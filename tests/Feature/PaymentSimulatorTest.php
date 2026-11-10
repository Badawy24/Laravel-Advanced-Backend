<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransitionException;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentSimulatorTest extends TestCase
{
    protected OrderService $orderService;

    protected PaymentService $paymentService;

    /** @var array<string, string> */
    protected array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanDatabase();

        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);

        $this->headers = [
            'X-APP-ACCESS-KEY' => (string) config('api.key'),
            'Accept' => 'application/json',
        ];
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

    /**
     * Create a sample pending order with reserved stock.
     *
     * @return array{order: Order, customer: Customer, branch: Branch, product: Product, inventory: BranchInventory}
     */
    protected function createPendingOrder(): array
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 125.50]);

        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'reserved_quantity' => 0,
        ]);

        $result = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ]
        );

        return [
            'order' => $result['order'],
            'customer' => $customer,
            'branch' => $branch,
            'product' => $product,
            'inventory' => $inventory->fresh(),
        ];
    }

    public function test_successful_payment_persists_attempt_and_event_and_confirms_order(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        Sanctum::actingAs($customer->user);

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'idempotency_key' => 'pay-success-key-001',
            'outcome' => 'succeeded',
            'event_id' => 'evt-success-001',
        ], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.amount', 251);

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        $payment = Payment::where('idempotency_key', 'pay-success-key-001')->firstOrFail();
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame('251.00', (string) $payment->amount);
        $this->assertSame('EGP', $payment->currency);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->failure_reason);
        $this->assertNotNull($payment->provider_reference);
        $this->assertNotNull($payment->applied_at);

        $event = PaymentEvent::where('event_id', 'evt-success-001')->firstOrFail();
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame('payment.succeeded', $event->type);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('order_confirmed', $event->payload['result']);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_failed_payment_persists_attempt_and_event_and_keeps_order_pending(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        Sanctum::actingAs($customer->user);

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'idempotency_key' => 'pay-fail-key-001',
            'outcome' => 'failed',
            'event_id' => 'evt-fail-001',
            'failure_reason' => 'Insufficient card funds',
        ], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::Failed->value)
            ->assertJsonPath('data.failure_reason', 'Insufficient card funds');

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);

        $payment = Payment::where('idempotency_key', 'pay-fail-key-001')->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Insufficient card funds', $payment->failure_reason);
        $this->assertNull($payment->applied_at);

        $event = PaymentEvent::where('event_id', 'evt-fail-001')->firstOrFail();
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame('payment.failed', $event->type);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('failed_recorded', $event->payload['result']);

        // Order remains Pending with only the initial creation history
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_duplicate_payment_request_with_same_idempotency_key_does_not_produce_duplicate_effects(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        Sanctum::actingAs($customer->user);

        $headers = array_merge($this->headers, [
            'Idempotency-Key' => 'pay-dup-key-100',
        ]);

        $response1 = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'outcome' => 'succeeded',
        ], $headers);

        $response1->assertCreated();
        $firstPaymentId = $response1->json('data.id');

        $response2 = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'outcome' => 'succeeded',
        ], $headers);

        $response2->assertOk()
            ->assertJsonPath('data.id', $firstPaymentId)
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_duplicate_callback_event_is_idempotent_and_never_updates_order_twice(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        Sanctum::actingAs($customer->user);

        $initiated = $this->paymentService->initiate($order, 'pay-callback-key-001');
        $payment = $initiated['payment'];

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->applied_at);

        // First callback event delivery
        $response1 = $this->postJson('/api/v1/payments/events', [
            'payment_id' => $payment->id,
            'event_id' => 'evt-webhook-dup-001',
            'outcome' => 'succeeded',
            'payload' => ['gateway_tx' => 'tx_999'],
        ], $this->headers);

        $response1->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value);

        // Duplicate delivery of the exact same callback event_id
        $response2 = $this->postJson('/api/v1/payments/events', [
            'payment_id' => $payment->id,
            'event_id' => 'evt-webhook-dup-001',
            'outcome' => 'succeeded',
            'payload' => ['gateway_tx' => 'tx_999'],
        ], $this->headers);

        $response2->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->applied_at);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    /**
     * @return array<string, array{0: OrderStatus}>
     */
    public static function invalidOrderStatesForPaymentProvider(): array
    {
        return [
            'Confirmed' => [OrderStatus::Confirmed],
            'Processing' => [OrderStatus::Processing],
            'Completed' => [OrderStatus::Completed],
            'Cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('invalidOrderStatesForPaymentProvider')]
    public function test_payment_is_prevented_for_invalid_order_states(OrderStatus $invalidStatus): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        $order->update(['status' => $invalidStatus]);

        Sanctum::actingAs($customer->user);

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'idempotency_key' => 'pay-invalid-state-'.$invalidStatus->value,
            'outcome' => 'succeeded',
        ], $this->headers);

        $response->assertConflict();

        $order->refresh();
        $this->assertSame($invalidStatus, $order->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_events', 0);
    }

    public function test_client_cannot_manipulate_payment_amount_and_server_order_total_is_always_used(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        // Order total is 2 * 125.50 = 251.00
        $this->assertSame('251.00', (string) $order->total_amount);

        Sanctum::actingAs($customer->user);

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'idempotency_key' => 'pay-tamper-amount-001',
            'amount' => 1.00,
            'currency' => 'USD',
            'outcome' => 'succeeded',
        ], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 251)
            ->assertJsonPath('data.currency', 'EGP');

        $payment = Payment::where('idempotency_key', 'pay-tamper-amount-001')->firstOrFail();
        $this->assertSame('251.00', (string) $payment->amount);
        $this->assertSame('EGP', $payment->currency);
    }

    public function test_crash_after_payment_succeeds_in_db_before_order_update_is_recovered_on_retry_without_double_payment(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        // Simulate a crash during order status transition after payment is committed
        $crashOnConfirm = true;
        OrderStatusHistory::creating(function (OrderStatusHistory $history) use (&$crashOnConfirm): void {
            if ($crashOnConfirm && $history->to_status === OrderStatus::Confirmed) {
                $crashOnConfirm = false;
                throw new RuntimeException('Simulated application crash after payment succeeded before order updated');
            }
        });

        try {
            $this->paymentService->process(
                order: $order,
                idempotencyKey: 'pay-crash-retry-001',
                succeed: true,
                eventId: 'evt-crash-retry-001',
                actor: $customer->user
            );
            $this->fail('Expected simulated crash exception to be thrown.');
        } catch (RuntimeException) {
            // Expected crash
        }

        // Verify database state after crash:
        // Payment succeeded in DB with applied_at = null, but order is still Pending!
        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::where('idempotency_key', 'pay-crash-retry-001')->firstOrFail();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->applied_at);

        $event = PaymentEvent::where('event_id', 'evt-crash-retry-001')->firstOrFail();
        $this->assertNull($event->processed_at);

        // Now retry the payment with the same idempotency_key
        $retryResult = $this->paymentService->process(
            order: $order,
            idempotencyKey: 'pay-crash-retry-001',
            succeed: true,
            eventId: 'evt-crash-retry-001',
            actor: $customer->user
        );

        $this->assertFalse($retryResult['is_new']);

        // Verify recovery: Order is now Confirmed, applied_at & processed_at are set, and only 1 payment exists!
        $order->refresh();
        $payment->refresh();
        $event->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->applied_at);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('order_confirmed', $event->payload['result']);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_reconciliation_job_and_command_complete_missing_order_update_exactly_once(): void
    {
        $data = $this->createPendingOrder();
        $order = $data['order'];
        $customer = $data['customer'];

        $crashOnConfirm = true;
        OrderStatusHistory::creating(function (OrderStatusHistory $history) use (&$crashOnConfirm): void {
            if ($crashOnConfirm && $history->to_status === OrderStatus::Confirmed) {
                $crashOnConfirm = false;
                throw new RuntimeException('Simulated crash before order update');
            }
        });

        try {
            $this->paymentService->process(
                order: $order,
                idempotencyKey: 'pay-crash-reconcile-001',
                succeed: true,
                eventId: 'evt-crash-reconcile-001',
                actor: $customer->user
            );
            $this->fail('Expected simulated crash exception.');
        } catch (RuntimeException) {
            // Expected crash
        }

        $payment = Payment::where('idempotency_key', 'pay-crash-reconcile-001')->firstOrFail();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->applied_at);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // Verify scheduled command discovers the unapplied succeeded payment and dispatches ReconcileSucceededPayment
        Queue::fake([ReconcileSucceededPayment::class]);

        $this->artisan('payments:reconcile')
            ->assertSuccessful();

        Queue::assertPushed(ReconcileSucceededPayment::class, function (ReconcileSucceededPayment $job) use ($payment): bool {
            return $job->paymentId === $payment->id;
        });

        // Execute the reconciliation job twice to verify idempotency
        $job1 = new ReconcileSucceededPayment($payment->id);
        $job1->handle($this->paymentService);

        $job2 = new ReconcileSucceededPayment($payment->id);
        $job2->handle($this->paymentService);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($payment->applied_at);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());

        // Attempting a new payment with a different idempotency key after order is confirmed is rejected
        try {
            $this->paymentService->process($order, 'pay-different-key-after-reconcile', true);
            $this->fail('Expected InvalidOrderTransitionException when paying for an already confirmed order.');
        } catch (InvalidOrderTransitionException) {
            // Expected
        }

        $this->assertDatabaseCount('payments', 1);
    }
}
