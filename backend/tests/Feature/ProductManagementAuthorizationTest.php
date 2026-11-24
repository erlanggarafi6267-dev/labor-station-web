<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $slug): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function createUser(
        string $roleSlug,
        string $email
    ): User {
        $role = $this->createRole($roleSlug);

        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);
    }

    private function grantPermission(
        User $user,
        string $slug
    ): void {
        $permission = Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst(str_replace('.', ' ', $slug))]
        );

        $user->permissions()->attach($permission->id);
    }

    private function createProduct(): Product
    {
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
            'description' => 'Produk untuk pengujian',
            'is_active' => true,
            'offline_status' => 'AVAILABLE',
            'grabfood_status' => 'AVAILABLE',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel' => 'OFFLINE',
            'price' => 5000,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel' => 'GRABFOOD',
            'price' => 7000,
            'is_active' => true,
        ]);

        return $product;
    }

    public function test_staff_with_status_permission_can_update_product_status(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-status@example.com'
        );

        $this->grantPermission(
            $user,
            'product.status.update'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'offline_status' => 'UNAVAILABLE',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'offline_status' => 'UNAVAILABLE',
        ]);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'sales_channel' => 'OFFLINE',
            'is_active' => false,
        ]);
    }

    public function test_staff_without_status_permission_cannot_update_product_status(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-no-status@example.com'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'offline_status' => 'UNAVAILABLE',
            ]);

        $response->assertForbidden()
            ->assertJsonPath(
                'required_permission',
                'product.status.update'
            );

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'offline_status' => 'AVAILABLE',
        ]);
    }

    public function test_staff_with_product_update_permission_can_update_product_name(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-update@example.com'
        );

        $this->grantPermission(
            $user,
            'product.update'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'name' => 'Es Teh Manis',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Es Teh Manis',
        ]);
    }

    public function test_staff_without_product_update_permission_cannot_update_product_name(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-no-update@example.com'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'name' => 'Nama Tidak Sah',
            ]);

        $response->assertForbidden()
            ->assertJsonPath(
                'required_permission',
                'product.update'
            );

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Es Teh',
        ]);
    }

    public function test_empty_update_request_is_rejected(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-empty@example.com'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", []);

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('audit_logs', [
            'entity_type' => 'PRODUCT',
            'entity_id' => $product->id,
            'action' => 'UPDATE_PRODUCT',
        ]);
    }

    public function test_unknown_update_field_is_rejected(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-unknown@example.com'
        );

        $product = $this->createProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'is_owner' => true,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Es Teh',
        ]);
    }

    public function test_guest_cannot_update_product(): void
    {
        $product = $this->createProduct();

        $response = $this->putJson(
            "/api/owner/products/{$product->id}",
            [
                'name' => 'Perubahan Tanpa Login',
            ]
        );

        $response->assertUnauthorized();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Es Teh',
        ]);
    }

    public function test_staff_with_status_permission_can_restore_archived_product(): void
    {
        $user = $this->createUser(
            'staff',
            'staff-restore@example.com'
        );

        $this->grantPermission(
            $user,
            'product.status.update'
        );

        $product = $this->createProduct();

        $product->update([
            'is_active' => false,
            'offline_status' => 'AVAILABLE',
            'grabfood_status' => 'UNAVAILABLE',
        ]);

        $product->prices()->update([
            'is_active' => false,
        ]);

        $product->delete();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/owner/products/{$product->id}", [
                'is_active' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'sales_channel' => 'OFFLINE',
            'price' => 5000,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'sales_channel' => 'GRABFOOD',
            'price' => 7000,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'PRODUCT',
            'entity_id' => $product->id,
            'action' => 'RESTORE_PRODUCT',
        ]);
    }


public function test_deactivating_product_deactivates_all_channel_prices(): void
{
    $user = $this->createUser(
        'staff',
        'staff-deactivate-product@example.com'
    );

    $this->grantPermission($user, 'product.status.update');

    $product = $this->createProduct();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/owner/products/{$product->id}", [
            'is_active' => false,
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'is_active' => false,
    ]);

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'OFFLINE',
        'is_active' => false,
    ]);

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'GRABFOOD',
        'is_active' => false,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'PRODUCT',
        'entity_id' => $product->id,
        'action' => 'UPDATE_PRODUCT',
    ]);
}

public function test_reactivating_product_synchronizes_channel_prices(): void
{
    $user = $this->createUser(
        'staff',
        'staff-reactivate-product@example.com'
    );

    $this->grantPermission($user, 'product.status.update');

    $product = $this->createProduct();

    $product->update([
        'is_active' => false,
        'offline_status' => 'AVAILABLE',
        'grabfood_status' => 'UNAVAILABLE',
    ]);

    $product->prices()->update([
        'is_active' => false,
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/owner/products/{$product->id}", [
            'is_active' => true,
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'is_active' => true,
        'offline_status' => 'AVAILABLE',
        'grabfood_status' => 'UNAVAILABLE',
    ]);

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'OFFLINE',
        'price' => 5000,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'GRABFOOD',
        'price' => 7000,
        'is_active' => false,
    ]);
}

public function test_product_price_above_postgresql_bigint_limit_is_rejected(): void
{
    $user = $this->createUser(
        'staff',
        'staff-bigint-price@example.com'
    );

    $this->grantPermission($user, 'product.price.update');

    $product = $this->createProduct();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/owner/products/{$product->id}", [
            'prices' => [
                [
                    'sales_channel' => 'OFFLINE',
                    'price' => '9223372036854775808',
                ],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('prices.0.price');

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'OFFLINE',
        'price' => 5000,
        'is_active' => true,
    ]);
}

public function test_updating_channel_status_preserves_other_channel_price(): void
{
    $user = $this->createUser(
        'staff',
        'staff-channel-status@example.com'
    );

    $this->grantPermission($user, 'product.status.update');

    $product = $this->createProduct();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/owner/products/{$product->id}", [
            'grabfood_status' => 'UNAVAILABLE',
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'OFFLINE',
        'price' => 5000,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('product_prices', [
        'product_id' => $product->id,
        'sales_channel' => 'GRABFOOD',
        'price' => 7000,
        'is_active' => false,
    ]);
}
}