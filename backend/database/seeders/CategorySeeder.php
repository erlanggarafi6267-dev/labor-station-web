<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Coffee',
                'description' => 'Menu minuman berbasis kopi.',
            ],
            [
                'name' => 'Non Coffee',
                'description' => 'Menu minuman tanpa kopi.',
            ],
            [
                'name' => 'Frozen Snacks',
                'description' => 'Aneka camilan frozen.',
            ],
            [
                'name' => 'Homemade Snacks',
                'description' => 'Camilan buatan sendiri.',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}