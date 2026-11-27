<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month'],
        ]);

        $period = $validated['period'] ?? 'today';

        $now = now();

        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'week') {
            $start = $now->copy()->startOfWeek();
            $end = $now->copy()->endOfWeek();
        } else {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfMonth();
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        /*
         * 1. DATA PENJUALAN
         * Berdasarkan tanggal transaksi dibuat.
         * Transaksi CANCELLED tidak dihitung.
         */
        $transactions = Transaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('order_status', '!=', 'CANCELLED');

        $offlineSales = (clone $transactions)
            ->where('sales_channel', 'OFFLINE')
            ->sum('total');

        $grabFoodSales = (clone $transactions)
            ->where('sales_channel', 'GRABFOOD')
            ->sum('total');

        $totalTransactions = (clone $transactions)->count();

        $unpaidTransactions = (clone $transactions)
            ->where('payment_status', 'UNPAID')
            ->count();

        /*
         * 2. KONFIRMASI PEMBAYARAN PELANGGAN
         * Berdasarkan tanggal transaksi dibuat.
         *
         * Pembayaran GrabFood berarti dikonfirmasi platform,
         * bukan berarti uang sudah masuk ke rekening Owner.
         */
        $offlineConfirmed = DB::table('payments')
            ->join(
                'transactions',
                'payments.transaction_id',
                '=',
                'transactions.id'
            )
            ->whereBetween('transactions.created_at', [$start, $end])
            ->where('transactions.sales_channel', 'OFFLINE')
            ->where('transactions.order_status', '!=', 'CANCELLED')
            ->sum('payments.amount');

        $grabFoodConfirmed = DB::table('payments')
            ->join(
                'transactions',
                'payments.transaction_id',
                '=',
                'transactions.id'
            )
            ->whereBetween('transactions.created_at', [$start, $end])
            ->where('transactions.sales_channel', 'GRABFOOD')
            ->where('transactions.order_status', '!=', 'CANCELLED')
            ->sum('payments.amount');

        /*
         * 3. PENCAIRAN GRABFOOD
         * Berdasarkan tanggal payout_date,
         * bukan tanggal transaksi dibuat.
         */
        $payouts = Transaction::query()
            ->where('sales_channel', 'GRABFOOD')
            ->where('payout_status', 'PAID_OUT')
            ->whereBetween('payout_date', [$start, $end]);

        $platformDeduction = (clone $payouts)
            ->sum('platform_deduction');

        $netPayout = (clone $payouts)
            ->sum('net_payout');

        $totalPayoutTransactions = (clone $payouts)->count();

        /*
         * 4. PENGELUARAN
         * Berdasarkan tanggal pengeluaran.
         */
        $totalRefunds = DB::table('refunds')
            ->join('transactions', 'refunds.transaction_id', '=', 'transactions.id')
            ->where('transactions.sales_channel', 'OFFLINE')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->sum('refunds.amount');

        $totalExpenses = Expense::query()
            ->whereBetween('expense_date', [
                $startDate,
                $endDate,
            ])
            ->sum('amount');

        /*
        * 5. STATUS PESANAN
        * Menghitung seluruh status transaksi pada periode terpilih,
        * termasuk transaksi CANCELLED.
        */
        $orderStatusCounts = Transaction::query()
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'order_status',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('order_status')
            ->pluck('total', 'order_status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $orderStatuses = [
            'NEW' => $orderStatusCounts['NEW'] ?? 0,
            'PREPARING' => $orderStatusCounts['PREPARING'] ?? 0,
            'READY' => $orderStatusCounts['READY'] ?? 0,
            'COMPLETED' => $orderStatusCounts['COMPLETED'] ?? 0,
            'CANCELLED' => $orderStatusCounts['CANCELLED'] ?? 0,
        ];

        /*
         * 6. PRODUK TERLARIS
         * Menggunakan snapshot nama produk,
         * termasuk produk yang dimasukkan secara manual.
         */
        $bestSellers = DB::table('transaction_items')
            ->join(
                'transactions',
                'transaction_items.transaction_id',
                '=',
                'transactions.id'
            )
            ->whereBetween('transactions.created_at', [$start, $end])
            ->where('transactions.order_status', '!=', 'CANCELLED')
            ->select(
                'transaction_items.product_name_snapshot',
                DB::raw('SUM(transaction_items.quantity) as total_quantity'),
                DB::raw('SUM(transaction_items.subtotal) as total_sales')
            )
            ->groupBy('transaction_items.product_name_snapshot')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get();

        /*
         * 7. RESPONSE DASHBOARD
         */
        return response()->json([
            'message' => 'Dashboard Owner berhasil diambil.',

            'period' => [
                'filter' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],

            'sales' => [
                'offline' => (int) $offlineSales,
                'grabfood' => (int) $grabFoodSales,
                'gross_total' => (int) $offlineSales
                    + (int) $grabFoodSales,
                'net_total' => (int) $offlineSales
                    + (int) $grabFoodSales
                    - (int) $totalRefunds,
            ],

            'customer_payment_confirmations' => [
                'offline' => (int) $offlineConfirmed,
                'grabfood_platform' => (int) $grabFoodConfirmed,
                'total' => (int) $offlineConfirmed
                    + (int) $grabFoodConfirmed,
            ],

            'grabfood_payout' => [
                'total_transactions' => $totalPayoutTransactions,
                'platform_deduction' => (int) $platformDeduction,
                'net_payout_received' => (int) $netPayout,
            ],

            'refunds' => [
                'total' => (int) $totalRefunds,
            ],

            'expenses' => [
                'total' => (int) $totalExpenses,
            ],

            'transactions' => [
                'total' => $totalTransactions,
                'unpaid' => $unpaidTransactions,
                'order_statuses' => $orderStatuses,
            ],

            'best_sellers' => $bestSellers,
        ]);
    }
}