<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\BranchProductResource;
use App\Http\Resources\Api\ProductResource;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    /**
     * List active products with category information and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->orderBy('id', 'asc');

        return $this->paginatedQueryResponse(
            $query,
            $request,
            __('api.products_retrieved_successfully'),
            ProductResource::class
        );
    }

    /**
     * List active products and their stock availability in the selected active branch.
     */
    public function branchProducts(Request $request, int|string $branch): JsonResponse
    {
        $branchId = filter_var($branch, FILTER_VALIDATE_INT);

        if ($branchId === false || $branchId < 1) {
            return $this->notFoundResponse(__('api.branch_not_found'));
        }

        $branchModel = Branch::where('is_active', true)->find($branchId);

        if ($branchModel === null) {
            return $this->notFoundResponse(__('api.branch_not_found'));
        }

        $query = BranchInventory::query()
            ->where('branch_id', $branchModel->id)
            ->whereHas('product', function (Builder $productQuery): void {
                $productQuery->where('is_active', true);
            })
            ->with(['product.category'])
            ->orderBy('product_id', 'asc');

        return $this->paginatedQueryResponse(
            $query,
            $request,
            __('api.branch_products_retrieved_successfully'),
            BranchProductResource::class
        );
    }
}
