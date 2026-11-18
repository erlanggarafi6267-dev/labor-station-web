<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Menampilkan daftar kategori.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $categories = Category::query()
            ->withCount('products')
            ->when(
                !empty($validated['search']),
                function ($query) use ($validated) {
                    $search = $validated['search'];

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('slug', 'ILIKE', "%{$search}%");
                    });
                }
            )
            ->when(
                ($validated['status'] ?? 'all') !== 'all',
                function ($query) use ($validated) {
                    $query->where(
                        'is_active',
                        ($validated['status'] ?? 'all') === 'active'
                    );
                }
            )
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Daftar kategori berhasil diambil.',
            'data' => $categories,
        ]);
    }

    /**
     * Menampilkan detail kategori.
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::withCount('products')
            ->findOrFail($id);

        return response()->json([
            'message' => 'Detail kategori berhasil diambil.',
            'data' => $category,
        ]);
    }

    /**
     * Menambahkan kategori baru.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'unique:categories,name',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $category = DB::transaction(function () use ($validated, $request) {
            $category = Category::create([
                'name' => $validated['name'],
                'slug' => $this->generateCategorySlug(
                    $validated['name']
                ),
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->writeAuditLog(
                $request,
                'CREATE_CATEGORY',
                $category,
                [
                    'name' => $category->name,
                    'slug' => $category->slug,
                ]
            );

            return $category;
        });

        return response()->json([
            'message' => 'Kategori berhasil ditambahkan.',
            'data' => $category,
        ], 201);
    }

    /**
     * Memperbarui kategori.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')
                    ->ignore($category->id),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $updatedCategory = DB::transaction(
            function () use ($category, $validated, $request) {
                $before = $category->toArray();

                $categoryData = $validated;

                if (isset($categoryData['name'])) {
                    $categoryData['slug'] =
                        $this->generateCategorySlug(
                            $categoryData['name'],
                            $category->id
                        );
                }

                $category->update($categoryData);

                $this->writeAuditLog(
                    $request,
                    'UPDATE_CATEGORY',
                    $category,
                    [
                        'before' => $before,
                        'after' => $category->fresh()->toArray(),
                    ]
                );

                return $category->fresh()
                    ->loadCount('products');
            }
        );

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data' => $updatedCategory,
        ]);
    }

    /**
     * Menghapus kategori jika belum memiliki relasi produk.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        // Produk yang sudah soft delete tetap memiliki relasi
        // dengan kategori dan harus diperhitungkan.
        $hasProducts = $category->products()
            ->withTrashed()
            ->exists();

        if ($hasProducts) {
            return response()->json([
                'message' => 'Kategori tidak dapat dihapus karena masih memiliki produk. Nonaktifkan kategori jika sudah tidak digunakan.',
            ], 422);
        }

        DB::transaction(function () use ($category, $request) {
            $categoryData = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ];

            $this->writeAuditLog(
                $request,
                'DELETE_CATEGORY',
                $category,
                $categoryData
            );

            $category->delete();
        });

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }

    /**
     * Membuat slug kategori yang unik.
     */
    private function generateCategorySlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'kategori';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Category::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreId,
                    fn ($query) => $query->where(
                        'id',
                        '!=',
                        $ignoreId
                    )
                )
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Mencatat aktivitas ke audit log.
     */
    private function writeAuditLog(
        Request $request,
        string $action,
        Category $category,
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'CATEGORY',
            'entity_id' => $category->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}