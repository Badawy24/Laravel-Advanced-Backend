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
use Exception;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    protected array $headers = [];

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

        $this->headers = [
            'X-APP-ACCESS-KEY' => (string) config('api.key'),
            'Accept' => 'application/json',
        ];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $this->headers);

        $response->assertStatus(401);
    }

    public function test_user_without_customer_profile_returns_403(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $this->headers);

        $response->assertStatus(403);
    }

    public function test_customer_id_in_payload_is_ignored_and_order_uses_authenticated_customer(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        Sanctum::actingAs($customerA->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'customer_id' => $customerB->id,
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $this->headers);

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame($customerA->id, $order->customer_id);
        $this->assertNotSame($customerB->id, $order->customer_id);
    }

    public function test_successful_order_creation_with_single_product(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 75.50]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'reserved_quantity' => 5,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ], $this->headers);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_amount', 226.50)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'pending',
            'total_amount' => 226.50,
        ]);

        $inventory->refresh();
        $this->assertSame(20, $inventory->quantity);
        $this->assertSame(8, $inventory->reserved_quantity);

        $this->assertDatabaseHas('stock_reservations', [
            'branch_inventory_id' => $inventory->id,
            'quantity' => 3,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'branch_inventory_id' => $inventory->id,
            'user_id' => $customer->user_id,
            'reason' => 'reserve',
            'quantity_delta' => 0,
            'reserved_delta' => 3,
            'quantity_after' => 20,
            'reserved_after' => 8,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'from_status' => null,
            'to_status' => 'pending',
            'changed_by' => $customer->user_id,
        ]);
    }

    public function test_successful_order_creation_with_multiple_products(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product1 = Product::factory()->create(['price' => 10.25]);
        $product2 = Product::factory()->create(['price' => 20.50]);

        $inv1 = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product1->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $inv2 = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product2->id,
            'quantity' => 15,
            'reserved_quantity' => 2,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2],
                ['product_id' => $product2->id, 'quantity' => 3],
            ],
        ], $this->headers);

        $response->assertStatus(201);

        // 2 * 10.25 = 20.50; 3 * 20.50 = 61.50; Total = 82.00
        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'total_amount' => 82.00,
        ]);

        $inv1->refresh();
        $inv2->refresh();

        $this->assertSame(2, $inv1->reserved_quantity);
        $this->assertSame(5, $inv2->reserved_quantity);
        $this->assertSame(10, $inv1->quantity);
        $this->assertSame(15, $inv2->quantity);

        $this->assertCount(2, OrderItem::all());
        $this->assertCount(2, StockReservation::all());
        $this->assertCount(2, InventoryMovement::all());
    }

    public function test_server_side_price_and_total_calculation_ignores_client_tampering(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 100.00]);
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'total_amount' => 1.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 0.50,
                    'line_total' => 1.00,
                ],
            ],
        ], $this->headers);

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertSame('200.00', (string) $order->total_amount);

        $orderItem = OrderItem::first();
        $this->assertSame('100.00', (string) $orderItem->unit_price);
        $this->assertSame('200.00', (string) $orderItem->line_total);
    }

    public function test_rejection_when_stock_is_insufficient_returns_409(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'reserved_quantity' => 4, // only 1 available
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $this->headers);

        $response->assertStatus(409);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_reservations', 0);

        $inventory->refresh();
        $this->assertSame(4, $inventory->reserved_quantity);
    }

    public function test_rejection_when_product_is_inactive_returns_422(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->inactive()->create();
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $this->headers);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_rejection_when_branch_is_inactive_returns_422(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->inactive()->create();
        $product = Product::factory()->create();
        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $this->headers);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_rejection_when_branch_inventory_is_missing_returns_422(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create();

        // No branch_inventory created for this product in this branch

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $this->headers);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_product_entries_in_payload_rejected_with_422(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $this->headers);

        $response->assertStatus(422);
    }

    public function test_idempotency_key_returns_existing_order_with_200(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 40.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $headers = array_merge($this->headers, [
            'Idempotency-Key' => 'unique-test-key-12345',
        ]);

        $response1 = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $headers);

        $response1->assertStatus(201);
        $orderId = $response1->json('data.id');

        $inventory->refresh();
        $this->assertSame(2, $inventory->reserved_quantity);

        // Second request with same idempotency key
        $response2 = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ], $headers);

        $response2->assertStatus(200);
        $this->assertSame($orderId, $response2->json('data.id'));

        $this->assertDatabaseCount('orders', 1);
        $inventory->refresh();
        $this->assertSame(2, $inventory->reserved_quantity); // not reserved twice
    }

    public function test_full_rollback_when_exception_occurs_at_last_step(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);
        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        // Intercept OrderStatusHistory::creating to simulate a failure at the last step
        OrderStatusHistory::creating(function () {
            throw new Exception('Simulated crash at final step');
        });

        try {
            $this->postJson('/api/v1/orders', [
                'branch_id' => $branch->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
            ], $this->headers);
        } catch (Exception) {
            // caught
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('order_status_histories', 0);

        $inventory->refresh();
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(10, $inventory->quantity);
    }

    public function test_decimal_precision_for_unit_prices_line_totals_and_order_total(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product1 = Product::factory()->create(['price' => '0.10']);
        $product2 = Product::factory()->create(['price' => '0.20']);
        $product3 = Product::factory()->create(['price' => '19.99']);
        $product4 = Product::factory()->create(['price' => '33.33']);

        foreach ([$product1, $product2, $product3, $product4] as $product) {
            BranchInventory::factory()->create([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => 50,
                'reserved_quantity' => 0,
            ]);
        }

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 1], // 0.10
                ['product_id' => $product2->id, 'quantity' => 1], // 0.20
                ['product_id' => $product3->id, 'quantity' => 3], // 59.97
                ['product_id' => $product4->id, 'quantity' => 3], // 99.99
            ],
        ], $this->headers);

        $response->assertStatus(201);

        /** @var Order $order */
        $order = Order::with('items')->firstOrFail();
        $this->assertSame('160.26', $order->total_amount);

        $itemsByProduct = $order->items->keyBy('product_id');

        $this->assertSame('0.10', $itemsByProduct->get($product1->id)->unit_price);
        $this->assertSame('0.10', $itemsByProduct->get($product1->id)->line_total);

        $this->assertSame('0.20', $itemsByProduct->get($product2->id)->unit_price);
        $this->assertSame('0.20', $itemsByProduct->get($product2->id)->line_total);

        $this->assertSame('19.99', $itemsByProduct->get($product3->id)->unit_price);
        $this->assertSame('59.97', $itemsByProduct->get($product3->id)->line_total);

        $this->assertSame('33.33', $itemsByProduct->get($product4->id)->unit_price);
        $this->assertSame('99.99', $itemsByProduct->get($product4->id)->line_total);
    }

    public function test_high_value_decimal_precision_without_float_drift(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer->user);

        $branch = Branch::factory()->create();
        $product1 = Product::factory()->create(['price' => '9999999.99']);
        $product2 = Product::factory()->create(['price' => '0.03']);

        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product1->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product2->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2], // 19999999.98
                ['product_id' => $product2->id, 'quantity' => 1], // 0.03
            ],
        ], $this->headers);

        $response->assertStatus(201);

        /** @var Order $order */
        $order = Order::with('items')->firstOrFail();
        $this->assertSame('20000000.01', $order->total_amount);

        $itemsByProduct = $order->items->keyBy('product_id');

        $this->assertSame('9999999.99', $itemsByProduct->get($product1->id)->unit_price);
        $this->assertSame('19999999.98', $itemsByProduct->get($product1->id)->line_total);

        $this->assertSame('0.03', $itemsByProduct->get($product2->id)->unit_price);
        $this->assertSame('0.03', $itemsByProduct->get($product2->id)->line_total);
    }
}
