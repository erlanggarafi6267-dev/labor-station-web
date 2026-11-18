<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
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
            'reference_number' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $transaction = DB::transaction(function () use (
            $id,
            $validated,
            $request
        ) {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($id);

            // Validasi status pesanan
            if ($transaction->order_status === 'CANCELLED') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Transaksi yang dibatalkan tidak dapat dibayar.',
                    ],
                ]);
            }

            // Validasi channel dan metode pembayaran
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

            // Validasi status pembayaran
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

            if ($transaction->total <= 0) {
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

            // Simpan pembayaran
            $transaction->payment()->create([
                'verified_by' => $request->user()->id,
                'method' => $validated['method'],
                'amount' => $transaction->total,
                'reference_number' =>
                    $validated['reference_number'] ?? null,
                'paid_at' => now(),
            ]);

            // Pembayaran pelanggan sudah selesai.
            // Pencairan GrabFood tetap diproses terpisah.
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