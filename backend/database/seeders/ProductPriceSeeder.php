<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class ProductPriceSeeder extends Seeder
{
    public function run(): void
    {
        $prices = [
            // Coffee Latte - Rp12.000
            'Coffee Latte - Butterscotch' => 12000,
            'Coffee Latte - Aren' => 12000,
            'Coffee Latte - Caramel' => 12000,
            'Coffee Latte - Vanilla' => 12000,
            'Coffee Latte - Coffee' => 12000,
            'Coffee Latte - Moka' => 12000,
            'Coffee Latte - Spanish' => 12000,
            'Coffee Latte - Matcha' => 12000,
            'Coffee Latte - Ginger' => 12000,

            // Fresh Milk - Rp10.000
            'Fresh Milk - Bubble Gum' => 10000,
            'Fresh Milk - Blue Wagon' => 10000,
            'Fresh Milk - Lychee' => 10000,
            'Fresh Milk - Peach' => 10000,
            'Fresh Milk - Vanilla' => 10000,
            'Fresh Milk - Caramel' => 10000,
            'Fresh Milk - Thai Tea' => 10000,
            'Fresh Milk - Red Velvet' => 10000,
            'Fresh Milk - Strawberry' => 10000,
            'Fresh Milk - Butterscotch' => 10000,
            'Fresh Milk - Aren' => 10000,
            'Fresh Milk - Ginger' => 10000,
            'Fresh Milk - Melon' => 10000,

            // Mojito - Rp12.000
            'Mojito - Bubble Gum' => 12000,
            'Mojito - Blue Wagon' => 12000,
            'Mojito - Peach' => 12000,
            'Mojito - Orange' => 12000,
            'Mojito - Vanilla' => 12000,
            'Mojito - Lychee' => 12000,
            'Mojito - Melon' => 12000,
            'Mojito - Strawberry' => 12000,
        ];

        foreach ($prices as $productName => $price) {
            $product = Product::where(
                'slug',
                Str::slug($productName)
            )->first();

            if (!$product) {
                throw new RuntimeException(
                    "Produk {$productName} tidak ditemukan."
                );
            }

            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'sales_channel' => 'OFFLINE',
                ],
                [
                    'price' => $price,
                    'is_active' => true,
                ]
            );
        }
    }
}