<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            [
                'code' => 'CAI',
                'name' => 'Cairo Branch',
                'city' => 'Cairo',
                'is_active' => true,
            ],
            [
                'code' => 'GIZ',
                'name' => 'Giza Branch',
                'city' => 'Giza',
                'is_active' => true,
            ],
            [
                'code' => 'ALX',
                'name' => 'Alexandria Branch',
                'city' => 'Alexandria',
                'is_active' => true,
            ],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['code' => $branch['code']],
                $branch
            );
        }
    }
}
