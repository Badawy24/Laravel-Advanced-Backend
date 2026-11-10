<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementReason;
use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Jobs\ExpireStockReservation;
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
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockReservationExpirationTest extends TestCase
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
     * Create a sample pending order with an active reservation.
     *
     * @return array{order: Order, reservation: StockReservation, inventory: BranchInventory, customer: Customer}
     */
    protected function createPendingOrderWithReservation(): array
    {
        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);

        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'reserved_quantity' => 4,
        ]);

        $result = $this->orderService->create(
            user: $customer->user,
            branchId: $branch->id,
            items: [
                ['product_id' => $product->id, 'quantity' => 3],
            ]
        );

        /** @var Order $order */
        $order = $result['order'];
        /** @var OrderItem $orderItem */
        $orderItem = $order->items->firstOrFail();
        /** @var StockReservation $reservation */
        $reservation = StockReservation::where('order_item_id', $orderItem->id)->firstOrFail();

        return [
            'order' => $order,
            'reservation' => $reservation,
            'inventory' => $inventory->fresh(),
            'customer' => $customer,
        ];
    }

    public function test_order_creation_dispatches_expiration_job_only_after_transaction_commits(): void
    {
        Queue::fake([ExpireStockReservation::class]);

        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 40.00]);
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
                ['product_id' => $product->id, 'quantity' => 2],
            ]
        );

        $reservation = StockReservation::where('order_item_id', $result['order']->items->first()->id)->firstOrFail();

        Queue::assertPushed(ExpireStockReservation::class, function (ExpireStockReservation $job) use ($reservation): bool {
            return $job->reservationId === $reservation->id && $job->afterCommit === true;
        });
    }

    public function test_order_creation_rollback_does_not_dispatch_expiration_job(): void
    {
        Queue::fake([ExpireStockReservation::class]);

        $customer = Customer::factory()->create();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 40.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        OrderStatusHistory::creating(function (): void {
            throw new Exception('Simulated failure before transaction commit');
        });

        try {
            $this->orderService->create(
                user: $customer->user,
                branchId: $branch->id,
                items: [
                    ['product_id' => $product->id, 'quantity' => 2],
                ]
            );
            $this->fail('Expected exception to be thrown.');
        } catch (Exception) {
            // Expected
        }

        Queue::assertNothingPushed();
    }

    public function test_expired_active_reservation_gets_released_and_records_movement_accurately(): void
    {
        $data = $this->createPendingOrderWithReservation();
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        // Before expiration: quantity = 20, reserved_quantity = 7 (4 initial + 3 reserved)
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(7, $inventory->reserved_quantity);

        $reservation->update(['expires_at' => now()->subMinute()]);

        $job = new ExpireStockReservation($reservation->id);
        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Expired, $reservation->status);
        $this->assertNotNull($reservation->resolved_at);

        // Quantity never changes; reserved_quantity decreased by 3
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(4, $inventory->reserved_quantity);

        // Inventory movement recorded with reason = expire
        $this->assertDatabaseHas('inventory_movements', [
            'branch_inventory_id' => $inventory->id,
            'reason' => InventoryMovementReason::Expire->value,
            'quantity_delta' => 0,
            'reserved_delta' => -3,
            'quantity_after' => 20,
            'reserved_after' => 4,
            'reference_type' => $reservation->getMorphClass(),
            'reference_id' => $reservation->id,
        ]);
    }

    public function test_future_reservation_remains_active(): void
    {
        $data = $this->createPendingOrderWithReservation();
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        $reservation->update(['expires_at' => now()->addMinutes(10)]);

        $job = new ExpireStockReservation($reservation->id);
        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Active, $reservation->status);
        $this->assertNull($reservation->resolved_at);
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(7, $inventory->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Expire)->count());
    }

    /**
     * @return array<string, array{0: OrderStatus}>
     */
    public static function nonPendingOrderStatusesProvider(): array
    {
        return [
            'Confirmed' => [OrderStatus::Confirmed],
            'Processing' => [OrderStatus::Processing],
            'Completed' => [OrderStatus::Completed],
            'Cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('nonPendingOrderStatusesProvider')]
    public function test_non_pending_orders_are_ignored(OrderStatus $orderStatus): void
    {
        $data = $this->createPendingOrderWithReservation();
        $order = $data['order'];
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        $order->update(['status' => $orderStatus]);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $job = new ExpireStockReservation($reservation->id);
        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Active, $reservation->status);
        $this->assertNull($reservation->resolved_at);
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(7, $inventory->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Expire)->count());
    }

    /**
     * @return array<string, array{0: StockReservationStatus}>
     */
    public static function nonActiveReservationStatusesProvider(): array
    {
        return [
            'Released' => [StockReservationStatus::Released],
            'Expired' => [StockReservationStatus::Expired],
            'Consumed' => [StockReservationStatus::Consumed],
        ];
    }

    #[DataProvider('nonActiveReservationStatusesProvider')]
    public function test_already_released_expired_or_consumed_reservations_are_ignored(StockReservationStatus $status): void
    {
        $data = $this->createPendingOrderWithReservation();
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        $resolvedAt = now()->subMinutes(5);
        $reservation->update([
            'status' => $status,
            'expires_at' => now()->subMinutes(10),
            'resolved_at' => $resolvedAt,
        ]);

        $job = new ExpireStockReservation($reservation->id);
        $job->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame($status, $reservation->status);
        $this->assertSame($resolvedAt->toDateTimeString(), $reservation->resolved_at->toDateTimeString());
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(7, $inventory->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Expire)->count());
    }

    public function test_running_the_same_job_twice_does_not_release_stock_twice(): void
    {
        $data = $this->createPendingOrderWithReservation();
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        $reservation->update(['expires_at' => now()->subMinute()]);

        $job1 = new ExpireStockReservation($reservation->id);
        $job1->handle($this->orderService);

        $job2 = new ExpireStockReservation($reservation->id);
        $job2->handle($this->orderService);

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Expired, $reservation->status);
        $this->assertNotNull($reservation->resolved_at);
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(4, $inventory->reserved_quantity);
        $this->assertSame(1, InventoryMovement::where('reason', InventoryMovementReason::Expire)->count());
    }

    public function test_transaction_rollback_leaves_reservation_and_stock_intact(): void
    {
        $data = $this->createPendingOrderWithReservation();
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];

        $reservation->update(['expires_at' => now()->subMinute()]);

        InventoryMovement::creating(function (InventoryMovement $movement): void {
            if ($movement->reason === InventoryMovementReason::Expire) {
                throw new Exception('Simulated database error during expire movement insert');
            }
        });

        try {
            $job = new ExpireStockReservation($reservation->id);
            $job->handle($this->orderService);
            $this->fail('Expected exception to be thrown.');
        } catch (Exception) {
            // Expected
        }

        $reservation->refresh();
        $inventory->refresh();

        $this->assertSame(StockReservationStatus::Active, $reservation->status);
        $this->assertNull($reservation->resolved_at);
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(7, $inventory->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovementReason::Expire)->count());
    }

    public function test_scheduled_command_discovers_and_dispatches_expired_active_reservations(): void
    {
        Queue::fake([ExpireStockReservation::class]);

        $data = $this->createPendingOrderWithReservation();
        $expiredReservation = $data['reservation'];
        $expiredReservation->update(['expires_at' => now()->subMinute()]);

        $this->artisan('reservations:expire')
            ->assertSuccessful();

        Queue::assertPushed(ExpireStockReservation::class, function (ExpireStockReservation $job) use ($expiredReservation): bool {
            return $job->reservationId === $expiredReservation->id;
        });
    }

    public function test_concurrent_cancellation_and_expiration_on_mysql_do_not_release_stock_twice(): void
    {
        $this->assertSame('mysql', config('database.default'), 'Concurrency verification requires MySQL.');

        $data = $this->createPendingOrderWithReservation();
        $order = $data['order'];
        $reservation = $data['reservation'];
        $inventory = $data['inventory'];
        $user = $data['customer']->user;

        // Make reservation eligible for expiration right now
        $reservation->update(['expires_at' => now()->subMinute()]);

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

        // Launch 4 concurrent cancellation processes and 4 concurrent expiration processes
        $poolResults = Process::pool(function (Pool $pool) use ($order, $reservation, $user, $envVars): void {
            for ($i = 0; $i < 4; $i++) {
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

                $pool->path(base_path())
                    ->env($envVars)
                    ->command([
                        PHP_BINARY,
                        'artisan',
                        'order:concurrency-expire',
                        (string) $reservation->id,
                        '--env=testing',
                    ]);
            }
        })->start()->wait();

        foreach ($poolResults->collect() as $result) {
            $this->assertStringNotContainsString('ERROR:', $result->output());
        }

        $inventory->refresh();
        $reservation->refresh();
        $order->refresh();

        // Stock quantity unchanged at 20, reserved_quantity decreased from 7 to 4 (released exactly once!)
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(4, $inventory->reserved_quantity);

        // Reservation was resolved once (either Released or Expired depending on which won the lock first)
        $this->assertContains($reservation->status, [
            StockReservationStatus::Released,
            StockReservationStatus::Expired,
        ]);
        $this->assertNotNull($reservation->resolved_at);

        // Total release + expire movements for this reservation must be 1
        $releaseOrExpireCount = InventoryMovement::where('reference_type', $reservation->getMorphClass())
            ->where('reference_id', $reservation->id)
            ->whereIn('reason', [InventoryMovementReason::Release, InventoryMovementReason::Expire])
            ->count();

        $this->assertSame(1, $releaseOrExpireCount);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }
}

