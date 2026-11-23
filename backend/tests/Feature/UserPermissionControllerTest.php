<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $slug): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function createUser(Role $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role->slug) . ' User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);
    }

    private function createPermission(string $slug): Permission
    {
        return Permission::create([
            'name' => ucfirst(str_replace('.', ' ', $slug)),
            'slug' => $slug,
        ]);
    }

    public function test_owner_can_view_admin_and_staff_users(): void
    {
        $ownerRole = $this->createRole('owner');
        $adminRole = $this->createRole('admin');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $admin = $this->createUser($adminRole, 'admin@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/owner/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['email' => $admin->email])
            ->assertJsonFragment(['email' => $staff->email])
            ->assertJsonMissing(['email' => $owner->email]);
    }

    public function test_owner_can_assign_permissions_to_staff(): void
    {
        $ownerRole = $this->createRole('owner');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $permission = $this->createPermission('product.view');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => ['product.view'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $staff->id,
            'permission_id' => $permission->id,
            'granted_by' => $owner->id,
        ]);

        $audit = AuditLog::query()
            ->where('action', 'UPDATE_USER_PERMISSIONS')
            ->where('entity_type', 'USER')
            ->where('entity_id', $staff->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame([], $audit->metadata['permissions']['before']);
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['after']
        );
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['granted']
        );
        $this->assertSame(
            [],
            $audit->metadata['permissions']['revoked']
        );
    }

    public function test_owner_can_revoke_all_permissions_from_staff(): void
    {
        $ownerRole = $this->createRole('owner');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $permission = $this->createPermission('product.view');

        $staff->permissions()->attach($permission->id, [
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => [],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $staff->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseCount('user_permissions', 0);

        $audit = AuditLog::query()
            ->where('action', 'REVOKE_USER_PERMISSIONS')
            ->where('entity_type', 'USER')
            ->where('entity_id', $staff->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['before']
        );
        $this->assertSame(
            [],
            $audit->metadata['permissions']['after']
        );
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['revoked']
        );
    }

    public function test_owner_can_replace_permissions_and_audit_both_changes(): void
    {
        $ownerRole = $this->createRole('owner');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $oldPermission = $this->createPermission('product.view');
        $newPermission = $this->createPermission('product.update');

        $staff->permissions()->attach($oldPermission->id, [
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => ['product.update'],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $staff->id,
            'permission_id' => $oldPermission->id,
        ]);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $staff->id,
            'permission_id' => $newPermission->id,
            'granted_by' => $owner->id,
        ]);

        $audit = AuditLog::query()
            ->where('action', 'UPDATE_USER_PERMISSIONS')
            ->where('entity_id', $staff->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['before']
        );
        $this->assertSame(
            ['product.update'],
            $audit->metadata['permissions']['after']
        );
        $this->assertSame(
            ['product.update'],
            $audit->metadata['permissions']['granted']
        );
        $this->assertSame(
            ['product.view'],
            $audit->metadata['permissions']['revoked']
        );
    }

    public function test_unchanged_permissions_do_not_create_duplicate_audit(): void
    {
        $ownerRole = $this->createRole('owner');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $permission = $this->createPermission('product.view');

        $staff->permissions()->attach($permission->id, [
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => ['product.view'],
            ])
            ->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $staff->id,
            'permission_id' => $permission->id,
            'granted_by' => $owner->id,
        ]);
    }

    public function test_owner_cannot_modify_another_owner_permissions(): void
    {
        $ownerRole = $this->createRole('owner');

        $owner = $this->createUser($ownerRole, 'owner@example.com');

        $anotherOwner = $this->createUser(
            $ownerRole,
            'another-owner@example.com'
        );

        $this->createPermission('product.view');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$anotherOwner->id}/permissions", [
                'permissions' => ['product.view'],
            ])
            ->assertStatus(422);
    }

    public function test_admin_cannot_access_user_permission_management(): void
    {
        $adminRole = $this->createRole('admin');
        $staffRole = $this->createRole('staff');

        $admin = $this->createUser($adminRole, 'admin@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => [],
            ])
            ->assertForbidden();
    }

    public function test_guest_cannot_access_user_permission_management(): void
    {
        $this->getJson('/api/owner/users')
            ->assertUnauthorized();
    }

    public function test_owner_cannot_assign_unknown_permissions(): void
    {
        $ownerRole = $this->createRole('owner');
        $staffRole = $this->createRole('staff');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $staff = $this->createUser($staffRole, 'staff@example.com');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$staff->id}/permissions", [
                'permissions' => ['unknown.permission'],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_owner_cannot_modify_user_with_unknown_role(): void
    {
        $ownerRole = $this->createRole('owner');
        $cashierRole = $this->createRole('cashier');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $cashier = $this->createUser($cashierRole, 'cashier@example.com');

        $this->createPermission('product.view');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/owner/users/{$cashier->id}/permissions", [
                'permissions' => ['product.view'],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_owner_cannot_view_user_with_unknown_role(): void
    {
        $ownerRole = $this->createRole('owner');
        $cashierRole = $this->createRole('cashier');

        $owner = $this->createUser($ownerRole, 'owner@example.com');
        $cashier = $this->createUser($cashierRole, 'cashier@example.com');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/owner/users/{$cashier->id}/permissions")
            ->assertStatus(422);
    }

    public function test_staff_cannot_access_user_permission_management(): void
    {
        $staffRole = $this->createRole('staff');

        $staff = $this->createUser($staffRole, 'staff@example.com');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/owner/users')
            ->assertForbidden();
    }
}