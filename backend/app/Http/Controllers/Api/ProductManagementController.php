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
     * Nilai maksimum BIGINT signed PostgreSQL.
     */
    private const MAX_PRICE = '9223372036854775807';

    /**
     * Menampilkan daftar produk.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive', 'all']),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
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
     * Detail produk, termasuk produk yang diarsipkan.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::withTrashed()
            ->with([
                'category',
                'prices',
            ])
            ->findOrFail($id);

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
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                'unique:products,sku',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'image_path' => [
                'nullable',
                'string',
                'max:255',
            ],
            'temperature_options' => [
                'nullable',
                'array',
            ],
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
            'prices' => [
                'required',
                'array',
                'min:1',
                'max:2',
            ],
            'prices.*.sales_channel' => [
                'required',
                Rule::in(['OFFLINE', 'GRABFOOD']),
                'distinct',
            ],
            'prices.*.price' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_PRICE,
            ],
        ]);

        $product = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $category = Category::query()
                ->lockForUpdate()
                ->findOrFail($validated['category_id']);

            if (!$category->is_active) {
                abort(422, 'Kategori yang dipilih tidak aktif.');
            }

            $prices = collect($validated['prices']);

            $priceErrors = $this->validateAvailableChannelPrices(
                $validated['offline_status'],
                $validated['grabfood_status'],
                $prices
            );

            if (!empty($priceErrors)) {
                abort(
                    response()->json([
                        'message' => 'Harga untuk channel yang tersedia wajib diisi.',
                        'errors' => $priceErrors,
                    ], 422)
                );
            }

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
        }, 3);

        return response()->json([
            'message' => 'Produk berhasil ditambahkan.',
            'data' => $product,
        ], 201);
    }

    /**
     * Memperbarui produk dan memulihkan produk arsip.
     *
     * Permission:
     * - product.update
     * - product.price.update
     * - product.status.update
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $allowedFields = [
            'category_id',
            'name',
            'sku',
            'description',
            'image_path',
            'temperature_options',
            'offline_status',
            'grabfood_status',
            'prices',
            'is_active',
        ];

        $submittedFields = array_keys($request->all());

        if (empty($submittedFields)) {
            return response()->json([
                'message' => 'Tidak ada perubahan yang dikirim.',
            ], 422);
        }

        $unknownFields = array_diff(
            $submittedFields,
            $allowedFields
        );

        if (!empty($unknownFields)) {
            return response()->json([
                'message' => 'Request mengandung field yang tidak diizinkan.',
                'errors' => [
                    'fields' => array_values($unknownFields),
                ],
            ], 422);
        }

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

        if (empty($permissionChecks)) {
            return response()->json([
                'message' => 'Tidak ada perubahan produk yang valid.',
            ], 422);
        }

        $validated = $request->validate([
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'sku')->ignore($id),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'image_path' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'temperature_options' => [
                'sometimes',
                'nullable',
                'array',
            ],
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
            'prices' => [
                'sometimes',
                'required',
                'array',
                'min:1',
                'max:2',
            ],
            'prices.*.sales_channel' => [
                'required',
                Rule::in(['OFFLINE', 'GRABFOOD']),
                'distinct',
            ],
            'prices.*.price' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_PRICE,
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (empty($validated)) {
            return response()->json([
                'message' => 'Tidak ada perubahan produk yang valid.',
            ], 422);
        }

        $result = DB::transaction(function () use (
            $id,
            $validated,
            $request
        ) {
            $product = Product::withTrashed()
                ->lockForUpdate()
                ->findOrFail($id);

            $isArchived = $product->trashed();
            $wasActive = (bool) $product->is_active;

            $willRestore = $isArchived
                && (bool) ($validated['is_active'] ?? false);

            if ($isArchived && !$willRestore) {
                abort(422, 'Produk arsip harus dipulihkan sebelum diubah.');
            }

            if (isset($validated['category_id'])) {
                $category = Category::query()
                    ->lockForUpdate()
                    ->findOrFail($validated['category_id']);

                if (!$category->is_active) {
                    abort(422, 'Kategori yang dipilih tidak aktif.');
                }
            }

            $offlineStatus = $validated['offline_status']
                ?? $product->offline_status;

            $grabfoodStatus = $validated['grabfood_status']
                ?? $product->grabfood_status;

            $willBeActive = (bool) (
                $validated['is_active'] ?? $product->is_active
            );

            $submittedPrices = collect(
                $validated['prices'] ?? []
            );

            $willActivate = $willBeActive && (
                !$wasActive || $willRestore
            );

            $priceErrors = $this->validateAvailableChannelPrices(
                $offlineStatus,
                $grabfoodStatus,
                $submittedPrices,
                $product,
                $willActivate
            );

            if (!empty($priceErrors)) {
                abort(
                    response()->json([
                        'message' => 'Channel yang tersedia wajib memiliki harga.',
                        'errors' => $priceErrors,
                    ], 422)
                );
            }

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

            if ($willRestore) {
                $product->restore();
            }

            $product->update($productData);

            /*
             * Harga channel selalu nonaktif jika produk nonaktif.
             */
            if (!$willBeActive) {
                ProductPrice::where('product_id', $product->id)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            } else {
                /*
                 * Sinkronisasi channel saat aktivasi kembali
                 * atau saat status channel berubah.
                 */
                if (
                    $willActivate
                    || array_key_exists('offline_status', $validated)
                    || array_key_exists('grabfood_status', $validated)
                ) {
                    $this->synchronizeChannelPrices(
                        $product,
                        $offlineStatus,
                        $grabfoodStatus
                    );
                }
            }

            /*
             * Update harga hanya untuk channel yang dikirim.
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
                        'is_active' => $willBeActive
                            && $status === 'AVAILABLE',
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

            return [
                'product' => $product->fresh()->load([
                    'category',
                    'prices',
                ]),
                'was_restored' => $willRestore,
            ];
        }, 3);

        return response()->json([
            'message' => $result['was_restored']
                ? 'Produk berhasil diaktifkan kembali.'
                : 'Produk berhasil diperbarui.',
            'data' => $result['product'],
        ]);
    }

    /**
     * Mengarsipkan produk tanpa menghapus riwayat transaksi.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $before = $product->fresh()
                ->load('prices')
                ->toArray();

            $product->update([
                'is_active' => false,
            ]);

            ProductPrice::where('product_id', $product->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
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
        }, 3);

        return response()->json([
            'message' => 'Produk berhasil diarsipkan.',
        ]);
    }

    /**
     * Menyinkronkan status harga dengan status channel.
     */
    private function synchronizeChannelPrices(
        Product $product,
        string $offlineStatus,
        string $grabfoodStatus
    ): void {
        $timestamp = now();

        ProductPrice::where('product_id', $product->id)
            ->where('sales_channel', 'OFFLINE')
            ->update([
                'is_active' => $offlineStatus === 'AVAILABLE',
                'updated_at' => $timestamp,
            ]);

        ProductPrice::where('product_id', $product->id)
            ->where('sales_channel', 'GRABFOOD')
            ->update([
                'is_active' => $grabfoodStatus === 'AVAILABLE',
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * Validasi harga untuk channel yang tersedia.
     */
    private function validateAvailableChannelPrices(
        string $offlineStatus,
        string $grabfoodStatus,
        $submittedPrices,
        ?Product $product = null,
        bool $allowInactiveExistingPrice = false
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
                ? $existingPriceQuery
                    ->when(
                        !$allowInactiveExistingPrice,
                        fn ($query) => $query->where('is_active', true)
                    )
                    ->where('price', '>', 0)
                    ->exists()
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
     * Membuat slug unik termasuk terhadap produk arsip.
     */
    private function generateProductSlug(
        string $name,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'produk';
        }

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