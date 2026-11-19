<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    /**
     * Ringkasan dan daftar bahan baku.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'stock' => ['nullable', Rule::in(['low', 'normal', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Ingredient::query()
            ->when(
                !empty($validated['search']),
                fn ($builder) => $builder->where(
                    'name',
                    'ILIKE',
                    '%' . $validated['search'] . '%'
                )
            )
            ->when(
                ($validated['status'] ?? 'active') !== 'all',
                fn ($builder) => $builder->where(
                    'is_active',
                    ($validated['status'] ?? 'active') === 'active'
                )
            )
            ->when(
                ($validated['stock'] ?? 'all') === 'low',
                fn ($builder) => $builder->whereColumn(
                    'current_stock',
                    '<=',
                    'minimum_stock'
                )
            )
            ->when(
                ($validated['stock'] ?? 'all') === 'normal',
                fn ($builder) => $builder->whereColumn(
                    'current_stock',
                    '>',
                    'minimum_stock'
                )
            )
            ->orderBy('name');

        $ingredients = $query
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $summary = [
            'total' => Ingredient::where('is_active', true)->count(),
            'low_stock' => Ingredient::where('is_active', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->count(),
            'out_of_stock' => Ingredient::where('is_active', true)
                ->where('current_stock', '<=', 0)
                ->count(),
            'inactive' => Ingredient::where('is_active', false)->count(),
        ];

        return response()->json([
            'message' => 'Data inventaris berhasil diambil.',
            'summary' => $summary,
            'data' => $ingredients,
        ]);
    }

    /**
     * Menambahkan bahan baku.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:ingredients,name'],
            'unit' => ['required', 'string', 'max:30'],
            'current_stock' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ]);

        $ingredient = DB::transaction(function () use ($validated, $request) {
            $ingredient = Ingredient::create([
                'name' => trim($validated['name']),
                'unit' => trim($validated['unit']),
                'current_stock' => $validated['current_stock'] ?? 0,
                'minimum_stock' => $validated['minimum_stock'],
                'is_active' => true,
            ]);

            $initialStock = (float) ($validated['current_stock'] ?? 0);

            if ($initialStock > 0) {
                StockMovement::create([
                    'ingredient_id' => $ingredient->id,
                    'user_id' => $request->user()->id,
                    'type' => 'IN',
                    'quantity' => $initialStock,
                    'stock_before' => 0,
                    'stock_after' => $initialStock,
                    'reason' => 'Stok awal bahan baku',
                    'movement_at' => now(),
                ]);
            }

            return $ingredient;
        });

        return response()->json([
            'message' => 'Bahan baku berhasil ditambahkan.',
            'data' => $ingredient->refresh(),
        ], 201);
    }

    /**
     * Memperbarui informasi bahan baku.
     * Perubahan stok hanya boleh melalui endpoint pergerakan stok.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ingredient = Ingredient::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('ingredients', 'name')->ignore($ingredient->id),
            ],
            'unit' => ['required', 'string', 'max:30'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $ingredient->update([
            'name' => trim($validated['name']),
            'unit' => trim($validated['unit']),
            'minimum_stock' => $validated['minimum_stock'],
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'message' => 'Informasi bahan baku berhasil diperbarui.',
            'data' => $ingredient->refresh(),
        ]);
    }

    /**
     * Menonaktifkan bahan baku.
     * Bahan tidak dihapus agar riwayat stok tetap aman.
     */
    public function destroy(int $id): JsonResponse
    {
        $ingredient = Ingredient::findOrFail($id);

        $ingredient->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Bahan baku berhasil dinonaktifkan.',
        ]);
    }

    /**
     * Mencatat stok masuk, keluar, atau penyesuaian.
     *
     * type:
     * IN        = stok masuk
     * OUT       = stok keluar
     * ADJUSTMENT = penyesuaian ke jumlah stok akhir
     */
    public function move(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['IN', 'OUT', 'ADJUSTMENT'])],
            'quantity' => [
                'required',
                'numeric',
                'gt:0',
                'max:99999999999',
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $movement = DB::transaction(function () use (
            $validated,
            $id,
            $request
        ) {
            $ingredient = Ingredient::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$ingredient->is_active) {
                abort(422, 'Bahan baku tidak aktif.');
            }

            $before = (float) $ingredient->current_stock;
            $quantity = (float) $validated['quantity'];

            if ($validated['type'] === 'IN') {
                $after = $before + $quantity;
            } elseif ($validated['type'] === 'OUT') {
                if ($quantity > $before) {
                    abort(422, 'Stok tidak mencukupi untuk pengeluaran ini.');
                }

                $after = $before - $quantity;
            } else {
                // Untuk ADJUSTMENT, quantity adalah stok akhir hasil hitung fisik.
                $after = $quantity;
            }

            $ingredient->update([
                'current_stock' => $after,
            ]);

            return StockMovement::create([
                'ingredient_id' => $ingredient->id,
                'user_id' => $request->user()->id,
                'type' => $validated['type'],
                'quantity' => $quantity,
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => trim($validated['reason']),
                'movement_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Pergerakan stok berhasil dicatat.',
            'data' => $movement->load([
                'ingredient',
                'user:id,name,email',
            ]),
        ], 201);
    }

    /**
     * Riwayat pergerakan stok.
     */
    public function movements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'type' => ['nullable', Rule::in(['IN', 'OUT', 'ADJUSTMENT'])],
            'search' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->with([
                'ingredient:id,name,unit',
                'user:id,name,email',
            ])
            ->when(
                !empty($validated['ingredient_id']),
                fn ($query) => $query->where(
                    'ingredient_id',
                    $validated['ingredient_id']
                )
            )
            ->when(
                !empty($validated['type']),
                fn ($query) => $query->where('type', $validated['type'])
            )
            ->when(
                !empty($validated['search']),
                function ($query) use ($validated) {
                    $search = $validated['search'];

                    $query->whereHas(
                        'ingredient',
                        fn ($ingredientQuery) => $ingredientQuery->where(
                            'name',
                            'ILIKE',
                            '%' . $search . '%'
                        )
                    );
                }
            )
            ->when(
                !empty($validated['from']),
                fn ($query) => $query->whereDate(
                    'movement_at',
                    '>=',
                    $validated['from']
                )
            )
            ->when(
                !empty($validated['to']),
                fn ($query) => $query->whereDate(
                    'movement_at',
                    '<=',
                    $validated['to']
                )
            )
            ->latest('movement_at')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Riwayat pergerakan stok berhasil diambil.',
            'data' => $movements,
        ]);
    }
}