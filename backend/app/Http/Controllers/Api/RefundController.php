<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $reason = trim($validated['reason']);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => [
                    'Alasan refund minimal 3 karakter setelah spasi dihapus.',
                ],
            ]);
        }

        $actorId = (int) $request->user()->id;

        $refund = DB::transaction(function () use (
            $actorId,
            $id,
            $reason
        ) {
            /*
             * Lock actor terlebih dahulu.
             * Pengaturan permission juga mengunci row user target.
             */
            $actor = User::query()
                ->with('role')
                ->lockForUpdate()
                ->findOrFail($actorId);

            /*
             * Recheck permission di dalam transaksi.
             * Jangan hanya mengandalkan pemeriksaan middleware.
             */
            if (!$actor->hasPermission('transaction.refund')) {
                abort(403, 'Kamu tidak memiliki izin untuk memproses refund.');
            }

            /*
             * Lock transaksi setelah actor.
             * PaymentController menggunakan urutan yang sama.
             */
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
             * Payment tidak boleh berubah selama transaksi refund
             * karena row transaksi sudah dikunci dan semua jalur
             * pembayaran harus mengunci row transaksi yang sama.
             */
            $payment = $transaction->payment()->first();

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

            if (!$payment) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Catatan pembayaran asli tidak ditemukan.',
                    ],
                ]);
            }

            $amount = (int) $payment->amount;

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
                'processed_by' => $actor->id,
                'amount' => $amount,
                'method' => 'CASH',
                'reason' => $reason,
                'refunded_at' => now(),
            ]);

            $transaction->update([
                'payment_status' => 'REFUNDED',
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'TRANSACTION_REFUNDED',
                'entity_type' => 'TRANSACTION',
                'entity_id' => $transaction->id,
                'metadata' => [
                    'refund_id' => $refund->id,
                    'invoice_number' => $transaction->invoice_number,
                    'amount' => $amount,
                    'method' => 'CASH',
                    'reason' => $refund->reason,
                    'original_payment_method' => $payment->method,
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