<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use Illuminate\Database\Seeder;

class BranchInventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $product = Product::where('sku', 'IPH17-001')->firstOrFail();

        $inventories = [
            'CAI' => ['quantity' => 10, 'reserved_quantity' => 0],
            'GIZ' => ['quantity' => 5, 'reserved_quantity' => 0],
            'ALX' => ['quantity' => 3, 'reserved_quantity' => 0],
        ];

        foreach ($inventories as $branchCode => $stock) {
            $branch = Branch::where('code', $branchCode)->firstOrFail();

            BranchInventory::updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $stock['quantity'],
                    'reserved_quantity' => $stock['reserved_quantity'],
                ]
            );
        }
    }
}
