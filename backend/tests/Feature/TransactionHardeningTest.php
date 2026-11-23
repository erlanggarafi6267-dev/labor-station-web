<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(
        string $roleSlug,
        string $email,
        array $permissions = []
    ): User {
        $role = Role::create([
            'name' => ucfirst($roleSlug),
            'slug' => $roleSlug,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('.', ' ', $slug))]
            );

            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createCatalogProduct(
        string $offlineStatus = 'AVAILABLE',
        int $price = 6000
    ): Product {
        $category = Category::create([
            'name' => 'Minuman',
            'slug' => 'minuman',
            'description' => 'Kategori minuman',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Es Teh',
            'slug' => 'es-teh',
            'sku' => 'TEST-001',
            'description' => 'Produk pengujian',
            'is_active' => true,
            'offline_status' => $offlineStatus,
            'grabfood_status' => 'UNAVAILABLE',
            'temperature_options' => [],
        ]);

        // Sengaja dibuat aktif agar pengujian status kanal
        // tidak tercampur dengan pengujian harga yang tidak aktif.
        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel' => 'OFFLINE',
            'price' => $price,
            'is_active' => true,
        ]);

        return $product;
    }

    public function test_product_unavailable_on_offline_channel_cannot_be_sold(): void
    {
        $user = $this->createUser(
            'staff',
            'offline-unavailable@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct(
            offlineStatus: 'UNAVAILABLE'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'CATALOG',
                        'product_id' => $product->id,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_product_available_on_offline_channel_can_be_sold(): void
    {
        $user = $this->createUser(
            'staff',
            'offline-available@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct(
            offlineStatus: 'AVAILABLE'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'CATALOG',
                        'product_id' => $product->id,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'id' => $response->json('data.id'),
            'subtotal' => 6000,
            'total' => 6000,
        ]);
    }

    public function test_transaction_cannot_contain_more_than_100_items(): void
    {
        $user = $this->createUser(
            'staff',
            'too-many-items@example.com',
            ['transaction.create']
        );

        $items = [];

        for ($i = 1; $i <= 101; $i++) {
            $items[] = [
                'item_type' => 'MANUAL',
                'name' => 'Produk Manual ' . $i,
                'unit_price' => 5000,
                'quantity' => 1,
            ];
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => $items,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_manual_item_price_cannot_exceed_maximum_limit(): void
    {
        $user = $this->createUser(
            'staff',
            'price-limit@example.com',
            ['transaction.create']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Produk Harga Tidak Wajar',
                        'unit_price' => 1000000001,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_item_quantity_cannot_exceed_maximum_limit(): void
    {
        $user = $this->createUser(
            'staff',
            'quantity-limit@example.com',
            ['transaction.create']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Cireng',
                        'unit_price' => 5000,
                        'quantity' => 10001,
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }
}