<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\InventoryMovementResource;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends ApiController
{
    /**
     * Display a paginated list of inventory movements.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== UserRole::Admin) {
            return $this->forbiddenResponse(__('api.forbidden'));
        }

        $query = InventoryMovement::with([
            'branchInventory.branch',
            'branchInventory.product',
        ])->orderBy('id', 'desc');

        return $this->paginatedQueryResponse(
            query: $query,
            request: $request,
            message: __('api.inventory_movements_retrieved_successfully'),
            resourceClass: InventoryMovementResource::class
        );
    }
}
