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
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\MissingActiveReservationException;
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
                    $unitPrice = bcadd((string) $product->price, '0.00', 2);
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

    /**
     * Transition an order to a new status with row locking and history logging.
     *
     * @throws InvalidOrderTransitionException
     * @throws MissingActiveReservationException
     * @throws BranchInventoryNotFoundException
     */
    public function transitionStatus(
        Order $order,
        OrderStatus $targetStatus,
        User|int|null $changedBy = null,
        ?string $note = null
    ): Order {
        if ($targetStatus === OrderStatus::Cancelled) {
            return $this->cancel($order, $changedBy, $note);
        }

        $changedById = $changedBy instanceof User ? $changedBy->id : $changedBy;

        return DB::transaction(function () use ($order, $targetStatus, $changedById, $note): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $lockedOrder->status;

            if (! $fromStatus->canTransitionTo($targetStatus)) {
                throw new InvalidOrderTransitionException;
            }

            if ($targetStatus === OrderStatus::Confirmed) {
                $this->ensureValidActiveReservations($lockedOrder);
            }

            $lockedOrder->status = $targetStatus;
            $lockedOrder->save();

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'changed_by' => $changedById,
                'note' => $note,
            ]);

            return $lockedOrder;
        }, 3);
    }

    /**
     * Confirm a pending order after verifying valid active stock reservations.
     *
     * @throws InvalidOrderTransitionException
     * @throws MissingActiveReservationException
     */
    public function confirm(Order $order, User|int|null $changedBy = null, ?string $note = null): Order
    {
        return $this->transitionStatus($order, OrderStatus::Confirmed, $changedBy, $note);
    }

    /**
     * Transition a confirmed order to processing.
     *
     * @throws InvalidOrderTransitionException
     */
    public function markProcessing(Order $order, User|int|null $changedBy = null, ?string $note = null): Order
    {
        return $this->transitionStatus($order, OrderStatus::Processing, $changedBy, $note);
    }

    /**
     * Transition a processing order to completed.
     *
     * @throws InvalidOrderTransitionException
     */
    public function complete(Order $order, User|int|null $changedBy = null, ?string $note = null): Order
    {
        return $this->transitionStatus($order, OrderStatus::Completed, $changedBy, $note);
    }

    /**
     * Cancel an eligible order and atomically release its active stock reservations.
     *
     * @throws InvalidOrderTransitionException
     * @throws BranchInventoryNotFoundException
     */
    public function cancel(Order $order, User|int|null $changedBy = null, ?string $note = null): Order
    {
        $changedById = $changedBy instanceof User ? $changedBy->id : $changedBy;

        return DB::transaction(function () use ($order, $changedById, $note): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $lockedOrder->status;

            if (! $fromStatus->canTransitionTo(OrderStatus::Cancelled)) {
                throw new InvalidOrderTransitionException;
            }

            $orderItemIds = OrderItem::where('order_id', $lockedOrder->id)
                ->orderBy('id', 'asc')
                ->pluck('id');

            if ($orderItemIds->isNotEmpty()) {
                /** @var Collection<int, StockReservation> $activeReservations */
                $activeReservations = StockReservation::whereIn('order_item_id', $orderItemIds)
                    ->where('status', StockReservationStatus::Active)
                    ->whereNull('resolved_at')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($activeReservations->isNotEmpty()) {
                    $branchInventoryIds = $activeReservations
                        ->pluck('branch_inventory_id')
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    /** @var Collection<int, BranchInventory> $inventories */
                    $inventories = BranchInventory::whereIn('id', $branchInventoryIds)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $resolvedAt = now();

                    foreach ($activeReservations as $reservation) {
                        /** @var BranchInventory|null $inventory */
                        $inventory = $inventories->get($reservation->branch_inventory_id);
                        if ($inventory === null) {
                            throw new BranchInventoryNotFoundException;
                        }

                        $inventory->reserved_quantity -= $reservation->quantity;
                        $inventory->save();

                        $reservation->status = StockReservationStatus::Released;
                        $reservation->resolved_at = $resolvedAt;
                        $reservation->save();

                        InventoryMovement::create([
                            'branch_inventory_id' => $inventory->id,
                            'user_id' => $changedById,
                            'reason' => InventoryMovementReason::Release,
                            'quantity_delta' => 0,
                            'reserved_delta' => -$reservation->quantity,
                            'quantity_after' => $inventory->quantity,
                            'reserved_after' => $inventory->reserved_quantity,
                            'reference_type' => $reservation->getMorphClass(),
                            'reference_id' => $reservation->id,
                            'note' => $note ?? 'Order cancellation stock release',
                        ]);
                    }
                }
            }

            $lockedOrder->status = OrderStatus::Cancelled;
            $lockedOrder->save();

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Cancelled,
                'changed_by' => $changedById,
                'note' => $note,
            ]);

            return $lockedOrder;
        }, 3);
    }

    /**
     * Verify that the order has items and every item has a valid active stock reservation.
     *
     * @throws MissingActiveReservationException
     */
    protected function ensureValidActiveReservations(Order $lockedOrder): void
    {
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::where('order_id', $lockedOrder->id)
            ->orderBy('id', 'asc')
            ->get();

        if ($items->isEmpty()) {
            throw new MissingActiveReservationException;
        }

        /** @var Collection<int, StockReservation> $activeReservations */
        $activeReservations = StockReservation::whereIn('order_item_id', $items->pluck('id'))
            ->where('status', StockReservationStatus::Active)
            ->whereNull('resolved_at')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('order_item_id');

        if ($activeReservations->count() !== $items->count()) {
            throw new MissingActiveReservationException;
        }

        foreach ($items as $item) {
            /** @var StockReservation|null $reservation */
            $reservation = $activeReservations->get($item->id);

            if ($reservation === null || $reservation->quantity !== $item->quantity || $reservation->quantity <= 0) {
                throw new MissingActiveReservationException;
            }
        }
    }
}
