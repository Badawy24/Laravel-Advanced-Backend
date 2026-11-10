<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = [
            [
                'email' => 'customer1@example.com',
                'phone' => '+201000000001',
                'address' => '123 El-Tahrir Street, Cairo, Egypt',
            ],
            [
                'email' => 'customer2@example.com',
                'phone' => '+201000000002',
                'address' => '456 Al-Ahram Street, Giza, Egypt',
            ],
        ];

        foreach ($customers as $customerData) {
            $user = User::where('email', $customerData['email'])->firstOrFail();

            Customer::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => $customerData['phone'],
                    'address' => $customerData['address'],
                ]
            );
        }
    }
}
