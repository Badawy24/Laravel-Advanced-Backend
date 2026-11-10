<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockReservationStatus;
use App\Models\BranchInventory;
use App\Models\OrderItem;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'branch_inventory_id' => BranchInventory::factory(),
            'quantity' => 2,
            'status' => StockReservationStatus::Active,
            'expires_at' => now()->addMinutes(15),
            'resolved_at' => null,
        ];
    }
}
