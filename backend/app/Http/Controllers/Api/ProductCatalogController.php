<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    /**
     * Menampilkan katalog produk berdasarkan channel penjualan.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sales_channel' => [
                'required',
                'in:OFFLINE,GRABFOOD',
            ],
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
        ]);

        $channel = $validated['sales_channel'];

        $availabilityColumn = $channel === 'OFFLINE'
            ? 'offline_status'
            : 'grabfood_status';

        $products = Product::query()
            ->where('is_active', true)
            ->where($availabilityColumn, 'AVAILABLE')
            ->whereHas('category', function ($query) {
                $query->where('is_active', true);
            })
            ->with([
                'category:id,name,slug',
                'prices' => function ($query) use ($channel) {
                    $query
                        ->where('sales_channel', $channel)
                        ->where('is_active', true)
                        ->select([
                            'id',
                            'product_id',
                            'sales_channel',
                            'price',
                        ]);
                },
            ])
            ->when(
                !empty($validated['search']),
                function ($query) use ($validated) {
                    $search = $validated['search'];

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('sku', 'ILIKE', "%{$search}%");
                    });
                }
            )
            ->when(
                !empty($validated['category_id']),
                fn ($query) => $query->where(
                    'category_id',
                    $validated['category_id']
                )
            )
            ->orderBy('name')
            ->get();

        // Produk tanpa harga aktif untuk channel ini tidak ditampilkan.
        $products = $products
            ->filter(fn ($product) => $product->prices->isNotEmpty())
            ->values();

        return response()->json([
            'message' => 'Katalog produk berhasil diambil.',
            'data' => [
                'sales_channel' => $channel,
                'total' => $products->count(),
                'products' => $products,
            ],
        ]);
    }
}