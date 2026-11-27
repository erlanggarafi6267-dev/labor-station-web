<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $slug): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function createUser(?Role $role, string $email): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role?->id,
        ]);
    }

    private function createPermission(): Permission
    {
        return Permission::create([
            'name' => 'Melihat Produk',
            'slug' => 'product.view',
        ]);
    }

    public function test_user_without_role_cannot_use_direct_permission(): void
    {
        $user = $this->createUser(null, 'norole@example.com');
        $permission = $this->createPermission();

        $user->permissions()->attach($permission->id);

        $this->assertFalse(
            $user->fresh()->hasPermission('product.view')
        );
    }

    public function test_unknown_role_cannot_use_direct_permission(): void
    {
        $role = $this->createRole('cashier');
        $user = $this->createUser($role, 'cashier@example.com');
        $permission = $this->createPermission();

        $user->permissions()->attach($permission->id);

        $this->assertFalse(
            $user->fresh()->hasPermission('product.view')
        );
    }

    public function test_admin_requires_assigned_permission(): void
    {
        $role = $this->createRole('admin');
        $user = $this->createUser($role, 'admin@example.com');

        $this->createPermission();

        $this->assertFalse(
            $user->hasPermission('product.view')
        );

        $user->permissions()->attach(
            Permission::where('slug', 'product.view')->first()->id
        );

        $this->assertTrue(
            $user->fresh()->hasPermission('product.view')
        );
    }

    public function test_staff_requires_assigned_permission(): void
    {
        $role = $this->createRole('staff');
        $user = $this->createUser($role, 'staff@example.com');

        $this->createPermission();

        $this->assertFalse(
            $user->hasPermission('product.view')
        );

        $user->permissions()->attach(
            Permission::where('slug', 'product.view')->first()->id
        );

        $this->assertTrue(
            $user->fresh()->hasPermission('product.view')
        );
    }

    public function test_owner_has_full_permission_access(): void
    {
        $role = $this->createRole('owner');
        $user = $this->createUser($role, 'owner@example.com');

        $this->assertTrue(
            $user->hasPermission('permission.not.registered')
        );
    }
}