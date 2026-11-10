<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementReason;
use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderManagementApiTest extends TestCase
{
    use DatabaseTransactions;

    protected OrderService $orderService;

    /** @var array<string, string> */
    protected array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = app(OrderService::class);

        $this->headers = [
            'X-APP-ACCESS-KEY' => (string) config('api.key'),
            'Accept' => 'application/json',
            'LANG' => 'en',
        ];
    }

    /**
     * @return array{order: Order, customer: Customer, user: User, branch: Branch, product: Product, inventory: BranchInventory}
     */
    protected function createPendingOrderViaApi(): array
    {
        $customer = Customer::factory()->create();
        $user = User::query()->findOrFail($customer->user_id);

        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['price' => 40.00]);

        $inventory = BranchInventory::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'reserved_quantity' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ], $this->headers);

        $response->assertCreated();

        /** @var Order $order */
        $order = Order::query()->findOrFail((int) $response->json('data.id'));
        $inventory->refresh();

        return [
            'order' => $order,
            'customer' => $customer,
            'user' => $user,
            'branch' => $branch,
            'product' => $product,
            'inventory' => $inventory,
        ];
    }

    public function test_customer_can_view_own_order_and_status_history(): void
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];
        $customer = $data['customer'];

        Sanctum::actingAs($data['user']);

        $response = $this->getJson('/api/v1/orders/'.$order->id, $this->headers);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('message', __('api.order_retrieved_successfully'));

        $histories = $response->json('data.status_histories');
        $this->assertIsArray($histories);
        $this->assertCount(1, $histories);
        $this->assertNull($histories[0]['from_status']);
        $this->assertSame(OrderStatus::Pending->value, $histories[0]['to_status']);
        $this->assertSame($customer->user_id, $histories[0]['changed_by']);

        $this->assertIsArray($response->json('data.items'));
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];

        $otherCustomer = Customer::factory()->create();
        Sanctum::actingAs(User::query()->findOrFail($otherCustomer->user_id));

        $response = $this->getJson('/api/v1/orders/'.$order->id, $this->headers);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.forbidden'));
    }

    public function test_show_returns_not_found_for_missing_order(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs(User::query()->findOrFail($customer->user_id));

        $response = $this->getJson('/api/v1/orders/999999', $this->headers);

        $response->assertNotFound()
            ->assertJsonPath('message', __('api.order_not_found'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unauthenticatedOrderEndpointsProvider(): array
    {
        return [
            'show' => ['GET', '/api/v1/orders/1'],
            'confirm' => ['POST', '/api/v1/orders/1/confirm'],
            'process' => ['POST', '/api/v1/orders/1/process'],
            'complete' => ['POST', '/api/v1/orders/1/complete'],
            'cancel' => ['POST', '/api/v1/orders/1/cancel'],
        ];
    }

    #[DataProvider('unauthenticatedOrderEndpointsProvider')]
    public function test_unauthenticated_order_management_requests_are_rejected(string $method, string $uri): void
    {
        $response = match ($method) {
            'GET' => $this->getJson($uri, $this->headers),
            'POST' => $this->postJson($uri, [], $this->headers),
            default => $this->fail('Unsupported HTTP method: '.$method),
        };

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.unauthorized'));
    }

    public function test_valid_lifecycle_transitions_work_through_the_api(): void
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];

        Sanctum::actingAs($data['user']);

        $confirm = $this->postJson('/api/v1/orders/'.$order->id.'/confirm', [
            'note' => 'Confirmed via API',
        ], $this->headers);

        $confirm->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Confirmed->value)
            ->assertJsonPath('message', __('api.order_confirmed_successfully'));

        $process = $this->postJson('/api/v1/orders/'.$order->id.'/process', [
            'note' => 'Processing via API',
        ], $this->headers);

        $process->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Processing->value)
            ->assertJsonPath('message', __('api.order_processing_successfully'));

        $complete = $this->postJson('/api/v1/orders/'.$order->id.'/complete', [
            'note' => 'Completed via API',
        ], $this->headers);

        $complete->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Completed->value)
            ->assertJsonPath('message', __('api.order_completed_successfully'));

        $histories = OrderStatusHistory::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(4, $histories);
        $this->assertSame(OrderStatus::Confirmed, $histories[1]->to_status);
        $this->assertSame('Confirmed via API', $histories[1]->note);
        $this->assertSame(OrderStatus::Processing, $histories[2]->to_status);
        $this->assertSame(OrderStatus::Completed, $histories[3]->to_status);
    }

    /**
     * @return array<string, array{0: callable(self): Order}>
     */
    public static function cancellableStatusProvider(): array
    {
        return [
            'confirmed' => [fn (self $test): Order => $test->advanceOrderTo(OrderStatus::Confirmed)],
            'processing' => [fn (self $test): Order => $test->advanceOrderTo(OrderStatus::Processing)],
        ];
    }

    protected function advanceOrderTo(OrderStatus $targetStatus): Order
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];
        $user = $data['user'];

        if ($targetStatus === OrderStatus::Confirmed || $targetStatus === OrderStatus::Processing) {
            $this->orderService->confirm($order, $user);
            $order->refresh();
        }

        if ($targetStatus === OrderStatus::Processing) {
            $this->orderService->markProcessing($order, $user);
            $order->refresh();
        }

        return $order;
    }

    #[DataProvider('cancellableStatusProvider')]
    public function test_valid_cancel_transitions_work_through_api(callable $prepareOrder): void
    {
        $order = $prepareOrder($this);
        $order->loadMissing('customer');
        $this->assertNotNull($order->customer);

        Sanctum::actingAs(User::query()->findOrFail($order->customer->user_id));

        $fromStatus = $order->status;

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
            'note' => 'Cancelled at '.$fromStatus->value,
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => $fromStatus->value,
            'to_status' => OrderStatus::Cancelled->value,
        ]);
    }

    /**
     * @return array<string, array{0: OrderStatus, 1: string}>
     */
    public static function invalidTransitionApiProvider(): array
    {
        return [
            'pending to processing via process' => [OrderStatus::Pending, '/process'],
            'pending to completed via complete' => [OrderStatus::Pending, '/complete'],
            'confirmed to confirmed via confirm' => [OrderStatus::Confirmed, '/confirm'],
            'confirmed to completed via complete' => [OrderStatus::Confirmed, '/complete'],
            'processing to confirmed via confirm' => [OrderStatus::Processing, '/confirm'],
            'completed to cancelled via cancel' => [OrderStatus::Completed, '/cancel'],
            'cancelled to confirmed via confirm' => [OrderStatus::Cancelled, '/confirm'],
        ];
    }

    #[DataProvider('invalidTransitionApiProvider')]
    public function test_invalid_status_transitions_are_rejected_via_api(
        OrderStatus $status,
        string $pathSuffix
    ): void {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];

        if ($status !== OrderStatus::Pending) {
            $order->update(['status' => $status]);
        }

        Sanctum::actingAs($data['user']);

        $uri = '/api/v1/orders/'.$order->id.$pathSuffix;
        $response = $this->postJson($uri, [], $this->headers);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.invalid_order_transition'));

        $order->refresh();
        $this->assertSame($status, $order->status);
    }

    public function test_cancellation_through_api_releases_stock_only_once(): void
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];
        $inventory = $data['inventory'];

        Sanctum::actingAs($data['user']);

        $this->assertSame(3, $inventory->reserved_quantity);

        $cancel = $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [
            'note' => 'Cancelled via API',
        ], $this->headers);

        $cancel->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('message', __('api.order_cancelled_successfully'));

        $inventory->refresh();
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(20, $inventory->quantity);

        $this->assertSame(
            1,
            InventoryMovement::where('branch_inventory_id', $inventory->id)
                ->where('reason', InventoryMovementReason::Release)
                ->count()
        );

        $reservation = StockReservation::whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id))->firstOrFail();
        $this->assertSame(StockReservationStatus::Released, $reservation->status);
        $this->assertNotNull($reservation->resolved_at);

        $secondCancel = $this->postJson('/api/v1/orders/'.$order->id.'/cancel', [], $this->headers);

        $secondCancel->assertStatus(409)
            ->assertJsonPath('message', __('api.invalid_order_transition'));

        $inventory->refresh();
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(
            1,
            InventoryMovement::where('branch_inventory_id', $inventory->id)
                ->where('reason', InventoryMovementReason::Release)
                ->count()
        );
    }

    public function test_confirm_without_valid_active_reservations_is_rejected_via_api(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
        ]);

        Sanctum::actingAs(User::query()->findOrFail($customer->user_id));

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/confirm', [], $this->headers);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('api.missing_active_reservations'));

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_confirm_is_rejected_when_reservation_is_not_active_via_api(): void
    {
        $data = $this->createPendingOrderViaApi();
        $order = $data['order'];

        $reservation = StockReservation::whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id))->firstOrFail();
        $reservation->update([
            'status' => StockReservationStatus::Released,
            'resolved_at' => now(),
        ]);

        Sanctum::actingAs($data['user']);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/confirm', [], $this->headers);

        $response->assertStatus(409)
            ->assertJsonPath('message', __('api.missing_active_reservations'));

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
    }
}
