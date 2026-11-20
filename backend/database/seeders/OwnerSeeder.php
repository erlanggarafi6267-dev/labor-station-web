<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('owner.email'));
        $name = trim((string) config('owner.name'));
        $password = (string) config('owner.password');

        if (
            $email === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            $name === '' ||
            strlen($password) < 12
        ) {
            throw new RuntimeException(
                'OWNER_EMAIL, OWNER_NAME, dan OWNER_PASSWORD wajib diatur. ' .
                'OWNER_EMAIL harus valid dan OWNER_PASSWORD minimal 12 karakter.'
            );
        }

        $ownerRole = Role::where('slug', 'owner')->firstOrFail();

        $owner = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role_id' => $ownerRole->id,
                'password' => Hash::make($password),
            ]
        );

        // Email yang sudah digunakan role lain tidak boleh menjadi akun Owner.
        if ((int) $owner->role_id !== (int) $ownerRole->id) {
            throw new RuntimeException(
                'OWNER_EMAIL sudah digunakan oleh akun yang bukan Owner. ' .
                'Gunakan email khusus untuk akun Owner.'
            );
        }
    }
}