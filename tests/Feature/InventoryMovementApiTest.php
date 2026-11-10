<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementReason;
use App\Enums\UserRole;
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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryMovementApiTest extends TestCase
{
    /** @var array<string, string> */
    protected array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanDatabase();

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

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/inventory-movements', $this->headers);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.unauthorized'));
    }

    public function test_non_admin_customer_user_is_forbidden(): void
    {
        $customerUser = User::factory()->create([
            'role' => UserRole::Customer,
        ]);
        Customer::factory()->create(['user_id' => $customerUser->id]);

        Sanctum::actingAs($customerUser);

        $response = $this->getJson('/api/v1/inventory-movements', $this->headers);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.forbidden'));
    }

    public function test_empty_results_returns_empty_paginated_response(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/inventory-movements', $this->headers);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.current_page', 1);

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_authorized_admin_can_retrieve_inventory_movements_with_correct_data_and_relationships(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $branch = Branch::factory()->create(['name' => 'Main Branch', 'code' => 'MAIN-01']);
        $product = Product::factory()->create(['name' => 'Laptop Pro', 'sku' => 'LAP-100', 'price' => 1500.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 50,
            'reserved_quantity' => 5,
        ]);

        $movement = InventoryMovement::factory()->create([
            'branch_inventory_id' => $inventory->id,
            'user_id' => $admin->id,
            'reason' => InventoryMovementReason::Reserve,
            'quantity_delta' => 0,
            'reserved_delta' => 5,
            'quantity_after' => 50,
            'reserved_after' => 5,
            'reference_type' => 'App\Models\StockReservation',
            'reference_id' => 123,
            'note' => 'Order reservation note',
        ]);

        $response = $this->getJson('/api/v1/inventory-movements', $this->headers);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $movement->id)
            ->assertJsonPath('data.0.branch.id', $branch->id)
            ->assertJsonPath('data.0.branch.name', 'Main Branch')
            ->assertJsonPath('data.0.branch.code', 'MAIN-01')
            ->assertJsonPath('data.0.product.id', $product->id)
            ->assertJsonPath('data.0.product.name', 'Laptop Pro')
            ->assertJsonPath('data.0.product.sku', 'LAP-100')
            ->assertJsonPath('data.0.product.price', 1500)
            ->assertJsonPath('data.0.quantity_delta', 0)
            ->assertJsonPath('data.0.reserved_delta', 5)
            ->assertJsonPath('data.0.reason', 'reserve')
            ->assertJsonPath('data.0.reference_type', 'App\Models\StockReservation')
            ->assertJsonPath('data.0.reference_id', 123)
            ->assertJsonPath('data.0.quantity_after', 50)
            ->assertJsonPath('data.0.reserved_after', 5)
            ->assertJsonPath('data.0.note', 'Order reservation note');

        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movement->id,
            'branch_inventory_id' => $inventory->id,
            'reason' => 'reserve',
            'reserved_delta' => 5,
        ]);
    }

    public function test_inventory_movements_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            InventoryMovement::create([
                'branch_inventory_id' => $inventory->id,
                'user_id' => $admin->id,
                'reason' => InventoryMovementReason::Adjustment,
                'quantity_delta' => $i,
                'reserved_delta' => 0,
                'quantity_after' => 100 + $i,
                'reserved_after' => 0,
                'reference_type' => 'manual',
                'reference_id' => $i,
                'note' => "Movement {$i}",
            ]);
        }

        $this->assertDatabaseCount('inventory_movements', 5);

        // Fetch page 1 with per_page = 2
        $response1 = $this->getJson('/api/v1/inventory-movements?page=1&per_page=2', $this->headers);

        $response1->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonCount(2, 'data');

        // Fetch page 3 with per_page = 2 (remaining 1 item)
        $response3 = $this->getJson('/api/v1/inventory-movements?page=3&per_page=2', $this->headers);

        $response3->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.current_page', 3)
            ->assertJsonCount(1, 'data');
    }
}
