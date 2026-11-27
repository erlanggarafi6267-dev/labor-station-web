<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    /**
     * Menampilkan daftar transaksi.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sales_channel' => [
                'nullable',
                'in:OFFLINE,GRABFOOD',
            ],
            'order_status' => [
                'nullable',
                'in:NEW,PREPARING,READY,COMPLETED,CANCELLED',
            ],
            'payment_status' => [
                'nullable',
                'in:UNPAID,PAID,REFUNDED',
            ],
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $transactions = Transaction::query()
            ->with(['items', 'payment', 'refund'])
            ->when(
                !empty($validated['sales_channel']),
                fn ($query) => $query->where(
                    'sales_channel',
                    $validated['sales_channel']
                )
            )
            ->when(
                !empty($validated['order_status']),
                fn ($query) => $query->where(
                    'order_status',
                    $validated['order_status']
                )
            )
            ->when(
                !empty($validated['payment_status']),
                fn ($query) => $query->where(
                    'payment_status',
                    $validated['payment_status']
                )
            )
            ->when(
                !empty($validated['search']),
                function ($query) use ($validated) {
                    $search = $validated['search'];

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'invoice_number',
                                'ILIKE',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'customer_name',
                                'ILIKE',
                                "%{$search}%"
                            );
                    });
                }
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Daftar transaksi berhasil diambil.',
            'data' => $transactions,
        ]);
    }

    /**
     * Menampilkan detail transaksi.
     */
    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::with([
            'items.product',
            'payment',
            'refund.processor:id,name',
            'user',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail transaksi berhasil diambil.',
            'data' => $transaction,
        ]);
    }

    /**
     * Membuat transaksi baru.
     *
     * Semua harga katalog diambil dari database.
     * Harga item manual tetap dihitung oleh backend.
     */
    public function store(Request $request): JsonResponse
    {
        /*
         * Kompatibilitas dengan request lama.
         * Item dengan product_id dianggap CATALOG.
         * Item tanpa product_id dianggap MANUAL.
         */
        $items = $request->input('items', []);

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (
                    is_array($item) &&
                    !isset($item['item_type'])
                ) {
                    $items[$index]['item_type'] =
                        !empty($item['product_id'])
                            ? 'CATALOG'
                            : 'MANUAL';
                }
            }

            $request->merge([
                'items' => $items,
            ]);
        }

        /*
         * Validasi request.
         *
         * Batas teknis:
         * Harga satuan maksimal Rp1.000.000.000.
         * Kuantitas maksimal 10.000 per item.
         * Maksimal 100 baris item per transaksi.
         */
        $validated = $request->validate([
            'customer_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'sales_channel' => [
                'required',
                'in:OFFLINE,GRABFOOD',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'items.*.item_type' => [
                'required',
                'in:CATALOG,MANUAL',
            ],

            'items.*.product_id' => [
                'nullable',
                'integer',
                'required_if:items.*.item_type,CATALOG',
                'exists:products,id',
            ],

            'items.*.name' => [
                'required_if:items.*.item_type,MANUAL',
                'nullable',
                'string',
                'max:150',
            ],

            'items.*.unit_price' => [
                'required_if:items.*.item_type,MANUAL',
                'nullable',
                'integer',
                'min:1',
                'max:1000000000',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:10000',
            ],

            'items.*.temperature' => [
                'nullable',
                'in:HOT,ICED',
            ],
        ]);

        /*
         * Seluruh proses transaksi berada di dalam database
         * transaction agar data tidak tersimpan setengah jalan.
         */
        $transaction = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $subtotal = 0;
            $preparedItems = [];

            foreach ($validated['items'] as $index => $item) {
                $temperature = $item['temperature'] ?? null;

                /*
                 * ITEM KATALOG
                 */
                if ($item['item_type'] === 'CATALOG') {
                    /*
                     * Kunci baris produk selama transaksi.
                     * Ini membantu mencegah perubahan status produk
                     * bersamaan dengan proses penjualan.
                     */
                    $product = Product::query()
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->find($item['product_id']);

                    if (!$product) {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => [
                                'Produk tidak ditemukan atau sedang tidak aktif.',
                            ],
                        ]);
                    }

                    /*
                     * VALIDASI STATUS CHANNEL
                     */
                    $channelStatus = $validated['sales_channel']
                        === 'OFFLINE'
                            ? $product->offline_status
                            : $product->grabfood_status;

                    if ($channelStatus !== 'AVAILABLE') {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => [
                                'Produk tidak tersedia untuk channel penjualan ini.',
                            ],
                        ]);
                    }

                    /*
                     * VALIDASI PILIHAN SUHU
                     */
                    $allowedTemperatures =
                        $product->temperature_options ?? [];

                    if (!is_array($allowedTemperatures)) {
                        $allowedTemperatures = [];
                    }

                    if (count($allowedTemperatures) > 0) {
                        if ($temperature === null) {
                            throw ValidationException::withMessages([
                                "items.{$index}.temperature" => [
                                    "Silakan pilih suhu untuk produk {$product->name}.",
                                ],
                            ]);
                        }

                        if (
                            !in_array(
                                $temperature,
                                $allowedTemperatures,
                                true
                            )
                        ) {
                            throw ValidationException::withMessages([
                                "items.{$index}.temperature" => [
                                    "Pilihan suhu tidak tersedia untuk produk {$product->name}.",
                                ],
                            ]);
                        }
                    } elseif ($temperature !== null) {
                        throw ValidationException::withMessages([
                            "items.{$index}.temperature" => [
                                "Produk {$product->name} tidak memiliki pilihan suhu.",
                            ],
                        ]);
                    }

                    /*
                     * AMBIL HARGA SESUAI CHANNEL
                     */
                    $price = ProductPrice::query()
                        ->where('product_id', $product->id)
                        ->where(
                            'sales_channel',
                            $validated['sales_channel']
                        )
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if (!$price) {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => [
                                'Harga produk tidak tersedia untuk channel penjualan ini.',
                            ],
                        ]);
                    }

                    $unitPrice = (int) $price->price;

                    /*
                     * Validasi harga katalog dari database.
                     */
                    if (
                        $unitPrice < 1 ||
                        $unitPrice > 1000000000
                    ) {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => [
                                'Harga produk berada di luar batas yang diizinkan.',
                            ],
                        ]);
                    }

                    $productId = $product->id;
                    $productName = $product->name;
                }

                /*
                 * ITEM MANUAL
                 */
                else {
                    if ($temperature !== null) {
                        throw ValidationException::withMessages([
                            "items.{$index}.temperature" => [
                                'Item manual tidak memiliki pilihan suhu.',
                            ],
                        ]);
                    }

                    $productId = null;
                    $productName = $item['name'];
                    $unitPrice = (int) $item['unit_price'];
                }

                /*
                 * HITUNG SUBTOTAL ITEM
                 */
                $quantity = (int) $item['quantity'];

                /*
                 * Periksa overflow sebelum perkalian.
                 */
                if (
                    $unitPrice > intdiv(PHP_INT_MAX, $quantity)
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Subtotal item terlalu besar untuk diproses.',
                        ],
                    ]);
                }

                $lineSubtotal = $unitPrice * $quantity;

                /*
                 * Periksa overflow sebelum penjumlahan.
                 */
                if (
                    $lineSubtotal > PHP_INT_MAX - $subtotal
                ) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'Total transaksi terlalu besar.',
                        ],
                    ]);
                }

                $subtotal += $lineSubtotal;

                /*
                 * SIAPKAN DATA ITEM
                 */
                $preparedItems[] = [
                    'product_id' => $productId,
                    'item_type' => $item['item_type'],
                    'temperature' => $temperature,
                    'product_name_snapshot' => $productName,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            /*
             * BUAT TRANSAKSI
             */
            $transaction = Transaction::create([
                'invoice_number' => 'LS-' .
                    now()->format('YmdHis') . '-' .
                    Str::upper(Str::random(6)),

                'payout_status' =>
                    $validated['sales_channel'] === 'GRABFOOD'
                        ? 'PENDING'
                        : null,

                'platform_deduction' => null,
                'net_payout' => null,
                'payout_date' => null,

                /*
                 * Identitas kasir diambil dari autentikasi.
                 * Tidak menerima user_id dari request.
                 */
                'user_id' => $request->user()->id,

                'customer_name' =>
                    $validated['customer_name'] ?? null,

                'sales_channel' => $validated['sales_channel'],

                'order_status' => 'NEW',
                'payment_status' => 'UNPAID',

                'subtotal' => $subtotal,
                'discount' => 0,
                'total' => $subtotal,

                'notes' => $validated['notes'] ?? null,
            ]);

            /*
             * SIMPAN SEMUA ITEM
             */
            $transaction->items()->createMany($preparedItems);

            return $transaction;
        });

        return response()->json([
            'message' => 'Transaksi berhasil dibuat.',
            'data' => $transaction->load([
                'items.product',
                'payment',
            ]),
        ], 201);
    }
}