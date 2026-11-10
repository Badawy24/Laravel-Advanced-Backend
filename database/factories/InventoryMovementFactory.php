<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryMovementReason;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_inventory_id' => BranchInventory::factory(),
            'user_id' => User::factory(),
            'reason' => InventoryMovementReason::Reserve,
            'quantity_delta' => 0,
            'reserved_delta' => 2,
            'quantity_after' => 100,
            'reserved_after' => 2,
            'reference_type' => null,
            'reference_id' => null,
            'note' => 'Test reservation movement',
        ];
    }
}
