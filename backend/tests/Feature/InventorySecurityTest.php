<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasPermission;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createStaff(
        string $email,
        array $permissions = []
    ): User {
        // Gunakan role resmi yang dikenali sistem.
        $role = Role::firstOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Staff']
        );

        $user = User::create([
            'name' => 'Inventory Test User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucwords(
                        str_replace('.', ' ', $slug)
                    ),
                ]
            );

            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createIngredient(
        string $stock = '10.000',
        bool $active = true
    ): Ingredient {
        static $counter = 0;
        $counter++;

        return Ingredient::create([
            'name' => 'Bahan Test ' . $counter,
            'unit' => 'gram',
            'current_stock' => $stock,
            'minimum_stock' => '2.000',
            'is_active' => $active,
        ]);
    }

    public function test_staff_with_permission_can_create_ingredient_and_initial_stock_movement(): void
    {
        $user = $this->createStaff(
            'inventory-create@example.com',
            ['inventory.manage']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/inventory', [
                'name' => 'Gula Pasir',
                'unit' => 'gram',
                'current_stock' => '12.500',
                'minimum_stock' => '2.000',
            ]);

        $response->assertCreated();

        $ingredientId = $response->json('data.id');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'name' => 'Gula Pasir',
            'current_stock' => '12.500',
            'minimum_stock' => '2.000',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredientId,
            'user_id' => $user->id,
            'type' => 'IN',
            'quantity' => '12.500',
            'stock_before' => '0.000',
            'stock_after' => '12.500',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'CREATE_INGREDIENT',
            'entity_type' => 'INGREDIENT',
            'entity_id' => $ingredientId,
        ]);
    }

    public function test_create_ingredient_without_initial_stock_does_not_create_movement(): void
    {
        $user = $this->createStaff(
            'inventory-zero@example.com',
            ['inventory.manage']
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/inventory', [
                'name' => 'Tepung Terigu',
                'unit' => 'kg',
                'minimum_stock' => '1.000',
            ]);

        $response->assertCreated();

        $ingredientId = $response->json('data.id');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'current_stock' => '0.000',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredientId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CREATE_INGREDIENT',
            'entity_id' => $ingredientId,
        ]);
    }

    public function test_staff_can_update_ingredient_and_audit_before_after_is_saved(): void
    {
        $user = $this->createStaff(
            'inventory-update@example.com',
            ['inventory.manage']
        );

        $ingredient = $this->createIngredient();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/inventory/{$ingredient->id}", [
                'name' => $ingredient->name,
                'unit' => 'kilogram',
                'minimum_stock' => '3.500',
                'is_active' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'unit' => 'kilogram',
            'minimum_stock' => '3.500',
            'current_stock' => '10.000',
        ]);

        $audit = AuditLog::where(
            'action',
            'UPDATE_INGREDIENT'
        )->where(
            'entity_id',
            $ingredient->id
        )->firstOrFail();

        $this->assertSame(
            'gram',
            $audit->metadata['before']['unit']
        );

        $this->assertSame(
            'kilogram',
            $audit->metadata['after']['unit']
        );

        $this->assertSame(
            '10.000',
            $audit->metadata['after']['current_stock']
        );
    }

    public function test_staff_can_deactivate_ingredient_without_deleting_it(): void
    {
        $user = $this->createStaff(
            'inventory-deactivate@example.com',
            ['inventory.manage']
        );

        $ingredient = $this->createIngredient();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/inventory/{$ingredient->id}");

        $response->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'DEACTIVATE_INGREDIENT',
            'entity_type' => 'INGREDIENT',
            'entity_id' => $ingredient->id,
        ]);
    }

    public function test_stock_in_uses_precise_decimal_calculation(): void
    {
        $user = $this->createStaff(
            'inventory-stock-in@example.com',
            ['inventory.stock.manage']
        );

        $ingredient = $this->createIngredient('10.125');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'IN',
                    'quantity' => '0.125',
                    'reason' => 'Pembelian bahan baku.',
                ]
            );

        $response->assertCreated();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '10.250',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'IN',
            'quantity' => '0.125',
            'stock_before' => '10.125',
            'stock_after' => '10.250',
        ]);
    }

    public function test_stock_out_cannot_exceed_available_stock(): void
    {
        $user = $this->createStaff(
            'inventory-stock-out@example.com',
            ['inventory.stock.manage']
        );

        $ingredient = $this->createIngredient('5.000');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'OUT',
                    'quantity' => '5.001',
                    'reason' => 'Penggunaan bahan baku.',
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '5.000',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'OUT',
        ]);
    }

    public function test_stock_in_cannot_exceed_decimal_database_capacity(): void
    {
        $user = $this->createStaff(
            'inventory-overflow@example.com',
            ['inventory.stock.manage']
        );

        $ingredient = $this->createIngredient(
            '99999999999.999'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'IN',
                    'quantity' => '0.001',
                    'reason' => 'Pengujian batas maksimum stok.',
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '99999999999.999',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'IN',
        ]);
    }

    public function test_adjustment_can_set_stock_to_zero(): void
    {
        $user = $this->createStaff(
            'inventory-adjustment@example.com',
            ['inventory.stock.manage']
        );

        $ingredient = $this->createIngredient('5.000');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'ADJUSTMENT',
                    'quantity' => '0.000',
                    'reason' => 'Hasil pemeriksaan stok fisik.',
                ]
            );

        $response->assertCreated();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '0.000',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'ADJUSTMENT',
            'quantity' => '0.000',
            'stock_before' => '5.000',
            'stock_after' => '0.000',
        ]);
    }

    public function test_inactive_ingredient_cannot_receive_stock_movement(): void
    {
        $user = $this->createStaff(
            'inventory-inactive@example.com',
            ['inventory.stock.manage']
        );

        $ingredient = $this->createIngredient('5.000', false);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'IN',
                    'quantity' => '1.000',
                    'reason' => 'Percobaan stok bahan nonaktif.',
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '5.000',
            'is_active' => false,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
        ]);
    }

    public function test_staff_without_stock_permission_cannot_move_stock(): void
    {
        $user = $this->createStaff(
            'inventory-forbidden@example.com',
            ['inventory.view']
        );

        $ingredient = $this->createIngredient('5.000');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'IN',
                    'quantity' => '1.000',
                    'reason' => 'Percobaan tanpa permission.',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '5.000',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
        ]);
    }

    public function test_controller_rechecks_inventory_manage_permission_without_middleware_on_create(): void
    {
        $user = $this->createStaff(
            'inventory-controller-create@example.com'
        );

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($user, 'sanctum')
            ->postJson('/api/inventory', [
                'name' => 'Bahan Tanpa Permission',
                'unit' => 'gram',
                'current_stock' => '5.000',
                'minimum_stock' => '1.000',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('ingredients', [
            'name' => 'Bahan Tanpa Permission',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'reason' => 'Stok awal bahan baku',
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'CREATE_INGREDIENT',
        ]);
    }

    public function test_controller_rechecks_inventory_manage_permission_without_middleware_on_update(): void
    {
        $user = $this->createStaff(
            'inventory-controller-update@example.com'
        );

        $ingredient = $this->createIngredient();

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($user, 'sanctum')
            ->putJson("/api/inventory/{$ingredient->id}", [
                'name' => 'Nama Tidak Boleh Berubah',
                'unit' => 'kilogram',
                'minimum_stock' => '3.000',
                'is_active' => true,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'unit' => 'gram',
            'minimum_stock' => '2.000',
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'UPDATE_INGREDIENT',
            'entity_id' => $ingredient->id,
        ]);
    }

    public function test_controller_rechecks_inventory_manage_permission_without_middleware_on_deactivate(): void
    {
        $user = $this->createStaff(
            'inventory-controller-deactivate@example.com'
        );

        $ingredient = $this->createIngredient();

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($user, 'sanctum')
            ->deleteJson("/api/inventory/{$ingredient->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'DEACTIVATE_INGREDIENT',
            'entity_id' => $ingredient->id,
        ]);
    }

    public function test_controller_rechecks_stock_permission_without_middleware_on_movement(): void
    {
        $user = $this->createStaff(
            'inventory-controller-movement@example.com'
        );

        $ingredient = $this->createIngredient('5.000');

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($user, 'sanctum')
            ->postJson(
                "/api/inventory/{$ingredient->id}/movements",
                [
                    'type' => 'IN',
                    'quantity' => '1.000',
                    'reason' => 'Percobaan tanpa permission.',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => '5.000',
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
        ]);
    }

    public function test_guest_cannot_access_inventory(): void
    {
        $response = $this->getJson('/api/inventory');

        $response->assertUnauthorized();
    }
}