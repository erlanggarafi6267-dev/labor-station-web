<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    /**
     * Menampilkan daftar pengeluaran.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => [
                'nullable',
                'integer',
                'exists:expense_categories,id',
            ],
            'date_from' => [
                'nullable',
                'date',
            ],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $expenses = Expense::query()
            ->with(['category', 'user'])
            ->when(
                !empty($validated['category_id']),
                fn ($query) => $query->where(
                    'expense_category_id',
                    $validated['category_id']
                )
            )
            ->when(
                !empty($validated['date_from']),
                fn ($query) => $query->whereDate(
                    'expense_date',
                    '>=',
                    $validated['date_from']
                )
            )
            ->when(
                !empty($validated['date_to']),
                fn ($query) => $query->whereDate(
                    'expense_date',
                    '<=',
                    $validated['date_to']
                )
            )
            ->when(
                !empty($validated['search']),
                function ($query) use ($validated) {
                    $search = $validated['search'];

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'description',
                                'ILIKE',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'notes',
                                'ILIKE',
                                "%{$search}%"
                            );
                    });
                }
            )
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'message' => 'Daftar pengeluaran berhasil diambil.',
            'data' => $expenses,
        ]);
    }

    /**
     * Menambahkan pengeluaran baru.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => [
                'required',
                'integer',
                'exists:expense_categories,id',
            ],
            'description' => [
                'required',
                'string',
                'max:255',
            ],
            'amount' => [
                'required',
                'integer',
                'min:1',
                'max:' . PHP_INT_MAX,
            ],
            'expense_date' => [
                'required',
                'date',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $actorId = (int) $request->user()->id;

        $expense = DB::transaction(function () use (
            $validated,
            $actorId
        ) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'expense.create'
            );

            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail($validated['expense_category_id']);

            if (!$category->is_active) {
                throw ValidationException::withMessages([
                    'expense_category_id' => [
                        'Kategori pengeluaran tidak aktif.',
                    ],
                ]);
            }

            $expense = Expense::create([
                ...$validated,
                'user_id' => $actor->id,
            ]);

            $this->writeAuditLog(
                $actor,
                'CREATE_EXPENSE',
                $expense,
                [
                    'after' => $this->expenseSnapshot($expense),
                ]
            );

            return $expense;
        });

        return response()->json([
            'message' => 'Pengeluaran berhasil ditambahkan.',
            'data' => $expense->load(['category', 'user']),
        ], 201);
    }

    /**
     * Menampilkan detail pengeluaran.
     */
    public function show(string $id): JsonResponse
    {
        $expense = Expense::with([
            'category',
            'user',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail pengeluaran berhasil diambil.',
            'data' => $expense,
        ]);
    }

    /**
     * Memperbarui pengeluaran.
     */
    public function update(
        Request $request,
        string $id
    ): JsonResponse {
        $validated = $request->validate([
            'expense_category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:expense_categories,id',
            ],
            'description' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'amount' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:' . PHP_INT_MAX,
            ],
            'expense_date' => [
                'sometimes',
                'required',
                'date',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        if (empty($validated)) {
            return response()->json([
                'message' => 'Tidak ada perubahan pengeluaran yang dikirim.',
            ], 422);
        }

        $actorId = (int) $request->user()->id;

        $expense = DB::transaction(function () use (
            $validated,
            $actorId,
            $id
        ) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'expense.update'
            );

            $expense = Expense::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (isset($validated['expense_category_id'])) {
                $category = ExpenseCategory::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $validated['expense_category_id']
                    );

                if (!$category->is_active) {
                    throw ValidationException::withMessages([
                        'expense_category_id' => [
                            'Kategori pengeluaran tidak aktif.',
                        ],
                    ]);
                }
            }

            $before = $this->expenseSnapshot($expense);

            $expense->update($validated);

            $expense->refresh();

            $after = $this->expenseSnapshot($expense);

            $this->writeAuditLog(
                $actor,
                'UPDATE_EXPENSE',
                $expense,
                [
                    'before' => $before,
                    'after' => $after,
                ]
            );

            return $expense;
        });

        return response()->json([
            'message' => 'Pengeluaran berhasil diperbarui.',
            'data' => $expense->load(['category', 'user']),
        ]);
    }

    /**
     * Menghapus pengeluaran.
     */
    public function destroy(
        Request $request,
        string $id
    ): JsonResponse {
        $actorId = (int) $request->user()->id;

        DB::transaction(function () use ($actorId, $id) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'expense.delete'
            );

            $expense = Expense::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $before = $this->expenseSnapshot($expense);

            $this->writeAuditLog(
                $actor,
                'DELETE_EXPENSE',
                $expense,
                [
                    'before' => $before,
                ]
            );

            $expense->delete();
        });

        return response()->json([
            'message' => 'Pengeluaran berhasil dihapus.',
        ]);
    }

    /**
     * Mengunci actor dan memeriksa ulang permission di dalam transaksi.
     */
    private function lockAuthorizedActor(
        int $actorId,
        string $permission
    ): User {
        $actor = User::query()
            ->with('role')
            ->lockForUpdate()
            ->findOrFail($actorId);

        if (!$actor->hasPermission($permission)) {
            throw new AuthorizationException(
                'Kamu tidak memiliki izin untuk melakukan tindakan ini.'
            );
        }

        return $actor;
    }

    /**
     * Membuat snapshot data pengeluaran untuk audit log.
     */
    private function expenseSnapshot(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_category_id' => $expense->expense_category_id,
            'user_id' => $expense->user_id,
            'description' => $expense->description,
            'amount' => (int) $expense->amount,
            'expense_date' => $expense->expense_date->toDateString(),
            'notes' => $expense->notes,
        ];
    }

    /**
     * Mencatat aktivitas pengeluaran.
     */
    private function writeAuditLog(
        User $actor,
        string $action,
        Expense $expense,
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'EXPENSE',
            'entity_id' => $expense->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}