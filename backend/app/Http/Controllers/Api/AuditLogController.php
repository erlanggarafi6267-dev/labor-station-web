<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;


class AuditLogController extends Controller
{
    /**
     * Menampilkan riwayat aktivitas.
     * Hanya dapat diakses oleh Owner melalui middleware.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'exists:roles,slug'],
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with([
                'user:id,name,email,role_id',
                'user.role:id,name,slug',
            ])

            ->when($request->filled('search'), function ($query) use ($filters) {
                $search = $filters['search'];

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('action', 'ILIKE', "%{$search}%")
                        ->orWhere('entity_type', 'ILIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery
                                ->where('name', 'ILIKE', "%{$search}%")
                                ->orWhere('email', 'ILIKE', "%{$search}%");
                        });
                });
            })

            ->when(
                isset($filters['user_id']),
                fn ($query) => $query->where('user_id', $filters['user_id'])
            )

            ->when(
                isset($filters['role']),
                fn ($query) => $query->whereHas(
                    'user.role',
                    fn ($roleQuery) => $roleQuery->where('slug', $filters['role'])
                )
            )

            ->when(
                isset($filters['action']),
                fn ($query) => $query->where('action', $filters['action'])
            )

            ->when(
                isset($filters['entity_type']),
                fn ($query) => $query->where(
                    'entity_type',
                    $filters['entity_type']
                )
            )

            ->when(
                isset($filters['date_from']),
                fn ($query) => $query->whereDate(
                    'created_at',
                    '>=',
                    $filters['date_from']
                )
            )

            ->when(
                isset($filters['date_to']),
                fn ($query) => $query->whereDate(
                    'created_at',
                    '<=',
                    $filters['date_to']
                )
            )

            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Riwayat aktivitas berhasil diambil.',
            'data' => $logs,
        ]);
    }

    /**
     * Menampilkan detail satu aktivitas.
     */
    public function show(int $id)
    {
        $log = AuditLog::with([
            'user:id,name,email,role_id',
            'user.role:id,name,slug',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail aktivitas berhasil diambil.',
            'data' => $log,
        ]);
    }
}