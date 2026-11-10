<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\BranchInventoryNotFoundException;
use App\Exceptions\CustomerProfileNotFoundException;
use App\Exceptions\InactiveBranchException;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\MissingActiveReservationException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Resources\Api\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * Store a newly created order and reserve stock atomically.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->orderService->create(
                user: $request->user(),
                branchId: (int) $request->validated('branch_id'),
                items: $request->validated('items'),
                idempotencyKey: $request->header('Idempotency-Key')
            );

            if (! $result['is_new']) {
                return $this->successResponse(
                    new OrderResource($result['order']),
                    __('api.order_retrieved_successfully'),
                    200
                );
            }

            return $this->createdResponse(
                new OrderResource($result['order']),
                __('api.order_created_successfully')
            );
        } catch (CustomerProfileNotFoundException $e) {
            return $this->forbiddenResponse($e->getMessage());
        } catch (InactiveBranchException $e) {
            return $this->validationErrorResponse(
                ['branch_id' => [$e->getMessage()]],
                $e->getMessage()
            );
        } catch (InactiveProductException|BranchInventoryNotFoundException $e) {
            return $this->validationErrorResponse(
                ['items' => [$e->getMessage()]],
                $e->getMessage()
            );
        } catch (InsufficientStockException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Display the specified order details and its status history.
     */
    public function show(Request $request, int|string $order): JsonResponse
    {
        $orderModel = $this->findOrder($order);
        if ($orderModel === null) {
            return $this->notFoundResponse(__('api.order_not_found'));
        }

        /** @var User $user */
        $user = $request->user();
        if (! $this->isAuthorizedForOrder($user, $orderModel)) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        $this->loadOrderDetails($orderModel);

        return $this->successResponse(
            new OrderResource($orderModel),
            __('api.order_retrieved_successfully')
        );
    }

    /**
     * Confirm a pending order.
     */
    public function confirm(Request $request, int|string $order): JsonResponse
    {
        $orderModel = $this->findOrder($order);
        if ($orderModel === null) {
            return $this->notFoundResponse(__('api.order_not_found'));
        }

        /** @var User $user */
        $user = $request->user();
        if (! $this->isAuthorizedForOrder($user, $orderModel)) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        try {
            $updatedOrder = $this->orderService->confirm(
                $orderModel,
                $user,
                $this->extractNote($request)
            );

            $this->loadOrderDetails($updatedOrder);

            return $this->successResponse(
                new OrderResource($updatedOrder),
                __('api.order_confirmed_successfully')
            );
        } catch (InvalidOrderTransitionException|MissingActiveReservationException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Transition a confirmed order to processing.
     */
    public function process(Request $request, int|string $order): JsonResponse
    {
        $orderModel = $this->findOrder($order);
        if ($orderModel === null) {
            return $this->notFoundResponse(__('api.order_not_found'));
        }

        /** @var User $user */
        $user = $request->user();
        if (! $this->isAuthorizedForOrder($user, $orderModel)) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        try {
            $updatedOrder = $this->orderService->markProcessing(
                $orderModel,
                $user,
                $this->extractNote($request)
            );

            $this->loadOrderDetails($updatedOrder);

            return $this->successResponse(
                new OrderResource($updatedOrder),
                __('api.order_processing_successfully')
            );
        } catch (InvalidOrderTransitionException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Transition a processing order to completed.
     */
    public function complete(Request $request, int|string $order): JsonResponse
    {
        $orderModel = $this->findOrder($order);
        if ($orderModel === null) {
            return $this->notFoundResponse(__('api.order_not_found'));
        }

        /** @var User $user */
        $user = $request->user();
        if (! $this->isAuthorizedForOrder($user, $orderModel)) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        try {
            $updatedOrder = $this->orderService->complete(
                $orderModel,
                $user,
                $this->extractNote($request)
            );

            $this->loadOrderDetails($updatedOrder);

            return $this->successResponse(
                new OrderResource($updatedOrder),
                __('api.order_completed_successfully')
            );
        } catch (InvalidOrderTransitionException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Cancel an eligible order and release its reserved stock.
     */
    public function cancel(Request $request, int|string $order): JsonResponse
    {
        $orderModel = $this->findOrder($order);
        if ($orderModel === null) {
            return $this->notFoundResponse(__('api.order_not_found'));
        }

        /** @var User $user */
        $user = $request->user();
        if (! $this->isAuthorizedForOrder($user, $orderModel)) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        try {
            $updatedOrder = $this->orderService->cancel(
                $orderModel,
                $user,
                $this->extractNote($request)
            );

            $this->loadOrderDetails($updatedOrder);

            return $this->successResponse(
                new OrderResource($updatedOrder),
                __('api.order_cancelled_successfully')
            );
        } catch (InvalidOrderTransitionException|BranchInventoryNotFoundException $e) {
            return $this->conflictResponse($e->getMessage());
        }
    }

    /**
     * Resolve an order by its numeric ID.
     */
    protected function findOrder(int|string $order): ?Order
    {
        $orderId = filter_var($order, FILTER_VALIDATE_INT);

        if ($orderId === false || $orderId < 1) {
            return null;
        }

        return Order::find($orderId);
    }

    /**
     * Determine whether the authenticated user can access the given order.
     */
    protected function isAuthorizedForOrder(User $user, Order $order): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        $customer = $user->customer;

        return $customer !== null && (int) $order->customer_id === (int) $customer->id;
    }

    /**
     * Eager-load order items and chronologically ordered status history.
     */
    protected function loadOrderDetails(Order $order): void
    {
        $order->load([
            'items',
            'statusHistories' => fn ($query) => $query->orderBy('id', 'asc'),
        ]);
    }

    /**
     * Extract an optional transition note from the request.
     */
    protected function extractNote(Request $request): ?string
    {
        $note = $request->input('note');

        if (! is_string($note) || trim($note) === '') {
            return null;
        }

        return trim($note);
    }
}
