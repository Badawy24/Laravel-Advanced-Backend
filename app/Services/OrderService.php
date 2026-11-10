<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryMovementReason;
use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Exceptions\BranchInventoryNotFoundException;
use App\Exceptions\CustomerProfileNotFoundException;
use App\Exceptions\InactiveBranchException;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Create an order and reserve its stock atomically.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return array{order: Order, is_new: bool}
     *
     * @throws CustomerProfileNotFoundException
     * @throws InactiveBranchException
     * @throws InactiveProductException
     * @throws BranchInventoryNotFoundException
     * @throws InsufficientStockException
     */
    public function create(User $user, int $branchId, array $items, ?string $idempotencyKey = null): array
    {
        $customer = $user->customer;
        if ($customer === null) {
            throw new CustomerProfileNotFoundException;
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existingOrder = Order::where('customer_id', $customer->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('items')
                ->first();

            if ($existingOrder !== null) {
                return ['order' => $existingOrder, 'is_new' => false];
            }
        }

        $branch = Branch::find($branchId);
        if ($branch === null || ! $branch->is_active) {
            throw new InactiveBranchException;
        }

        $productIds = array_column($items, 'product_id');
        sort($productIds);

        try {
            return DB::transaction(function () use ($user, $customer, $branch, $items, $productIds, $idempotencyKey): array {
                /** @var Collection<int, BranchInventory> $inventories */
                $inventories = BranchInventory::where('branch_id', $branch->id)
                    ->whereIn('product_id', $productIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');

                foreach ($productIds as $productId) {
                    if (! $inventories->has($productId)) {
                        throw new BranchInventoryNotFoundException;
                    }
                }

                $products = Product::whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                foreach ($productIds as $productId) {
                    $product = $products->get($productId);
                    if ($product === null || ! $product->is_active) {
                        throw new InactiveProductException;
                    }
                }

                // Verify sufficient available stock for all products before reserving
                foreach ($items as $item) {
                    $inventory = $inventories->get($item['product_id']);
                    $available = $inventory->quantity - $inventory->reserved_quantity;

                    if ($item['quantity'] > $available) {
                        throw new InsufficientStockException;
                    }
                }

                // Increase reserved_quantity only after all requested stock has been verified
                foreach ($items as $item) {
                    $inventory = $inventories->get($item['product_id']);
                    $inventory->reserved_quantity += $item['quantity'];
                    $inventory->save();
                }

                // Calculate monetary values with bcmath
                $totalAmount = '0.00';
                $preparedItems = [];

                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    $unitPrice = number_format((float) $product->price, 2, '.', '');
                    $lineTotal = bcmul($unitPrice, (string) $item['quantity'], 2);
                    $totalAmount = bcadd($totalAmount, $lineTotal, 2);

                    $preparedItems[] = [
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                        'inventory' => $inventories->get($item['product_id']),
                    ];
                }

                $order = Order::create([
                    'number' => (string) Str::ulid(),
                    'customer_id' => $customer->id,
                    'branch_id' => $branch->id,
                    'status' => OrderStatus::Pending,
                    'total_amount' => $totalAmount,
                    'currency' => 'EGP',
                    'idempotency_key' => $idempotencyKey,
                ]);

                $ttlMinutes = (int) config('inventory.reservation_ttl_minutes', 15);
                $expiresAt = now()->addMinutes($ttlMinutes);

                foreach ($preparedItems as $prepItem) {
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $prepItem['product_id'],
                        'quantity' => $prepItem['quantity'],
                        'unit_price' => $prepItem['unit_price'],
                        'line_total' => $prepItem['line_total'],
                    ]);

                    $reservation = StockReservation::create([
                        'order_item_id' => $orderItem->id,
                        'branch_inventory_id' => $prepItem['inventory']->id,
                        'quantity' => $prepItem['quantity'],
                        'status' => StockReservationStatus::Active,
                        'expires_at' => $expiresAt,
                        'resolved_at' => null,
                    ]);

                    InventoryMovement::create([
                        'branch_inventory_id' => $prepItem['inventory']->id,
                        'user_id' => $user->id,
                        'reason' => InventoryMovementReason::Reserve,
                        'quantity_delta' => 0,
                        'reserved_delta' => $prepItem['quantity'],
                        'quantity_after' => $prepItem['inventory']->quantity,
                        'reserved_after' => $prepItem['inventory']->reserved_quantity,
                        'reference_type' => $reservation->getMorphClass(),
                        'reference_id' => $reservation->id,
                        'note' => 'Order stock reservation',
                    ]);
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => null,
                    'to_status' => OrderStatus::Pending,
                    'changed_by' => $user->id,
                    'note' => 'Order created',
                ]);

                $order->load('items');

                return ['order' => $order, 'is_new' => true];
            }, 3);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
                $existingOrder = Order::where('customer_id', $customer->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with('items')
                    ->first();

                if ($existingOrder !== null) {
                    return ['order' => $existingOrder, 'is_new' => false];
                }
            }

            throw $e;
        }
    }
}
