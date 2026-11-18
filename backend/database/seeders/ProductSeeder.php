<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            // COFFEE LATTE
            ...array_map(fn ($name) => [
                'category_slug' => 'coffee',
                'name' => 'Coffee Latte - ' . $name,
            ], [
                'Butterscotch', 'Aren', 'Caramel', 'Vanilla',
                'Coffee', 'Moka', 'Spanish', 'Matcha', 'Ginger',
            ]),

            // ICE COFFEE
            ...array_map(fn ($name) => [
                'category_slug' => 'coffee',
                'name' => 'Ice Coffee - ' . $name,
            ], [
                'Americano', 'Peach Americano', 'Long Black',
                'Japanese', 'Mont Blanc',
            ]),

            // HOT COFFEE
            ...array_map(fn ($name) => [
                'category_slug' => 'coffee',
                'name' => 'Hot Coffee - ' . $name,
            ], [
                'V60', 'Vietnam Drip', 'Kopi Tubruk',
            ]),

            // FRESH MILK
            ...array_map(fn ($name) => [
                'category_slug' => 'non-coffee',
                'name' => 'Fresh Milk - ' . $name,
            ], [
                'Bubble Gum', 'Blue Wagon', 'Lychee', 'Peach',
                'Vanilla', 'Caramel', 'Thai Tea', 'Red Velvet',
                'Strawberry', 'Butterscotch', 'Aren', 'Ginger',
                'Melon',
            ]),

            // MOJITO
            ...array_map(fn ($name) => [
                'category_slug' => 'non-coffee',
                'name' => 'Mojito - ' . $name,
            ], [
                'Bubble Gum', 'Blue Wagon', 'Peach', 'Orange',
                'Vanilla', 'Lychee', 'Melon', 'Strawberry',
            ]),
        ];

        foreach ($products as $item) {
            $category = Category::where(
                'slug',
                $item['category_slug']
            )->first();

            if (!$category) {
                throw new RuntimeException(
                    "Kategori {$item['category_slug']} tidak ditemukan."
                );
            }

            Product::updateOrCreate(
                ['slug' => Str::slug($item['name'])],
                [
                    'category_id' => $category->id,
                    'name' => $item['name'],
                    'is_active' => true,
                ]
            );
        }
    }
}