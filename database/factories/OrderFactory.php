<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => (string) Str::ulid(),
            'customer_id' => Customer::factory(),
            'branch_id' => Branch::factory(),
            'status' => OrderStatus::Pending,
            'total_amount' => 150.00,
            'currency' => 'EGP',
            'idempotency_key' => null,
        ];
    }
}
