<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Bahan Baku',
                'description' => 'Pembelian bahan baku minuman dan makanan.',
            ],
            [
                'name' => 'Operasional',
                'description' => 'Biaya operasional usaha sehari-hari.',
            ],
            [
                'name' => 'Listrik dan Air',
                'description' => 'Pembayaran listrik dan air.',
            ],
            [
                'name' => 'Peralatan',
                'description' => 'Pembelian dan kebutuhan peralatan usaha.',
            ],
            [
                'name' => 'Transportasi',
                'description' => 'Biaya transportasi dan pengiriman.',
            ],
            [
                'name' => 'Lain-lain',
                'description' => 'Pengeluaran lainnya.',
            ],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::updateOrCreate(
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