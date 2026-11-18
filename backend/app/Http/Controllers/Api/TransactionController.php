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
        $transactions = Transaction::query()
            ->with(['items', 'payment'])
            ->when(
                $request->filled('sales_channel'),
                fn ($query) => $query->where(
                    'sales_channel',
                    $request->input('sales_channel')
                )
            )
            ->when(
                $request->filled('order_status'),
                fn ($query) => $query->where(
                    'order_status',
                    $request->input('order_status')
                )
            )
            ->when(
                $request->filled('payment_status'),
                fn ($query) => $query->where(
                    'payment_status',
                    $request->input('payment_status')
                )
            )
            ->when(
                $request->filled('search'),
                function ($query) use ($request) {
                    $search = $request->input('search');

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('invoice_number', 'ILIKE', "%{$search}%")
                            ->orWhere('customer_name', 'ILIKE', "%{$search}%");
                    });
                }
            )
            ->latest()
            ->paginate(15);

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
            'user',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail transaksi berhasil diambil.',
            'data' => $transaction,
        ]);
    }

    /**
     * Membuat transaksi baru.
     */
    public function store(Request $request): JsonResponse
    {
        // Kompatibilitas dengan request lama.
        // Item dengan product_id otomatis dianggap CATALOG.
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
            ],

            'items' => [
                'required',
                'array',
                'min:1',
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
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.temperature' => [
                'nullable',
                'in:HOT,ICED',
            ],
        ]);

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
                    $product = Product::query()
                        ->where('is_active', true)
                        ->find($item['product_id']);

                    if (!$product) {
                        throw ValidationException::withMessages([
                            "items.{$index}.product_id" => [
                                'Produk tidak ditemukan atau sedang tidak aktif.',
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

                    $productId = $product->id;
                    $productName = $product->name;
                    $unitPrice = (int) $price->price;
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

                $lineSubtotal = $unitPrice * $quantity;

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