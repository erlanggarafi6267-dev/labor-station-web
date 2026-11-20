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
        $email = trim((string) env('OWNER_EMAIL'));
        $name = trim((string) env('OWNER_NAME'));
        $password = (string) env('OWNER_PASSWORD');

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

        // Jangan mengubah password owner yang sudah ada setiap kali seeder dijalankan.
        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role_id' => $ownerRole->id,
                'password' => Hash::make($password),
            ]
        );
    }
}
