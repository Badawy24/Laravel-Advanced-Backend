<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\InventoryMovement;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin InventoryMovement
 */
class InventoryMovementResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branchInventory = $this->branchInventory;
        $branch = $branchInventory?->branch;
        $product = $branchInventory?->product;

        return [
            'id' => $this->id,
            'branch_inventory_id' => $this->branch_inventory_id,
            'branch_inventory' => new BranchInventoryResource($this->whenLoaded('branchInventory')),
            'branch' => $branch ? new BranchResource($branch) : null,
            'product' => $product ? new ProductResource($product) : null,
            'quantity_delta' => $this->quantity_delta,
            'reserved_delta' => $this->reserved_delta,
            'reason' => $this->reason instanceof BackedEnum ? $this->reason->value : (string) $this->reason,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'quantity_after' => $this->quantity_after,
            'reserved_after' => $this->reserved_after,
            'note' => $this->note,
            'created_at' => $this->formatIsoDateTime($this->created_at),
        ];
    }
}
