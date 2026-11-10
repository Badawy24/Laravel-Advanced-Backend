<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OrderConcurrencyTest extends TestCase
{
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
    }

    public function test_concurrent_requests_attempting_to_reserve_final_unit(): void
    {
        // Setup initial inventory: quantity = 1, reserved_quantity = 0
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 100.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        // Create 10 distinct customers
        $customers = Customer::factory()->count(10)->create();
        $users = $customers->pluck('user');

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

        // Launch 10 simultaneous OS processes, each using its own database connection
        $poolResults = Process::pool(function (Pool $pool) use ($users, $branch, $product, $envVars): void {
            foreach ($users as $user) {
                $pool->path(base_path())
                    ->env($envVars)
                    ->command([
                        PHP_BINARY,
                        'artisan',
                        'order:concurrency-attempt',
                        (string) $user->id,
                        (string) $branch->id,
                        (string) $product->id,
                        '1',
                        '--env=testing',
                    ]);
            }
        })->start()->wait();

        $allOutputs = [];
        $statusCodes = [];
        foreach ($poolResults->collect() as $result) {
            $output = trim($result->output().' '.$result->errorOutput());
            $allOutputs[] = $output;
            $lines = array_filter(explode("\n", trim($result->output())));
            $lastLine = trim((string) end($lines));
            $statusCodes[] = $lastLine;
        }

        // Count 201s and 409s
        $createdCount = count(array_filter($statusCodes, fn ($code) => $code === '201'));
        $conflictCount = count(array_filter($statusCodes, fn ($code) => $code === '409'));

        $this->assertSame(1, $createdCount, 'Outputs: '.json_encode($allOutputs));
        $this->assertSame(9, $conflictCount, 'Outputs: '.json_encode($allOutputs));

        // Verify final database state
        $inventory->refresh();
        $this->assertSame(1, $inventory->quantity, 'Quantity must remain unchanged at 1.');
        $this->assertSame(1, $inventory->reserved_quantity, 'Reserved quantity must be exactly 1.');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('inventory_movements', 1);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status->value);

        $reservation = StockReservation::first();
        $this->assertNotNull($reservation);
        $this->assertSame(1, $reservation->quantity);
        $this->assertSame('active', $reservation->status->value);

        $movement = InventoryMovement::first();
        $this->assertNotNull($movement);
        $this->assertSame('reserve', $movement->reason->value);
        $this->assertSame(0, $movement->quantity_delta);
        $this->assertSame(1, $movement->reserved_delta);
        $this->assertSame(1, $movement->quantity_after);
        $this->assertSame(1, $movement->reserved_after);
    }
}
