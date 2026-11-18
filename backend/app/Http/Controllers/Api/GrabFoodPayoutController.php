<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrabFoodPayoutController extends Controller
{
    /**
     * Mencatat pencairan dana GrabFood.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'platform_deduction' => [
                'required',
                'integer',
                'min:0',
            ],
            'payout_date' => [
                'nullable',
                'date',
            ],
        ]);

        $transaction = DB::transaction(function () use (
            $id,
            $validated
        ) {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->sales_channel !== 'GRABFOOD') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Pencairan hanya berlaku untuk transaksi GrabFood.',
                    ],
                ]);
            }

            if ($transaction->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Pembayaran pelanggan GrabFood belum dikonfirmasi.',
                    ],
                ]);
            }

            if ($transaction->payout_status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'payout' => [
                        'Transaksi tidak memiliki pencairan berstatus PENDING.',
                    ],
                ]);
            }

            if (
                $validated['platform_deduction'] >
                $transaction->total
            ) {
                throw ValidationException::withMessages([
                    'platform_deduction' => [
                        'Potongan platform tidak boleh melebihi total transaksi.',
                    ],
                ]);
            }

            $netPayout = $transaction->total
                - $validated['platform_deduction'];

            $transaction->update([
                'platform_deduction' =>
                    $validated['platform_deduction'],

                'net_payout' => $netPayout,

                'payout_status' => 'PAID_OUT',

                'payout_date' =>
                    $validated['payout_date'] ?? now(),
            ]);

            return $transaction;
        });

        return response()->json([
            'message' => 'Pencairan GrabFood berhasil dicatat.',
            'data' => $transaction->load([
                'items',
                'payment',
                'user',
            ]),
        ]);
    }
}