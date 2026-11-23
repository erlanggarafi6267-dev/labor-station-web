<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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

        $requestedPermissions = Permission::query()
            ->whereIn('id', $permissionIds)
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        $actorId = $request->user()->id;

        $user = DB::transaction(function () use (
            $userId,
            $permissionIds,
            $requestedPermissions,
            $actorId
        ) {
            $user = User::query()
                ->with('role')
                ->lockForUpdate()
                ->findOrFail($userId);

            if (!$this->hasManageableRole($user->role?->slug)) {
                abort(422, 'Pengguna ini tidak dapat diatur permission-nya.');
            }

            /*
             * Simpan kondisi permission sebelum perubahan.
             */
            $beforePermissions = $user->permissions()
                ->orderBy('permissions.slug')
                ->pluck('permissions.slug')
                ->all();

            /*
             * Bandingkan kondisi sebelum dan sesudah.
             */
            $grantedPermissions = array_values(
                array_diff(
                    $requestedPermissions,
                    $beforePermissions
                )
            );

            $revokedPermissions = array_values(
                array_diff(
                    $beforePermissions,
                    $requestedPermissions
                )
            );

            /*
             * Sinkronisasi permission pengguna.
             */
            $user->permissions()->syncWithPivotValues(
                $permissionIds,
                ['granted_by' => $actorId]
            );

            /*
             * Catat audit hanya jika benar-benar ada perubahan.
             * Audit berada dalam transaksi yang sama dengan sync.
             */
            if (
                !empty($grantedPermissions)
                || !empty($revokedPermissions)
            ) {
                $action = empty($requestedPermissions)
                    ? 'REVOKE_USER_PERMISSIONS'
                    : 'UPDATE_USER_PERMISSIONS';

                AuditLog::create([
                    'user_id' => $actorId,
                    'action' => $action,
                    'entity_type' => 'USER',
                    'entity_id' => $user->id,
                    'metadata' => [
                        'target_user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'role' => $user->role->slug,
                        ],
                        'permissions' => [
                            'before' => $beforePermissions,
                            'after' => $requestedPermissions,
                            'granted' => $grantedPermissions,
                            'revoked' => $revokedPermissions,
                        ],
                    ],
                    'created_at' => now(),
                ]);
            }

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