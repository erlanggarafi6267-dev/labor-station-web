<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    /**
     * DECIMAL(14,3):
     * 11 digit sebelum koma dan 3 digit sesudah koma.
     */
    private const MAX_STOCK_MILLI = 99999999999999;

    /**
     * Menampilkan ringkasan dan daftar bahan baku.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive', 'all']),
            ],
            'stock' => [
                'nullable',
                Rule::in(['low', 'normal', 'all']),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
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
            'name' => [
                'required',
                'string',
                'max:150',
                'unique:ingredients,name',
            ],
            'unit' => [
                'required',
                'string',
                'max:30',
            ],
            'current_stock' => [
                'nullable',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (!$this->isValidStockValue($value)) {
                        $fail(
                            'Stok harus memiliki maksimal 11 digit sebelum koma '
                            . 'dan 3 digit sesudah koma.'
                        );
                    }
                },
            ],
            'minimum_stock' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (!$this->isValidStockValue($value)) {
                        $fail(
                            'Minimum stok harus memiliki maksimal 11 digit '
                            . 'sebelum koma dan 3 digit sesudah koma.'
                        );
                    }
                },
            ],
        ]);

        $actorId = (int) $request->user()->id;

        $ingredient = DB::transaction(function () use (
            $validated,
            $actorId
        ) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'inventory.manage'
            );

            $initialStock = $this->toMilliUnits(
                $validated['current_stock'] ?? '0'
            );

            $minimumStock = $this->toMilliUnits(
                $validated['minimum_stock']
            );

            $ingredient = Ingredient::create([
                'name' => trim($validated['name']),
                'unit' => trim($validated['unit']),
                'current_stock' => $this->formatMilliUnits($initialStock),
                'minimum_stock' => $this->formatMilliUnits($minimumStock),
                'is_active' => true,
            ]);

            if ($initialStock > 0) {
                StockMovement::create([
                    'ingredient_id' => $ingredient->id,
                    'user_id' => $actor->id,
                    'type' => 'IN',
                    'quantity' => $this->formatMilliUnits($initialStock),
                    'stock_before' => '0.000',
                    'stock_after' => $this->formatMilliUnits($initialStock),
                    'reason' => 'Stok awal bahan baku',
                    'movement_at' => now(),
                ]);
            }

            $this->writeAuditLog(
                $actor,
                'CREATE_INGREDIENT',
                $ingredient,
                [
                    'after' => $this->ingredientSnapshot($ingredient),
                ]
            );

            return $ingredient;
        });

        return response()->json([
            'message' => 'Bahan baku berhasil ditambahkan.',
            'data' => $ingredient->refresh(),
        ], 201);
    }

    /**
     * Memperbarui informasi bahan baku.
     * Perubahan stok hanya melalui endpoint pergerakan stok.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('ingredients', 'name')->ignore($id),
            ],
            'unit' => [
                'required',
                'string',
                'max:30',
            ],
            'minimum_stock' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (!$this->isValidStockValue($value)) {
                        $fail(
                            'Minimum stok harus memiliki maksimal 11 digit '
                            . 'sebelum koma dan 3 digit sesudah koma.'
                        );
                    }
                },
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        $actorId = (int) $request->user()->id;

        $ingredient = DB::transaction(function () use (
            $validated,
            $actorId,
            $id
        ) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'inventory.manage'
            );

            $ingredient = Ingredient::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $before = $this->ingredientSnapshot($ingredient);

            $ingredient->update([
                'name' => trim($validated['name']),
                'unit' => trim($validated['unit']),
                'minimum_stock' => $this->formatMilliUnits(
                    $this->toMilliUnits($validated['minimum_stock'])
                ),
                'is_active' => $validated['is_active'],
            ]);

            $ingredient->refresh();

            $this->writeAuditLog(
                $actor,
                'UPDATE_INGREDIENT',
                $ingredient,
                [
                    'before' => $before,
                    'after' => $this->ingredientSnapshot($ingredient),
                ]
            );

            return $ingredient;
        });

        return response()->json([
            'message' => 'Informasi bahan baku berhasil diperbarui.',
            'data' => $ingredient,
        ]);
    }

    /**
     * Menonaktifkan bahan baku tanpa menghapus riwayatnya.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;

        DB::transaction(function () use ($actorId, $id) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'inventory.manage'
            );

            $ingredient = Ingredient::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$ingredient->is_active) {
                return;
            }

            $before = $this->ingredientSnapshot($ingredient);

            $ingredient->update([
                'is_active' => false,
            ]);

            $ingredient->refresh();

            $this->writeAuditLog(
                $actor,
                'DEACTIVATE_INGREDIENT',
                $ingredient,
                [
                    'before' => $before,
                    'after' => $this->ingredientSnapshot($ingredient),
                ]
            );
        });

        return response()->json([
            'message' => 'Bahan baku berhasil dinonaktifkan.',
        ]);
    }

    /**
     * Mencatat stok masuk, keluar, atau penyesuaian.
     *
     * IN         = stok masuk.
     * OUT        = stok keluar.
     * ADJUSTMENT = quantity menjadi stok akhir hasil hitung fisik.
     */
    public function move(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'required',
                Rule::in(['IN', 'OUT', 'ADJUSTMENT']),
            ],
            'quantity' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if (!$this->isValidStockValue($value)) {
                        $fail(
                            'Jumlah stok harus memiliki maksimal 11 digit '
                            . 'sebelum koma dan 3 digit sesudah koma.'
                        );

                        return;
                    }

                    if (
                        $request->input('type') !== 'ADJUSTMENT'
                        && $this->toMilliUnits($value) <= 0
                    ) {
                        $fail(
                            'Jumlah stok masuk atau keluar harus lebih besar dari nol.'
                        );
                    }
                },
            ],
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $actorId = (int) $request->user()->id;

        $movement = DB::transaction(function () use (
            $validated,
            $id,
            $actorId
        ) {
            $actor = $this->lockAuthorizedActor(
                $actorId,
                'inventory.stock.manage'
            );

            $ingredient = Ingredient::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$ingredient->is_active) {
                throw ValidationException::withMessages([
                    'ingredient' => [
                        'Bahan baku tidak aktif.',
                    ],
                ]);
            }

            $before = $this->toMilliUnits(
                $ingredient->current_stock
            );

            $quantity = $this->toMilliUnits(
                $validated['quantity']
            );

            if ($validated['type'] === 'IN') {
                if (
                    $quantity >
                    self::MAX_STOCK_MILLI - $before
                ) {
                    throw ValidationException::withMessages([
                        'quantity' => [
                            'Stok akhir melebihi kapasitas database.',
                        ],
                    ]);
                }

                $after = $before + $quantity;
            } elseif ($validated['type'] === 'OUT') {
                if ($quantity > $before) {
                    throw ValidationException::withMessages([
                        'quantity' => [
                            'Stok tidak mencukupi untuk pengeluaran ini.',
                        ],
                    ]);
                }

                $after = $before - $quantity;
            } else {
                // Quantity pada ADJUSTMENT adalah stok akhir.
                $after = $quantity;
            }

            if (
                $after < 0 ||
                $after > self::MAX_STOCK_MILLI
            ) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        'Hasil pergerakan stok berada di luar kapasitas database.',
                    ],
                ]);
            }

            $beforeFormatted = $this->formatMilliUnits($before);
            $afterFormatted = $this->formatMilliUnits($after);
            $quantityFormatted = $this->formatMilliUnits($quantity);

            $ingredient->update([
                'current_stock' => $afterFormatted,
            ]);

            return StockMovement::create([
                'ingredient_id' => $ingredient->id,
                'user_id' => $actor->id,
                'type' => $validated['type'],
                'quantity' => $quantityFormatted,
                'stock_before' => $beforeFormatted,
                'stock_after' => $afterFormatted,
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
     * Menampilkan riwayat pergerakan stok.
     */
    public function movements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => [
                'nullable',
                'integer',
                'exists:ingredients,id',
            ],
            'type' => [
                'nullable',
                Rule::in(['IN', 'OUT', 'ADJUSTMENT']),
            ],
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],
            'from' => [
                'nullable',
                'date',
            ],
            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
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
                fn ($query) => $query->where(
                    'type',
                    $validated['type']
                )
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
     * Memastikan angka sesuai kapasitas DECIMAL(14,3).
     */
    private function isValidStockValue(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $value = trim((string) $value);

        if (!preg_match('/^\d{1,11}(?:\.\d{1,3})?$/D', $value)) {
            return false;
        }

        return $this->toMilliUnits($value) <= self::MAX_STOCK_MILLI;
    }

    /**
     * Mengubah angka desimal menjadi satuan 0,001.
     *
     * Contoh:
     * 12.345 menjadi 12345.
     */
    private function toMilliUnits(mixed $value): int
    {
        $value = trim((string) $value);

        if (!preg_match('/^(\d{1,11})(?:\.(\d{1,3}))?$/D', $value, $matches)) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'Format angka stok tidak valid.',
                ],
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ($whole * 1000) + (int) $fraction;
    }

    /**
     * Mengubah satuan 0,001 menjadi format desimal database.
     */
    private function formatMilliUnits(int $value): string
    {
        $whole = intdiv($value, 1000);
        $fraction = $value % 1000;

        return $whole . '.' . str_pad(
            (string) $fraction,
            3,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Snapshot informasi bahan untuk audit log.
     */
    private function ingredientSnapshot(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'unit' => $ingredient->unit,
            'current_stock' => $ingredient->current_stock,
            'minimum_stock' => $ingredient->minimum_stock,
            'is_active' => $ingredient->is_active,
        ];
    }

    /**
     * Mencatat aktivitas ke audit log.
     */
    private function writeAuditLog(
        User $actor,
        string $action,
        Ingredient $ingredient,
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'INGREDIENT',
            'entity_id' => $ingredient->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}