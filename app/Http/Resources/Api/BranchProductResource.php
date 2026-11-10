<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\BranchInventory;
use Illuminate\Http\Request;

/**
 * @mixin BranchInventory
 */
class BranchProductResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $quantity = (int) $this->quantity;
        $reservedQuantity = (int) $this->reserved_quantity;
        $availableQuantity = $quantity - $reservedQuantity;

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $this->formatPrice($product->price),
            'is_active' => (bool) $product->is_active,
            'category' => $product->relationLoaded('category') && $product->category !== null
                ? (new CategoryResource($product->category))->resolve($request)
                : null,
            'branch_id' => $this->branch_id,
            'quantity' => $quantity,
            'reserved_quantity' => $reservedQuantity,
            'available_quantity' => $availableQuantity,
            'in_stock' => $availableQuantity > 0,
            'created_at' => $this->formatIsoDateTime($product->created_at),
            'updated_at' => $this->formatIsoDateTime($product->updated_at),
        ];
    }
}
