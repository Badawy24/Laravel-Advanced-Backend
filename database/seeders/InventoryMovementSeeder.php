<?php

namespace Database\Seeders;

use App\Enums\InventoryMovementReason;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\Seeder;

class InventoryMovementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = Product::where('sku', 'IPH17-001')->firstOrFail();
        $branchCodes = ['CAI', 'GIZ', 'ALX'];

        foreach ($branchCodes as $code) {
            $branch = Branch::where('code', $code)->firstOrFail();
            $inventory = BranchInventory::where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->firstOrFail();

            InventoryMovement::updateOrCreate(
                [
                    'branch_inventory_id' => $inventory->id,
                    'reason' => InventoryMovementReason::Restock,
                    'reference_type' => null,
                    'reference_id' => null,
                ],
                [
                    'user_id' => null,
                    'quantity_delta' => $inventory->quantity,
                    'reserved_delta' => 0,
                    'quantity_after' => $inventory->quantity,
                    'reserved_after' => 0,
                    'note' => 'Initial stock restock',
                ]
            );
        }
    }
}
