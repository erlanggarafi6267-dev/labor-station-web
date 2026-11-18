<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'Melihat Produk', 'slug' => 'product.view'],
            ['name' => 'Menambah Produk', 'slug' => 'product.create'],
            ['name' => 'Mengubah Produk', 'slug' => 'product.update'],
            ['name' => 'Mengubah Harga Produk', 'slug' => 'product.price.update'],
            ['name' => 'Menghapus Produk', 'slug' => 'product.delete'],
            ['name' => 'Mengubah Ketersediaan Produk', 'slug' => 'product.status.update'],

            ['name' => 'Mengelola Kategori', 'slug' => 'category.manage'],

            ['name' => 'Melihat Transaksi', 'slug' => 'transaction.view'],
            ['name' => 'Membuat Transaksi', 'slug' => 'transaction.create'],
            ['name' => 'Mengelola Pembayaran', 'slug' => 'transaction.payment'],
            ['name' => 'Mengubah Status Pesanan', 'slug' => 'transaction.status.update'],

            ['name' => 'Melihat Pengeluaran', 'slug' => 'expense.view'],
            ['name' => 'Menambah Pengeluaran', 'slug' => 'expense.create'],
            ['name' => 'Mengubah Pengeluaran', 'slug' => 'expense.update'],
            ['name' => 'Menghapus Pengeluaran', 'slug' => 'expense.delete'],

            ['name' => 'Melihat Audit Log', 'slug' => 'audit.view'],
            ['name' => 'Mengatur Permission Pengguna', 'slug' => 'permission.manage'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name']]
            );
        }
    }
}