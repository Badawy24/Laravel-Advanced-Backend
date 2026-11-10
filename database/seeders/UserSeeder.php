<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'username' => 'admin',
                'name' => 'System Admin',
                'email' => 'admin@example.com',
                'password' => 'Password123!',
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
            [
                'username' => 'customer1',
                'name' => 'Customer One',
                'email' => 'customer1@example.com',
                'password' => 'Password123!',
                'role' => UserRole::Customer,
                'email_verified_at' => now(),
            ],
            [
                'username' => 'customer2',
                'name' => 'Customer Two',
                'email' => 'customer2@example.com',
                'password' => 'Password123!',
                'role' => UserRole::Customer,
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['username' => $userData['username']],
                $userData
            );
        }
    }
}
