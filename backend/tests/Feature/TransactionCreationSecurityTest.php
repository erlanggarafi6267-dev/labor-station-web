<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCreationSecurityTest extends TestCase
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
        bool $active = true,
        string $offlinePriceStatus = 'AVAILABLE',
        array $temperatures = []
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
            'is_active' => $active,
            'offline_status' => $offlinePriceStatus,
            'grabfood_status' => 'UNAVAILABLE',
            'temperature_options' => $temperatures,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel' => 'OFFLINE',
            'price' => 6000,
            'is_active' => $offlinePriceStatus === 'AVAILABLE',
        ]);

        return $product;
    }

    public function test_catalog_product_uses_database_price_not_client_price(): void
    {
        $user = $this->createUser(
            'staff',
            'catalog-price@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'CATALOG',
                        'product_id' => $product->id,
                        'quantity' => 2,
                        'unit_price' => 1,
                    ],
                ],
            ]);

        $response->assertCreated();

        $transactionId = $response->json('data.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'subtotal' => 12000,
            'total' => 12000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'product_id' => $product->id,
            'unit_price' => 6000,
            'quantity' => 2,
            'subtotal' => 12000,
        ]);
    }

    public function test_manual_item_price_is_calculated_by_backend(): void
    {
        $user = $this->createUser(
            'staff',
            'manual-item@example.com',
            ['transaction.create']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Mix Platter',
                        'unit_price' => 10000,
                        'quantity' => 2,
                    ],
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Cireng',
                        'unit_price' => 5000,
                        'quantity' => 3,
                    ],
                ],
            ]);

        $response->assertCreated();

        $transactionId = $response->json('data.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'subtotal' => 35000,
            'total' => 35000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'product_name_snapshot' => 'Mix Platter',
            'unit_price' => 10000,
            'quantity' => 2,
            'subtotal' => 20000,
            'product_id' => null,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'product_name_snapshot' => 'Cireng',
            'unit_price' => 5000,
            'quantity' => 3,
            'subtotal' => 15000,
            'product_id' => null,
        ]);
    }

    public function test_staff_without_transaction_permission_cannot_create_transaction(): void
    {
        $user = $this->createUser(
            'staff',
            'no-transaction-permission@example.com'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Cireng',
                        'unit_price' => 5000,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertForbidden();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_guest_cannot_create_transaction(): void
    {
        $response = $this->postJson('/api/transactions', [
            'sales_channel' => 'OFFLINE',
            'items' => [
                [
                    'item_type' => 'MANUAL',
                    'name' => 'Cireng',
                    'unit_price' => 5000,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_inactive_catalog_product_cannot_be_sold(): void
    {
        $user = $this->createUser(
            'staff',
            'inactive-product@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct(active: false);

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

    public function test_catalog_product_without_active_channel_price_cannot_be_sold(): void
    {
        $user = $this->createUser(
            'staff',
            'unavailable-price@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct(
            active: true,
            offlinePriceStatus: 'UNAVAILABLE'
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

    public function test_catalog_product_requires_valid_temperature_selection(): void
    {
        $user = $this->createUser(
            'staff',
            'temperature-check@example.com',
            ['transaction.create']
        );

        $product = $this->createCatalogProduct(
            active: true,
            offlinePriceStatus: 'AVAILABLE',
            temperatures: ['HOT', 'ICED']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'CATALOG',
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'temperature' => 'FROZEN',
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_manual_item_cannot_use_catalog_temperature_option(): void
    {
        $user = $this->createUser(
            'staff',
            'manual-temperature@example.com',
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
                        'quantity' => 1,
                        'temperature' => 'HOT',
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transaction_requires_at_least_one_item(): void
    {
        $user = $this->createUser(
            'staff',
            'empty-transaction@example.com',
            ['transaction.create']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [],
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transaction_records_authenticated_cashier_as_creator(): void
    {
        $user = $this->createUser(
            'staff',
            'transaction-owner@example.com',
            ['transaction.create']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/transactions', [
                'sales_channel' => 'OFFLINE',
                'items' => [
                    [
                        'item_type' => 'MANUAL',
                        'name' => 'Kopi Susu',
                        'unit_price' => 12000,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'payment_status' => 'UNPAID',
            'order_status' => 'NEW',
            'total' => 12000,
        ]);
    }
}