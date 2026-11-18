<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusController extends Controller
{
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'order_status' => [
                'required',
                'in:PREPARING,READY,COMPLETED,CANCELLED',
            ],
        ]);

        $transaction = DB::transaction(function () use ($validated, $id) {

            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $currentStatus = $transaction->order_status;
            $newStatus = $validated['order_status'];

            $allowedTransitions = [
                'NEW' => ['PREPARING', 'CANCELLED'],
                'PREPARING' => ['READY'],
                'READY' => ['COMPLETED'],
                'COMPLETED' => [],
                'CANCELLED' => [],
            ];

            if (!in_array(
                $newStatus,
                $allowedTransitions[$currentStatus] ?? [],
                true
            )) {
                throw ValidationException::withMessages([
                    'order_status' => [
                        "Status tidak dapat diubah dari {$currentStatus} menjadi {$newStatus}.",
                    ],
                ]);
            }

            $updates = [
                'order_status' => $newStatus,
            ];

            if ($newStatus === 'COMPLETED') {
                $updates['completed_at'] = now();
            }

            if ($newStatus === 'CANCELLED') {
                $updates['cancelled_at'] = now();
            }

            $transaction->update($updates);

            return $transaction;
        });

        return response()->json([
            'message' => 'Status pesanan berhasil diperbarui.',
            'data' => $transaction->load([
                'items',
                'payment',
                'user',
            ]),
        ]);
    }
}