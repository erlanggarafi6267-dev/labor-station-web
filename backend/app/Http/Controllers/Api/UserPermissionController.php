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
     * Daftar pengguna yang dapat diatur izinnya.
     * Hanya Admin dan Staff yang ditampilkan.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with([
                'role:id,name,slug',
                'permissions:id,name,slug',
            ])
            ->whereHas('role', function ($query) {
                $query->whereIn('slug', ['admin', 'staff']);
            })
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->slug,
                    'permissions' => $user->permissions->pluck('slug'),
                ];
            });

        return response()->json([
            'message' => 'Daftar pengguna berhasil diambil.',
            'data' => $users,
        ]);
    }

    /**
     * Detail izin seorang pengguna.
     * Target hanya boleh Admin atau Staff.
     */
    public function show(int $userId): JsonResponse
    {
        $user = User::with([
            'role:id,name,slug',
            'permissions:id,name,slug',
        ])->findOrFail($userId);

        if (
            !$user->role ||
            !in_array($user->role->slug, ['admin', 'staff'], true)
        ) {
            return response()->json([
                'message' => 'Permission hanya dapat diatur untuk akun Admin atau Staff.',
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

        $user = User::with('role')->findOrFail($userId);

        if (
            !$user->role ||
            !in_array($user->role->slug, ['admin', 'staff'], true)
        ) {
            return response()->json([
                'message' => 'Permission hanya dapat diatur untuk akun Admin atau Staff.',
            ], 422);
        }

        $permissionIds = Permission::query()
            ->whereIn('slug', $validated['permissions'])
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($user, $permissionIds, $request) {
            $user->permissions()->syncWithPivotValues(
                $permissionIds,
                ['granted_by' => $request->user()->id]
            );
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