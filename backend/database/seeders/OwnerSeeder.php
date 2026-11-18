<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $ownerRole = Role::where('slug', 'owner')->firstOrFail();

        User::updateOrCreate(
            ['email' => 'owner@laborstation.local'],
            [
                'name' => 'Owner LABOR_STATION',
                'role_id' => $ownerRole->id,
                'password' => Hash::make('LaborStation_Tulungagung'),
            ]
        );
    }
}