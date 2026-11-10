<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BranchInventoryNotFoundException;
use App\Exceptions\CustomerProfileNotFoundException;
use App\Exceptions\InactiveBranchException;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Resources\Api\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

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
}
