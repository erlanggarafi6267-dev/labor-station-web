<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OwnerSeederTest extends TestCase
{
    use RefreshDatabase;

    private function configureOwner(
        string $name = 'Owner LABOR_STATION',
        string $email = 'owner@example.com',
        string $password = 'StrongOwnerPassword2026!'
    ): void {
        config([
            'owner.name' => $name,
            'owner.email' => $email,
            'owner.password' => $password,
        ]);
    }

    public function test_owner_account_is_created_with_valid_configuration(): void
    {
        Role::create([
            'name' => 'Owner',
            'slug' => 'owner',
        ]);

        $this->configureOwner();

        (new OwnerSeeder())->run();

        $this->assertDatabaseHas('users', [
            'name' => 'Owner LABOR_STATION',
            'email' => 'owner@example.com',
        ]);

        $owner = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertSame('owner', $owner->role->slug);

        $this->assertTrue(
            Hash::check('StrongOwnerPassword2026!', $owner->password)
        );
    }

    public function test_existing_owner_password_is_not_changed(): void
    {
        $role = Role::create([
            'name' => 'Owner',
            'slug' => 'owner',
        ]);

        $existingPassword = 'ExistingOwnerPassword2026!';

        $owner = User::create([
            'name' => 'Existing Owner',
            'email' => 'owner@example.com',
            'password' => $existingPassword,
            'role_id' => $role->id,
        ]);

        $this->configureOwner(
            password: 'AnotherStrongPassword2026!'
        );

        (new OwnerSeeder())->run();

        $owner->refresh();

        $this->assertSame('Existing Owner', $owner->name);

        $this->assertTrue(
            Hash::check($existingPassword, $owner->password)
        );

        $this->assertFalse(
            Hash::check('AnotherStrongPassword2026!', $owner->password)
        );

        $this->assertDatabaseCount('users', 1);
    }

    public function test_seeder_rejects_invalid_email(): void
    {
        Role::create([
            'name' => 'Owner',
            'slug' => 'owner',
        ]);

        $this->configureOwner(
            email: 'invalid-email'
        );

        $this->expectException(RuntimeException::class);

        (new OwnerSeeder())->run();
    }

    public function test_seeder_rejects_password_shorter_than_twelve_characters(): void
    {
        Role::create([
            'name' => 'Owner',
            'slug' => 'owner',
        ]);

        $this->configureOwner(
            password: 'short123'
        );

        $this->expectException(RuntimeException::class);

        (new OwnerSeeder())->run();
    }


    public function test_seeder_rejects_email_already_used_by_non_owner(): void
    {
    $staffRole = Role::create([
        'name' => 'Staff',
        'slug' => 'staff',
    ]);

    $staff = User::create([
        'name' => 'Existing Staff',
        'email' => 'owner@example.com',
        'password' => 'ExistingStaffPassword2026!',
        'role_id' => $staffRole->id,
    ]);

    Role::create([
        'name' => 'Owner',
        'slug' => 'owner',
    ]);

    $this->configureOwner();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
        'OWNER_EMAIL sudah digunakan oleh akun yang bukan Owner.'
    );

    try {
        (new OwnerSeeder())->run();
    } finally {
        $staff->refresh();

        $this->assertSame('staff', $staff->role->slug);
        $this->assertSame('Existing Staff', $staff->name);

        $this->assertTrue(
            Hash::check(
                'ExistingStaffPassword2026!',
                $staff->password
            )
        );

        $this->assertDatabaseCount('users', 1);
    }
}


    public function test_seeder_fails_when_owner_role_does_not_exist(): void
    {
        $this->configureOwner();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        (new OwnerSeeder())->run();
    }
}