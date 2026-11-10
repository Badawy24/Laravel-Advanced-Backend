<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $category = Category::where('slug', 'smartphones')->firstOrFail();

        Product::updateOrCreate(
            ['sku' => 'IPH17-001'],
            [
                'category_id' => $category->id,
                'name' => 'iPhone 17',
                'description' => 'Apple iPhone 17',
                'price' => 50000.00,
                'is_active' => true,
            ]
        );
    }
}
