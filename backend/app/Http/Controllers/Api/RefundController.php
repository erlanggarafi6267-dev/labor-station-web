<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $refund = DB::transaction(function () use ($request, $id, $validated) {
            $transaction = Transaction::query()
                ->with('payment')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->sales_channel !== 'OFFLINE') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Refund transaksi GRABFOOD ditangani langsung oleh Grab.',
                    ],
                ]);
            }

            if ($transaction->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Hanya transaksi dengan pembayaran PAID yang dapat direfund.',
                    ],
                ]);
            }

            if (!$transaction->payment) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Catatan pembayaran asli tidak ditemukan.',
                    ],
                ]);
            }

            $amount = (int) $transaction->payment->amount;

            if ($amount <= 0 || $amount !== (int) $transaction->total) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Nominal pembayaran tidak valid untuk full refund.',
                    ],
                ]);
            }

            if ($transaction->refund()->exists()) {
                throw ValidationException::withMessages([
                    'refund' => [
                        'Transaksi ini sudah pernah direfund.',
                    ],
                ]);
            }

            $refund = $transaction->refund()->create([
                'processed_by' => $request->user()->id,
                'amount' => $amount,
                'method' => 'CASH',
                'reason' => trim($validated['reason']),
                'refunded_at' => now(),
            ]);

            $transaction->update([
                'payment_status' => 'REFUNDED',
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'TRANSACTION_REFUNDED',
                'entity_type' => 'TRANSACTION',
                'entity_id' => $transaction->id,
                'metadata' => [
                    'refund_id' => $refund->id,
                    'invoice_number' => $transaction->invoice_number,
                    'amount' => $amount,
                    'method' => 'CASH',
                    'reason' => $refund->reason,
                    'original_payment_method' => $transaction->payment->method,
                    'stock_restored' => false,
                ],
                'created_at' => now(),
            ]);

            return $refund;
        });

        return response()->json([
            'message' => 'Refund tunai berhasil dicatat.',
            'data' => $refund->load('processor:id,name'),
        ], 201);
    }
}
