<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'email', 'password', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Relasi user dengan role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Relasi user dengan permissions.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions'
        )
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    /**
     * Memeriksa hak akses pengguna.
     */
    public function hasPermission(string $permission): bool
    {
        $role = $this->role;

        // User tanpa role tidak memiliki akses.
        if (!$role) {
            return false;
        }

        // Owner memiliki akses penuh.
        if ($role->slug === 'owner') {
            return true;
        }

        // Hanya Admin dan Staff yang menggunakan permission.
        if (!in_array($role->slug, ['admin', 'staff'], true)) {
            return false;
        }

        // Admin dan Staff wajib memiliki permission terkait.
        return $this->permissions()
            ->where('slug', $permission)
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}