<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductManagementController extends Controller
{
    /**
     * Menampilkan daftar produk.
     * Produk soft deleted tetap tersedia pada filter inactive/all.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'active';

        $query = Product::query()
            ->when(
                $status !== 'active',
                fn ($builder) => $builder->withTrashed()
            )
            ->with([
                'category:id,name,slug',
                'prices:id,product_id,sales_channel,price,is_active',
            ]);

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('sku', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($validated['category_id'])) {
            $query->where(
                'category_id',
                $validated['category_id']
            );
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $products = $query
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Daftar produk berhasil diambil.',
            'data' => $products,
        ]);
    }

    /**
     * Detail produk.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with([
            'category',
            'prices',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail produk berhasil diambil.',
            'data' => $product,
        ]);
    }

    /**
     * Membuat produk baru.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],
            'name' => ['required', 'string', 'max:150'],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                'unique:products,sku',
            ],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],

            'temperature_options' => ['nullable', 'array'],
            'temperature_options.*' => [
                'string',
                'distinct',
                Rule::in(['HOT', 'ICED']),
            ],

            'offline_status' => [
                'required',
                Rule::in(['AVAILABLE', 'UNAVAILABLE']),
            ],
            'grabfood_status' => [
                'required',
                Rule::in(['AVAILABLE', 'UNAVAILABLE']),
            ],

            'prices' => ['required', 'array', 'min:1', 'max:2'],
            'prices.*.sales_channel' => [
                'required',
                Rule::in(['OFFLINE', 'GRABFOOD']),
                'distinct',
            ],
            'prices.*.price' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $category = Category::findOrFail($validated['category_id']);

        if (!$category->is_active) {
            return response()->json([
                'message' => 'Kategori yang dipilih tidak aktif.',
            ], 422);
        }

        $prices = collect($validated['prices']);

        $priceErrors = $this->validateAvailableChannelPrices(
            $validated['offline_status'],
            $validated['grabfood_status'],
            $prices
        );

        if (!empty($priceErrors)) {
            return response()->json([
                'message' => 'Harga untuk channel yang tersedia wajib diisi.',
                'errors' => $priceErrors,
            ], 422);
        }

        $product = DB::transaction(function () use (
            $validated,
            $prices,
            $request
        ) {
            $product = Product::create([
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'slug' => $this->generateProductSlug(
                    $validated['name']
                ),
                'sku' => $validated['sku'] ?? null,
                'description' => $validated['description'] ?? null,
                'image_path' => $validated['image_path'] ?? null,
                'temperature_options' =>
                    $validated['temperature_options'] ?? null,
                'offline_status' => $validated['offline_status'],
                'grabfood_status' => $validated['grabfood_status'],
                'is_active' => true,
            ]);

            foreach ($prices as $price) {
                $channel = $price['sales_channel'];

                $status = $channel === 'OFFLINE'
                    ? $validated['offline_status']
                    : $validated['grabfood_status'];

                ProductPrice::create([
                    'product_id' => $product->id,
                    'sales_channel' => $channel,
                    'price' => $price['price'],
                    'is_active' => $status === 'AVAILABLE',
                ]);
            }

            $this->writeAuditLog(
                $request,
                'CREATE_PRODUCT',
                $product,
                [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'prices' => $prices->values()->all(),
                    'offline_status' => $product->offline_status,
                    'grabfood_status' => $product->grabfood_status,
                ]
            );

            return $product->load([
                'category',
                'prices',
            ]);
        });

        return response()->json([
            'message' => 'Produk berhasil ditambahkan.',
            'data' => $product,
        ], 201);
    }

    /**
     * Memperbarui produk.
     *
     * Jika produk sudah diarsipkan dan menerima is_active=true,
     * produk akan dipulihkan menggunakan restore().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::withTrashed()
            ->with('prices')
            ->findOrFail($id);

        $isArchived = $product->trashed();

        // ==================================================
        // PERMISSION CHECK
        // ==================================================

        $permissionChecks = [];

        if ($request->hasAny([
            'category_id',
            'name',
            'sku',
            'description',
            'image_path',
            'temperature_options',
        ])) {
            $permissionChecks[] = 'product.update';
        }

        if ($request->has('prices')) {
            $permissionChecks[] = 'product.price.update';
        }

        if ($request->hasAny([
            'offline_status',
            'grabfood_status',
            'is_active',
        ])) {
            $permissionChecks[] = 'product.status.update';
        }

        foreach (array_unique($permissionChecks) as $permission) {
            if (!$request->user()->hasPermission($permission)) {
                return response()->json([
                    'message' => 'Kamu tidak memiliki izin untuk melakukan perubahan ini.',
                    'required_permission' => $permission,
                ], 403);
            }
        }

        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],

            'temperature_options' => ['sometimes', 'nullable', 'array'],
            'temperature_options.*' => [
                'string',
                'distinct',
                Rule::in(['HOT', 'ICED']),
            ],

            'offline_status' => [
                'sometimes',
                'required',
                Rule::in(['AVAILABLE', 'UNAVAILABLE']),
            ],
            'grabfood_status' => [
                'sometimes',
                'required',
                Rule::in(['AVAILABLE', 'UNAVAILABLE']),
            ],

            'prices' => ['sometimes', 'required', 'array', 'min:1', 'max:2'],
            'prices.*.sales_channel' => [
                'required',
                Rule::in(['OFFLINE', 'GRABFOOD']),
                'distinct',
            ],
            'prices.*.price' => [
                'required',
                'integer',
                'min:1',
            ],

            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['category_id'])) {
            $category = Category::findOrFail(
                $validated['category_id']
            );

            if (!$category->is_active) {
                return response()->json([
                    'message' => 'Kategori yang dipilih tidak aktif.',
                ], 422);
            }
        }

        $submittedPrices = collect($validated['prices'] ?? []);

        $offlineStatus = $validated['offline_status']
            ?? $product->offline_status;

        $grabfoodStatus = $validated['grabfood_status']
            ?? $product->grabfood_status;

        $willRestore = $isArchived
            && ($validated['is_active'] ?? false) === true;

        /*
         * Saat restore, harga lama boleh digunakan kembali
         * meskipun is_active pada ProductPrice sebelumnya false.
         */
        $priceErrors = $this->validateAvailableChannelPrices(
            $offlineStatus,
            $grabfoodStatus,
            $submittedPrices,
            $product,
            $willRestore
        );

        if (!empty($priceErrors)) {
            return response()->json([
                'message' => 'Channel yang tersedia wajib memiliki harga.',
                'errors' => $priceErrors,
            ], 422);
        }

        $updatedProduct = DB::transaction(function () use (
            $product,
            $validated,
            $submittedPrices,
            $offlineStatus,
            $grabfoodStatus,
            $request,
            $willRestore
        ) {
            $before = $product->fresh()
                ->load('prices')
                ->toArray();

            $productData = collect($validated)
                ->except('prices')
                ->all();

            if (isset($productData['name'])) {
                $productData['slug'] = $this->generateProductSlug(
                    $productData['name'],
                    $product->id
                );
            }

            /*
             * Pulihkan record soft deleted terlebih dahulu.
             */
            if ($willRestore) {
                $product->restore();
            }

            $product->update($productData);

            /*
             * Sinkronisasi status harga dengan status channel.
             */
            if (
                isset($validated['offline_status'])
                || $willRestore
            ) {
                ProductPrice::where('product_id', $product->id)
                    ->where('sales_channel', 'OFFLINE')
                    ->update([
                        'is_active' => $offlineStatus === 'AVAILABLE',
                    ]);
            }

            if (
                isset($validated['grabfood_status'])
                || $willRestore
            ) {
                ProductPrice::where('product_id', $product->id)
                    ->where('sales_channel', 'GRABFOOD')
                    ->update([
                        'is_active' => $grabfoodStatus === 'AVAILABLE',
                    ]);
            }

            /*
             * Harga yang dikirim hanya memperbarui channel terkait.
             * Harga channel lainnya tidak dihapus.
             */
            foreach ($submittedPrices as $price) {
                $channel = $price['sales_channel'];

                $status = $channel === 'OFFLINE'
                    ? $offlineStatus
                    : $grabfoodStatus;

                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'sales_channel' => $channel,
                    ],
                    [
                        'price' => $price['price'],
                        'is_active' => $status === 'AVAILABLE',
                    ]
                );
            }

            $this->writeAuditLog(
                $request,
                $willRestore ? 'RESTORE_PRODUCT' : 'UPDATE_PRODUCT',
                $product,
                [
                    'before' => $before,
                    'after' => $product->fresh()
                        ->load('prices')
                        ->toArray(),
                ]
            );

            return $product->fresh()->load([
                'category',
                'prices',
            ]);
        });

        return response()->json([
            'message' => $willRestore
                ? 'Produk berhasil diaktifkan kembali.'
                : 'Produk berhasil diperbarui.',
            'data' => $updatedProduct,
        ]);
    }

    /**
     * Mengarsipkan produk tanpa menghapus riwayat transaksi.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        DB::transaction(function () use ($product, $request) {
            $before = $product->fresh()
                ->load('prices')
                ->toArray();

            /*
             * Pertahankan offline_status dan grabfood_status
             * agar konfigurasi channel dapat dipulihkan.
             */
            $product->update([
                'is_active' => false,
            ]);

            ProductPrice::where('product_id', $product->id)
                ->update([
                    'is_active' => false,
                ]);

            $product->delete();

            $this->writeAuditLog(
                $request,
                'DELETE_PRODUCT',
                $product,
                [
                    'before' => $before,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ]
            );
        });

        return response()->json([
            'message' => 'Produk berhasil diarsipkan.',
        ]);
    }

    /**
     * Memvalidasi harga channel yang tersedia.
     */
    private function validateAvailableChannelPrices(
        string $offlineStatus,
        string $grabfoodStatus,
        $submittedPrices,
        ?Product $product = null,
        bool $allowArchivedPrice = false
    ): array {
        $errors = [];

        $channels = [
            'OFFLINE' => $offlineStatus,
            'GRABFOOD' => $grabfoodStatus,
        ];

        foreach ($channels as $channel => $status) {
            if ($status !== 'AVAILABLE') {
                continue;
            }

            $submittedPrice = $submittedPrices->firstWhere(
                'sales_channel',
                $channel
            );

            if ($submittedPrice !== null) {
                continue;
            }

            $existingPriceQuery = $product
                ? $product->prices()->where(
                    'sales_channel',
                    $channel
                )
                : null;

            $hasExistingPrice = $existingPriceQuery
                ? (
                    $allowArchivedPrice
                        ? $existingPriceQuery->where('price', '>', 0)->exists()
                        : $existingPriceQuery
                            ->where('is_active', true)
                            ->where('price', '>', 0)
                            ->exists()
                )
                : false;

            if (!$hasExistingPrice) {
                $errors["prices.{$channel}"] = [
                    "Harga {$channel} wajib diisi jika channel tersedia.",
                ];
            }
        }

        return $errors;
    }

    /**
     * Membuat slug produk yang unik,
     * termasuk terhadap produk yang sudah diarsipkan.
     */
    private function generateProductSlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::withTrashed()
                ->where('slug', $slug)
                ->when(
                    $ignoreId,
                    fn ($query) => $query->where('id', '!=', $ignoreId)
                )
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Menulis aktivitas ke audit log.
     */
    private function writeAuditLog(
        Request $request,
        string $action,
        Product $product,
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'PRODUCT',
            'entity_id' => $product->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}