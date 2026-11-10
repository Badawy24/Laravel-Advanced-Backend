<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementReason;
use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\MissingActiveReservationException;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OrderService;
use Exception;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderLifecycleAndCancellationTest extends TestCase
{
    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->orderService = app(OrderService::class);
    }

    /**
     * Helper to create a pending order with active reservations via OrderService.
     *
     * @return array{order: Order, customer: Customer, branch: Branch, product1: Product, product2: Product, inv1: BranchInventory, inv2: BranchInventory}
     */
    protected function createSamplePendingOrder(): array
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product1 = Product::factory()->create(['price' => 50.00]);
        $product2 = Product::factory()->create(['price' => 30.00]);

        $inv1 = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product1->id,
            'quantity' => 20,
            'reserved_quantity' => 4,
        ]);

        $inv2 = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product2->id,
            'quantity' => 15,
            'reserved_quantity' => 2,
        ]);

        $result = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product1->id, 'quantity' => 3],
                ['product_id' => $product2->id, 'quantity' => 5],
            ]
        );

        return [
            'order' => $result['order'],
            'customer' => $customer,
            'branch' => $branch,
            'product1' => $product1,
            'product2' => $product2,
            'inv1' => $inv1->fresh(),
            'inv2' => $inv2->fresh(),
        ];
    }

    public function test_allowed_lifecycle_transitions_pending_to_confirmed_to_processing_to_completed(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $actor = User::factory()->create();

        $this->assertSame(OrderStatus::Pending, $order->status);

        // 1. Pending -> Confirmed
        $order = $this->orderService->confirm($order, $actor, 'Order confirmed by staff');
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        // 2. Confirmed -> Processing
        $order = $this->orderService->markProcessing($order, $actor, 'Order is being prepared');
        $this->assertSame(OrderStatus::Processing, $order->status);

        // 3. Processing -> Completed
        $order = $this->orderService->complete($order, $actor, 'Order handed over');
        $this->assertSame(OrderStatus::Completed, $order->status);

        // Verify all 4 status history records (creation + 3 transitions) are preserved in order
        $histories = OrderStatusHistory::where('order_id', $order->id)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(4, $histories);

        $this->assertNull($histories[0]->from_status);
        $this->assertSame(OrderStatus::Pending, $histories[0]->to_status);
        $this->assertSame($data['customer']->user_id, $histories[0]->changed_by);
        $this->assertNotNull($histories[0]->created_at);

        $this->assertSame(OrderStatus::Pending, $histories[1]->from_status);
        $this->assertSame(OrderStatus::Confirmed, $histories[1]->to_status);
        $this->assertSame($actor->id, $histories[1]->changed_by);
        $this->assertSame('Order confirmed by staff', $histories[1]->note);
        $this->assertNotNull($histories[1]->created_at);

        $this->assertSame(OrderStatus::Confirmed, $histories[2]->from_status);
        $this->assertSame(OrderStatus::Processing, $histories[2]->to_status);
        $this->assertSame($actor->id, $histories[2]->changed_by);
        $this->assertSame('Order is being prepared', $histories[2]->note);
        $this->assertNotNull($histories[2]->created_at);

        $this->assertSame(OrderStatus::Processing, $histories[3]->from_status);
        $this->assertSame(OrderStatus::Completed, $histories[3]->to_status);
        $this->assertSame($actor->id, $histories[3]->changed_by);
        $this->assertSame('Order handed over', $histories[3]->note);
        $this->assertNotNull($histories[3]->created_at);
    }

    /**
     * @return array<string, array{0: OrderStatus, 1: OrderStatus}>
     */
    public static function invalidTransitionsProvider(): array
    {
        return [
            'Pending -> Pending' => [OrderStatus::Pending, OrderStatus::Pending],
            'Pending -> Processing' => [OrderStatus::Pending, OrderStatus::Processing],
            'Pending -> Completed' => [OrderStatus::Pending, OrderStatus::Completed],
            'Confirmed -> Pending' => [OrderStatus::Confirmed, OrderStatus::Pending],
            'Confirmed -> Confirmed' => [OrderStatus::Confirmed, OrderStatus::Confirmed],
            'Confirmed -> Completed' => [OrderStatus::Confirmed, OrderStatus::Completed],
            'Processing -> Pending' => [OrderStatus::Processing, OrderStatus::Pending],
            'Processing -> Confirmed' => [OrderStatus::Processing, OrderStatus::Confirmed],
            'Processing -> Processing' => [OrderStatus::Processing, OrderStatus::Processing],
            'Completed -> Pending' => [OrderStatus::Completed, OrderStatus::Pending],
            'Completed -> Confirmed' => [OrderStatus::Completed, OrderStatus::Confirmed],
            'Completed -> Processing' => [OrderStatus::Completed, OrderStatus::Processing],
            'Completed -> Completed' => [OrderStatus::Completed, OrderStatus::Completed],
            'Completed -> Cancelled' => [OrderStatus::Completed, OrderStatus::Cancelled],
            'Cancelled -> Pending' => [OrderStatus::Cancelled, OrderStatus::Pending],
            'Cancelled -> Confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
            'Cancelled -> Processing' => [OrderStatus::Cancelled, OrderStatus::Processing],
            'Cancelled -> Completed' => [OrderStatus::Cancelled, OrderStatus::Completed],
            'Cancelled -> Cancelled' => [OrderStatus::Cancelled, OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('invalidTransitionsProvider')]
    public function test_invalid_transitions_are_rejected(OrderStatus $fromStatus, OrderStatus $toStatus): void
    {
        $order = Order::factory()->create(['status' => $fromStatus]);
        $initialHistoryCount = OrderStatusHistory::where('order_id', $order->id)->count();

        try {
            $this->orderService->transitionStatus($order, $toStatus);
            $this->fail("Expected InvalidOrderTransitionException for {$fromStatus->value} -> {$toStatus->value}");
        } catch (InvalidOrderTransitionException) {
            // Expected
        }

        $order->refresh();
        $this->assertSame($fromStatus, $order->status);
        $this->assertSame($initialHistoryCount, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_client_cannot_set_arbitrary_status_on_order_creation(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 40.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'status' => OrderStatus::Completed->value,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], [
            'X-APP-ACCESS-KEY' => (string) config('api.key'),
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $order = Order::firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    public function test_confirmation_is_rejected_when_order_has_no_reservations(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
        ]);

        try {
            $this->orderService->confirm($order);
            $this->fail('Expected MissingActiveReservationException when confirming order without reservations.');
        } catch (MissingActiveReservationException) {
            // Expected
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_confirmation_is_rejected_when_reservation_is_not_active(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];

        // Mark one reservation as released
        $reservation = StockReservation::whereIn('order_item_id', $order->items->pluck('id'))->firstOrFail();
        $reservation->update([
            'status' => StockReservationStatus::Released,
            'resolved_at' => now(),
        ]);

        try {
            $this->orderService->confirm($order);
            $this->fail('Expected MissingActiveReservationException when a reservation is not active.');
        } catch (MissingActiveReservationException) {
            // Expected
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('order_status_histories', 1); // Only initial creation history
    }

    public function test_cancellation_from_pending_releases_stock_and_records_movement_and_history(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        // Before cancel: inv1 quantity=20, reserved=7 (4+3); inv2 quantity=15, reserved=7 (2+5)
        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(7, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(7, $inv2->reserved_quantity);

        $cancelledOrder = $this->orderService->cancel($order, $user, 'Customer cancelled pending order');

        $this->assertSame(OrderStatus::Cancelled, $cancelledOrder->status);

        $inv1->refresh();
        $inv2->refresh();

        // Quantity remains unchanged; reserved_quantity decreased by reservation quantities (3 and 5)
        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        // Reservations set to released with resolved_at populated
        $reservations = StockReservation::whereIn('order_item_id', $order->items->pluck('id'))->get();
        $this->assertCount(2, $reservations);
        foreach ($reservations as $res) {
            $this->assertSame(StockReservationStatus::Released, $res->status);
            $this->assertNotNull($res->resolved_at);
        }

        // Verify inventory movements for release
        $this->assertDatabaseHas('inventory_movements', [
            'branch_inventory_id' => $inv1->id,
            'user_id' => $user->id,
            'reason' => InventoryMovementReason::Release->value,
            'quantity_delta' => 0,
            'reserved_delta' => -3,
            'quantity_after' => 20,
            'reserved_after' => 4,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'branch_inventory_id' => $inv2->id,
            'user_id' => $user->id,
            'reason' => InventoryMovementReason::Release->value,
            'quantity_delta' => 0,
            'reserved_delta' => -5,
            'quantity_after' => 15,
            'reserved_after' => 2,
        ]);

        // Verify status history appended
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Cancelled->value,
            'changed_by' => $user->id,
            'note' => 'Customer cancelled pending order',
        ]);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_cancellation_from_confirmed_releases_stock_and_records_history(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        $order = $this->orderService->confirm($order, $user, 'Confirmed');
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        $order = $this->orderService->cancel($order, $user, 'Cancelled after confirmation');
        $this->assertSame(OrderStatus::Cancelled, $order->status);

        $inv1->refresh();
        $inv2->refresh();

        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Cancelled->value,
            'changed_by' => $user->id,
            'note' => 'Cancelled after confirmation',
        ]);
        $this->assertSame(3, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_cancellation_from_processing_releases_stock_and_records_history(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        $order = $this->orderService->confirm($order, $user);
        $order = $this->orderService->markProcessing($order, $user);
        $this->assertSame(OrderStatus::Processing, $order->status);

        $order = $this->orderService->cancel($order, $user, 'Cancelled during processing');
        $this->assertSame(OrderStatus::Cancelled, $order->status);

        $inv1->refresh();
        $inv2->refresh();

        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Processing->value,
            'to_status' => OrderStatus::Cancelled->value,
            'changed_by' => $user->id,
            'note' => 'Cancelled during processing',
        ]);
        $this->assertSame(4, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_completed_order_cannot_be_cancelled(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        $this->orderService->confirm($order, $user);
        $this->orderService->markProcessing($order, $user);
        $this->orderService->complete($order, $user);

        try {
            $this->orderService->cancel($order, $user);
            $this->fail('Expected InvalidOrderTransitionException when cancelling a completed order.');
        } catch (InvalidOrderTransitionException) {
            // Expected
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);

        // No release movements created
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Release)->count());
        $this->assertSame(4, OrderStatusHistory::where('order_id', $order->id)->count());

        $inv1->refresh();
        $inv2->refresh();
        $this->assertSame(7, $inv1->reserved_quantity);
        $this->assertSame(7, $inv2->reserved_quantity);
    }

    public function test_repeated_cancellation_cannot_release_stock_twice(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        // First cancellation succeeds
        $this->orderService->cancel($order, $user, 'First cancel');

        $inv1->refresh();
        $inv2->refresh();
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        // Second cancellation is rejected
        try {
            $this->orderService->cancel($order, $user, 'Second cancel');
            $this->fail('Expected InvalidOrderTransitionException on repeated cancellation.');
        } catch (InvalidOrderTransitionException) {
            // Expected
        }

        // Stock was released only once
        $inv1->refresh();
        $inv2->refresh();
        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        $this->assertSame(2, InventoryMovement::where('reason', InventoryMovementReason::Release)->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_transaction_rollback_on_failure_during_cancellation_leaves_all_state_intact(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        // Simulate failure at the final step (creating status history)
        OrderStatusHistory::creating(function (OrderStatusHistory $history): void {
            if ($history->to_status === OrderStatus::Cancelled) {
                throw new Exception('Simulated database failure during cancellation history insert');
            }
        });

        try {
            $this->orderService->cancel($order, $user, 'Should rollback');
            $this->fail('Expected exception to be thrown.');
        } catch (Exception) {
            // Expected
        }

        // Order remains Pending
        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);

        // Inventories retain their reserved_quantity (7 and 7)
        $inv1->refresh();
        $inv2->refresh();
        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(7, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(7, $inv2->reserved_quantity);

        // Reservations remain Active with null resolved_at
        $reservations = StockReservation::whereIn('order_item_id', $order->items->pluck('id'))->get();
        foreach ($reservations as $res) {
            $this->assertSame(StockReservationStatus::Active, $res->status);
            $this->assertNull($res->resolved_at);
        }

        // No release movements or cancellation history records exist
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Release)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_concurrent_cancellation_requests_on_mysql_release_stock_exactly_once(): void
    {
        $data = $this->createSamplePendingOrder();
        $order = $data['order'];
        $inv1 = $data['inv1'];
        $inv2 = $data['inv2'];
        $user = $data['customer']->user;

        $dbConnection = (string) config('database.default');
        $envVars = [
            'DB_CONNECTION' => $dbConnection,
            'DB_DATABASE' => (string) config("database.connections.{$dbConnection}.database"),
            'DB_HOST' => (string) config("database.connections.{$dbConnection}.host"),
            'DB_PORT' => (string) config("database.connections.{$dbConnection}.port"),
            'DB_USERNAME' => (string) config("database.connections.{$dbConnection}.username"),
            'DB_PASSWORD' => (string) config("database.connections.{$dbConnection}.password"),
            'APP_ACCESS_KEY' => (string) config('api.key'),
            'RESERVATION_TTL_MINUTES' => (string) config('inventory.reservation_ttl_minutes', 15),
        ];

        // Launch 8 parallel OS processes attempting to cancel the same order simultaneously
        $poolResults = Process::pool(function (Pool $pool) use ($order, $user, $envVars): void {
            for ($i = 0; $i < 8; $i++) {
                $pool->path(base_path())
                    ->env($envVars)
                    ->command([
                        PHP_BINARY,
                        'artisan',
                        'order:concurrency-cancel',
                        (string) $order->id,
                        (string) $user->id,
                        '--env=testing',
                    ]);
            }
        })->start()->wait();

        $results = [];
        $allOutputs = [];
        foreach ($poolResults->collect() as $result) {
            $allOutputs[] = trim($result->output().' '.$result->errorOutput());
            $lines = array_filter(explode("\n", trim($result->output())));
            $results[] = trim((string) end($lines));
        }

        $cancelledCount = count(array_filter($results, fn (string $r): bool => $r === 'CANCELLED'));
        $rejectedCount = count(array_filter($results, fn (string $r): bool => $r === 'REJECTED'));

        $this->assertSame(1, $cancelledCount, 'Outputs: '.json_encode($allOutputs));
        $this->assertSame(7, $rejectedCount, 'Outputs: '.json_encode($allOutputs));

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);

        $inv1->refresh();
        $inv2->refresh();
        $this->assertSame(20, $inv1->quantity);
        $this->assertSame(4, $inv1->reserved_quantity);
        $this->assertSame(15, $inv2->quantity);
        $this->assertSame(2, $inv2->reserved_quantity);

        $this->assertSame(2, InventoryMovement::where('reason', InventoryMovementReason::Release)->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }
}
