<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\BranchInventory;
use Illuminate\Http\Request;

/**
 * @mixin BranchInventory
 */
class BranchInventoryResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
