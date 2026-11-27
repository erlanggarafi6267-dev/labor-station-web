<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'method' => [
                'required',
                'in:CASH,QRIS,TRANSFER,GRAB_PLATFORM',
            ],
            'cash_received' => [
                'nullable',
                'required_if:method,CASH',
                'integer',
                'min:0',
            ],
            'reference_number' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $actorId = (int) $request->user()->id;

        $transaction = DB::transaction(function () use (
            $id,
            $validated,
            $actorId
        ) {
            /*
             * Lock actor terlebih dahulu.
             * Urutan ini disamakan dengan RefundController dan
             * UserPermissionController untuk sinkronisasi RBAC.
             */
            $actor = User::query()
                ->with('role')
                ->lockForUpdate()
                ->findOrFail($actorId);

            /*
             * Periksa ulang permission dari database.
             * Middleware tetap menjadi lapisan otorisasi pertama.
             */
            if (!$actor->hasPermission('transaction.payment')) {
                abort(403, 'Kamu tidak memiliki izin untuk memproses pembayaran.');
            }

            /*
             * Lock transaksi setelah actor.
             */
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->order_status === 'CANCELLED') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Transaksi yang dibatalkan tidak dapat dibayar.',
                    ],
                ]);
            }

            if (
                $transaction->sales_channel === 'GRABFOOD' &&
                $validated['method'] !== 'GRAB_PLATFORM'
            ) {
                throw ValidationException::withMessages([
                    'method' => [
                        'Transaksi GrabFood harus menggunakan GRAB_PLATFORM.',
                    ],
                ]);
            }

            if (
                $transaction->sales_channel === 'OFFLINE' &&
                $validated['method'] === 'GRAB_PLATFORM'
            ) {
                throw ValidationException::withMessages([
                    'method' => [
                        'Metode GRAB_PLATFORM hanya untuk transaksi GrabFood.',
                    ],
                ]);
            }

            if ($transaction->payment_status === 'PAID') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Transaksi ini sudah dibayar.',
                    ],
                ]);
            }

            if ($transaction->payment_status !== 'UNPAID') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Status pembayaran transaksi tidak valid.',
                    ],
                ]);
            }

            $total = (int) $transaction->total;

            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Total transaksi harus lebih dari nol.',
                    ],
                ]);
            }

            if ($transaction->payment()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Catatan pembayaran untuk transaksi ini sudah ada.',
                    ],
                ]);
            }

            $cashReceived = null;
            $changeAmount = null;

            if ($validated['method'] === 'CASH') {
                $cashReceived = (int) $validated['cash_received'];

                if ($cashReceived < $total) {
                    throw ValidationException::withMessages([
                        'cash_received' => [
                            'Uang yang diterima kurang dari total pembayaran.',
                        ],
                    ]);
                }

                $changeAmount = $cashReceived - $total;
            }

            $transaction->payment()->create([
                'verified_by' => $actor->id,
                'method' => $validated['method'],
                'amount' => $total,
                'cash_received' => $cashReceived,
                'change_amount' => $changeAmount,
                'reference_number' => $validated['reference_number'] ?? null,
                'paid_at' => now(),
            ]);

            $transaction->update([
                'payment_status' => 'PAID',
            ]);

            return $transaction;
        });

        return response()->json([
            'message' => 'Pembayaran berhasil dicatat.',
            'data' => $transaction->load([
                'items',
                'payment',
                'user',
            ]),
        ], 201);
    }
}