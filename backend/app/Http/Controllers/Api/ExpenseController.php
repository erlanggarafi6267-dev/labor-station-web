<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $expenses = Expense::query()
            ->with(['category', 'user'])
            ->when(
                !empty($validated['category_id']),
                fn ($query) => $query->where('expense_category_id', $validated['category_id'])
            )
            ->when(
                !empty($validated['date_from']),
                fn ($query) => $query->whereDate('expense_date', '>=', $validated['date_from'])
            )
            ->when(
                !empty($validated['date_to']),
                fn ($query) => $query->whereDate('expense_date', '<=', $validated['date_to'])
            )
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('description', 'ILIKE', "%{$search}%")
                        ->orWhere('notes', 'ILIKE', "%{$search}%");
                });
            })
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'message' => 'Daftar pengeluaran berhasil diambil.',
            'data' => $expenses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => [
                'required',
                'integer',
                'exists:expense_categories,id',
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $category = ExpenseCategory::findOrFail(
            $validated['expense_category_id']
        );

        if (!$category->is_active) {
            return response()->json([
                'message' => 'Kategori pengeluaran tidak aktif.',
            ], 422);
        }

        $expense = Expense::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Pengeluaran berhasil ditambahkan.',
            'data' => $expense->load(['category', 'user']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $expense = Expense::with(['category', 'user'])->findOrFail($id);

        return response()->json([
            'message' => 'Detail pengeluaran berhasil diambil.',
            'data' => $expense,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        $validated = $request->validate([
            'expense_category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:expense_categories,id',
            ],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'expense_date' => ['sometimes', 'required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if (isset($validated['expense_category_id'])) {
            $category = ExpenseCategory::findOrFail(
                $validated['expense_category_id']
            );

            if (!$category->is_active) {
                return response()->json([
                    'message' => 'Kategori pengeluaran tidak aktif.',
                ], 422);
            }
        }

        $expense->update($validated);

        return response()->json([
            'message' => 'Pengeluaran berhasil diperbarui.',
            'data' => $expense->fresh()->load(['category', 'user']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        $expense->delete();

        return response()->json([
            'message' => 'Pengeluaran berhasil dihapus.',
        ]);
    }
}