<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Subcategory;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Create a School Fees category
        $schoolFees = Category::create([
            'name' => 'School Fees',
            'price' => null,
        ]);

        Subcategory::create([
            'category_id' => $schoolFees->id,
            'name' => 'Primary',
            'price' => 50000,
        ]);

        Subcategory::create([
            'category_id' => $schoolFees->id,
            'name' => 'Secondary',
            'price' => 80000,
        ]);

        // Create a Uniform category
        $uniform = Category::create([
            'name' => 'Uniform',
            'price' => null,
        ]);

        Subcategory::create([
            'category_id' => $uniform->id,
            'name' => 'Shirt',
            'price' => 3000,
        ]);

        Subcategory::create([
            'category_id' => $uniform->id,
            'name' => 'Trousers',
            'price' => 4000,
        ]);
    }
}
