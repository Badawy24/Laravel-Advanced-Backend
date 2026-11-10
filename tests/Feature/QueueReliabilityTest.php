<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockReservationStatus;
use App\Jobs\ExpireStockReservation;
use App\Jobs\ReconcileSucceededPayment;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class QueueReliabilityTest extends TestCase
{
    protected OrderService $orderService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanDatabase();

        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();

        parent::tearDown();
    }

    protected function cleanDatabase(): void
    {
        PaymentEvent::query()->delete();
        Payment::query()->delete();
        InventoryMovement::query()->delete();
        OrderStatusHistory::query()->delete();
        StockReservation::query()->delete();
        OrderItem::query()->delete();
        Order::query()->delete();
        BranchInventory::query()->delete();
        Product::withTrashed()->forceDelete();
        Category::query()->delete();
        Branch::withTrashed()->forceDelete();
        Customer::query()->delete();
        DB::table('personal_access_tokens')->delete();
        User::query()->delete();
    }

    public function test_queued_jobs_implement_should_queue_and_use_queueable_after_commit(): void
    {
        $expireJob = new ExpireStockReservation(1);
        $reconcileJob = new ReconcileSucceededPayment(1);

        $this->assertInstanceOf(ShouldQueue::class, $expireJob);
        $this->assertInstanceOf(ShouldQueue::class, $reconcileJob);

        $uses = class_uses($expireJob);
        $this->assertContains(Queueable::class, $uses);
        $this->assertTrue($expireJob->afterCommit);
        $this->assertTrue($reconcileJob->afterCommit);
    }

    public function test_reconcile_job_retries_after_transient_failure_without_duplicate_order_confirmation(): void
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 80.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $result = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 1],
            ]
        );

        /** @var Order $order */
        $order = $result['order'];

        $payment = Payment::create([
            'order_id' => $order->id,
            'idempotency_key' => 'queue-reconcile-retry-key',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'status' => PaymentStatus::Succeeded,
            'failure_reason' => null,
            'provider_reference' => 'sim_pay_retry',
            'applied_at' => null,
        ]);

        $failOnce = true;
        OrderStatusHistory::creating(function (OrderStatusHistory $history) use (&$failOnce): void {
            if ($failOnce && $history->to_status === OrderStatus::Confirmed) {
                $failOnce = false;
                throw new RuntimeException('Transient failure during reconcile job');
            }
        });

        $job = new ReconcileSucceededPayment($payment->id);

        try {
            $job->handle($this->paymentService);
            $this->fail('Expected transient failure on first job attempt.');
        } catch (RuntimeException) {
            // Expected
        }

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($payment->applied_at);

        $job->handle($this->paymentService);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($payment->applied_at);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_expire_job_retries_after_transient_failure_without_double_stock_release(): void
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 45.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 12,
            'reserved_quantity' => 0,
        ]);

        $result = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ]
        );

        $reservation = StockReservation::where(
            'order_item_id',
            $result['order']->items->firstOrFail()->id
        )->firstOrFail();

        $reservation->update(['expires_at' => now()->subMinute()]);
        $inventory->refresh();

        $failOnce = true;
        InventoryMovement::creating(function (InventoryMovement $movement) use (&$failOnce): void {
            if ($failOnce && $movement->reason === InventoryMovementReason::Expire) {
                $failOnce = false;
                throw new RuntimeException('Transient failure during expire job');
            }
        });

        $job = new ExpireStockReservation($reservation->id);

        try {
            $job->handle($this->orderService);
            $this->fail('Expected transient failure on first job attempt.');
        } catch (RuntimeException) {
            // Expected
        }

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Active, $reservation->status);
        $this->assertSame(2, $inventory->reserved_quantity);

        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Expired, $reservation->status);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(1, InventoryMovement::where('reference_id', $reservation->id)
            ->where('reason', InventoryMovementReason::Expire->value)
            ->count());
    }

    public function test_duplicate_reconcile_job_executions_apply_business_effects_once(): void
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 60.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $order = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 1],
            ]
        )['order'];

        $payment = Payment::create([
            'order_id' => $order->id,
            'idempotency_key' => 'queue-dup-reconcile',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'status' => PaymentStatus::Succeeded,
            'failure_reason' => null,
            'provider_reference' => 'sim_pay_test',
            'applied_at' => null,
        ]);

        $job = new ReconcileSucceededPayment($payment->id);
        $job->handle($this->paymentService);
        $job->handle($this->paymentService);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($payment->applied_at);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_duplicate_expire_job_executions_do_not_release_stock_twice(): void
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 30.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 8,
            'reserved_quantity' => 0,
        ]);

        $order = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 3],
            ]
        )['order'];

        $reservation = StockReservation::where(
            'order_item_id',
            $order->items->firstOrFail()->id
        )->firstOrFail();

        $reservation->update(['expires_at' => now()->subMinute()]);

        $job = new ExpireStockReservation($reservation->id);
        $job->handle($this->orderService);
        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory = BranchInventory::where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(StockReservationStatus::Expired, $reservation->status);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(1, InventoryMovement::where('reference_id', $reservation->id)
            ->where('reason', InventoryMovementReason::Expire->value)
            ->count());
    }

    public function test_reconcile_job_with_missing_payment_completes_without_error(): void
    {
        $job = new ReconcileSucceededPayment(999_999);

        $job->handle($this->paymentService);

        $this->assertSame(0, Payment::count());
    }

    public function test_expire_job_with_missing_reservation_completes_without_error(): void
    {
        $job = new ExpireStockReservation(999_999);

        $job->handle($this->orderService);

        $this->assertSame(0, StockReservation::count());
    }
}
