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
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductAndBranchAvailabilityApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->headers = [
            'X-APP-ACCESS-KEY' => (string) config('api.key'),
            'Accept' => 'application/json',
        ];
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $branch = Branch::factory()->create();

        $this->getJson('/api/v1/products', $this->headers)
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->getJson("/api/v1/branches/{$branch->id}/products", $this->headers)
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_product_listing_returns_active_products_with_category_information(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $category = Category::factory()->create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $product1 = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-001',
            'name' => 'Alpha Phone',
            'description' => 'Flagship smartphone',
            'price' => 450.50,
            'is_active' => true,
        ]);

        $product2 = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-002',
            'name' => 'Beta Phone',
            'description' => 'Midrange smartphone',
            'price' => 250.00,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products', $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $product1->id)
            ->assertJsonPath('data.0.sku', 'SKU-001')
            ->assertJsonPath('data.0.name', 'Alpha Phone')
            ->assertJsonPath('data.0.price', 450.5)
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.category.name', 'Smartphones')
            ->assertJsonPath('data.0.category.slug', 'smartphones')
            ->assertJsonPath('data.1.id', $product2->id)
            ->assertJsonPath('data.1.sku', 'SKU-002');
    }

    public function test_inactive_and_deleted_products_are_excluded_from_product_listing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $activeProduct = Product::factory()->create(['is_active' => true]);
        Product::factory()->inactive()->create();
        $deletedProduct = Product::factory()->create(['is_active' => true]);
        $deletedProduct->delete();

        $response = $this->getJson('/api/v1/products', $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeProduct->id);
    }

    public function test_product_listing_pagination_works_correctly(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $category = Category::factory()->create();
        Product::factory()->count(5)->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $responsePage1 = $this->getJson('/api/v1/products?page=1&per_page=2', $this->headers);

        $responsePage1->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5);

        $responsePage3 = $this->getJson('/api/v1/products?page=3&per_page=2', $this->headers);

        $responsePage3->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.current_page', 3)
            ->assertJsonPath('pagination.total', 5);
    }

    public function test_branch_specific_availability_and_reserved_quantity_deduction(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        $category = Category::factory()->create([
            'name' => 'Laptops',
            'slug' => 'laptops',
        ]);

        $productInBranchA = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'LAP-001',
            'name' => 'Pro Laptop',
            'price' => 1200.00,
            'is_active' => true,
        ]);

        $productOutOfStockInBranchA = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'LAP-002',
            'name' => 'Air Laptop',
            'price' => 900.00,
            'is_active' => true,
        ]);

        $productOnlyInBranchB = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'LAP-003',
            'name' => 'Gaming Laptop',
            'price' => 1500.00,
            'is_active' => true,
        ]);

        $inactiveProductInBranchA = Product::factory()->inactive()->create([
            'category_id' => $category->id,
            'sku' => 'LAP-004',
        ]);

        $deletedProductInBranchA = Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'LAP-005',
            'is_active' => true,
        ]);
        $deletedProductInBranchA->delete();

        BranchInventory::factory()->create([
            'branch_id' => $branchA->id,
            'product_id' => $productInBranchA->id,
            'quantity' => 25,
            'reserved_quantity' => 7,
        ]);

        BranchInventory::factory()->create([
            'branch_id' => $branchA->id,
            'product_id' => $productOutOfStockInBranchA->id,
            'quantity' => 10,
            'reserved_quantity' => 10,
        ]);

        BranchInventory::factory()->create([
            'branch_id' => $branchB->id,
            'product_id' => $productOnlyInBranchB->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        BranchInventory::factory()->create([
            'branch_id' => $branchA->id,
            'product_id' => $inactiveProductInBranchA->id,
            'quantity' => 30,
            'reserved_quantity' => 0,
        ]);

        BranchInventory::factory()->create([
            'branch_id' => $branchA->id,
            'product_id' => $deletedProductInBranchA->id,
            'quantity' => 15,
            'reserved_quantity' => 0,
        ]);

        $response = $this->getJson(
            "/api/v1/branches/{$branchA->id}/products?quantity=999&reserved_quantity=0&available_quantity=999",
            $this->headers
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $productInBranchA->id)
            ->assertJsonPath('data.0.sku', 'LAP-001')
            ->assertJsonPath('data.0.price', 1200)
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.branch_id', $branchA->id)
            ->assertJsonPath('data.0.quantity', 25)
            ->assertJsonPath('data.0.reserved_quantity', 7)
            ->assertJsonPath('data.0.available_quantity', 18)
            ->assertJsonPath('data.0.in_stock', true)
            ->assertJsonPath('data.1.id', $productOutOfStockInBranchA->id)
            ->assertJsonPath('data.1.quantity', 10)
            ->assertJsonPath('data.1.reserved_quantity', 10)
            ->assertJsonPath('data.1.available_quantity', 0)
            ->assertJsonPath('data.1.in_stock', false);
    }

    public function test_inactive_and_deleted_branches_are_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $inactiveBranch = Branch::factory()->inactive()->create();
        $deletedBranch = Branch::factory()->create(['is_active' => true]);
        $deletedBranch->delete();

        $this->getJson("/api/v1/branches/{$inactiveBranch->id}/products", $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error');

        $this->getJson("/api/v1/branches/{$deletedBranch->id}/products", $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error');
    }

    public function test_invalid_branch_ids_return_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/branches/999999/products', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/branches/invalid-branch/products', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/branches/0/products', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_branch_products_pagination_works_correctly(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $branch = Branch::factory()->create(['is_active' => true]);
        $category = Category::factory()->create();

        $products = Product::factory()->count(4)->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        foreach ($products as $product) {
            BranchInventory::factory()->create([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'reserved_quantity' => 2,
            ]);
        }

        $response = $this->getJson("/api/v1/branches/{$branch->id}/products?page=2&per_page=2", $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 4);
    }
}
