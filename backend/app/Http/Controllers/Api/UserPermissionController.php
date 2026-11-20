<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPermissionController extends Controller
{
    /**
     * Role yang boleh diatur permission-nya.
     */
    private const MANAGEABLE_ROLES = ['admin', 'staff'];

    /**
     * Memastikan user memiliki role yang boleh dikelola.
     */
    private function hasManageableRole(?string $roleSlug): bool
    {
        return in_array(
            $roleSlug,
            self::MANAGEABLE_ROLES,
            true
        );
    }

    /**
     * Daftar pengguna yang dapat diatur izinnya.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with([
                'role:id,name,slug',
                'permissions:id,name,slug',
            ])
            ->whereHas('role', function ($query) {
                $query->whereIn('slug', self::MANAGEABLE_ROLES);
            })
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->slug,
                    'permissions' => $user->permissions->pluck('slug'),
                ];
            });

        return response()->json([
            'message' => 'Daftar pengguna berhasil diambil.',
            'data' => $users,
        ]);
    }

    /**
     * Detail permission seorang pengguna.
     */
    public function show(int $userId): JsonResponse
    {
        $user = User::with([
            'role:id,name,slug',
            'permissions:id,name,slug',
        ])->findOrFail($userId);

        if (!$this->hasManageableRole($user->role?->slug)) {
            return response()->json([
                'message' => 'Pengguna ini tidak dapat diatur permission-nya.',
            ], 422);
        }

        return response()->json([
            'message' => 'Permission pengguna berhasil diambil.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->slug,
                'permissions' => $user->permissions,
            ],
        ]);
    }

    /**
     * Mengganti seluruh permission pengguna.
     *
     * Array kosong diperbolehkan untuk mencabut seluruh permission.
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                'exists:permissions,slug',
            ],
        ]);

        $permissionIds = Permission::query()
            ->whereIn('slug', $validated['permissions'])
            ->pluck('id')
            ->all();

        $actorId = $request->user()->id;

        $user = DB::transaction(function () use (
            $userId,
            $permissionIds,
            $actorId
        ) {
            $user = User::query()
                ->with('role')
                ->lockForUpdate()
                ->findOrFail($userId);

            if (!$this->hasManageableRole($user->role?->slug)) {
                abort(422, 'Pengguna ini tidak dapat diatur permission-nya.');
            }

            $user->permissions()->syncWithPivotValues(
                $permissionIds,
                ['granted_by' => $actorId]
            );

            return $user;
        });

        $user->load('permissions:id,name,slug');

        return response()->json([
            'message' => 'Permission pengguna berhasil diperbarui.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->slug,
                'permissions' => $user->permissions,
            ],
        ]);
    }
}