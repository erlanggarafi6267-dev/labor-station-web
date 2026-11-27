<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createCashier(string $email, bool $canRefund = true): User
    {
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);
        $user = User::create([
            'name' => 'Refund Cashier',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        if ($canRefund) {
            $permission = Permission::firstOrCreate(
                ['slug' => 'transaction.refund'],
                ['name' => 'Memproses Refund Transaksi']
            );
            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createPaidTransaction(
        User $user,
        string $channel = 'OFFLINE',
        string $orderStatus = 'COMPLETED',
        int $total = 15000,
        string $method = 'QRIS'
    ): Transaction {
        $transaction = Transaction::create([
            'invoice_number' => 'LS-REFUND-' . uniqid(),
            'user_id' => $user->id,
            'customer_name' => 'Pelanggan Refund',
            'sales_channel' => $channel,
            'order_status' => $orderStatus,
            'payment_status' => 'PAID',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'payout_status' => $channel === 'GRABFOOD' ? 'PENDING' : null,
        ]);

        $transaction->payment()->create([
            'verified_by' => $user->id,
            'method' => $channel === 'GRABFOOD' ? 'GRAB_PLATFORM' : $method,
            'amount' => $total,
            'paid_at' => now(),
        ]);

        return $transaction;
    }

    public function test_cashier_can_refund_paid_offline_transaction_and_original_payment_is_preserved(): void
    {
        $cashier = $this->createCashier('refund-success@example.com');
        $transaction = $this->createPaidTransaction($cashier, orderStatus: 'NEW', method: 'QRIS');

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/refund", [
                'reason' => 'Pelanggan membatalkan pesanan.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonPath('data.method', 'CASH');

        $this->assertDatabaseHas('refunds', [
            'transaction_id' => $transaction->id,
            'processed_by' => $cashier->id,
            'amount' => 15000,
            'method' => 'CASH',
            'reason' => 'Pelanggan membatalkan pesanan.',
        ]);

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'method' => 'QRIS',
            'amount' => 15000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'REFUNDED',
            'order_status' => 'NEW',
        ]);

        $this->assertDatabaseCount('stock_movements', 0);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $cashier->id,
            'action' => 'TRANSACTION_REFUNDED',
            'entity_type' => 'TRANSACTION',
            'entity_id' => $transaction->id,
        ]);
    }

    public function test_refund_is_rejected_for_grabfood_transaction(): void
    {
        $cashier = $this->createCashier('refund-grab@example.com');
        $transaction = $this->createPaidTransaction($cashier, channel: 'GRABFOOD');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/refund", [
                'reason' => 'Refund test GrabFood.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_unpaid_transaction_cannot_be_refunded(): void
    {
        $cashier = $this->createCashier('refund-unpaid@example.com');
        $transaction = Transaction::create([
            'invoice_number' => 'LS-REFUND-UNPAID-' . uniqid(),
            'user_id' => $cashier->id,
            'sales_channel' => 'OFFLINE',
            'order_status' => 'NEW',
            'payment_status' => 'UNPAID',
            'subtotal' => 10000,
            'discount' => 0,
            'total' => 10000,
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/refund", [
                'reason' => 'Belum dibayar.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_transaction_can_only_be_refunded_once(): void
    {
        $cashier = $this->createCashier('refund-duplicate@example.com');
        $transaction = $this->createPaidTransaction($cashier);

        $url = "/api/transactions/{$transaction->id}/refund";
        $payload = ['reason' => 'Refund duplikat test.'];

        $this->actingAs($cashier, 'sanctum')->postJson($url, $payload)->assertCreated();
        $this->actingAs($cashier, 'sanctum')->postJson($url, $payload)->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_cashier_without_refund_permission_is_forbidden(): void
    {
        $cashier = $this->createCashier('refund-forbidden@example.com', false);
        $transaction = $this->createPaidTransaction($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/refund", [
                'reason' => 'Tidak punya permission.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_guest_cannot_refund_transaction(): void
    {
        $cashier = $this->createCashier('refund-guest@example.com');
        $transaction = $this->createPaidTransaction($cashier);

        $this->postJson("/api/transactions/{$transaction->id}/refund", [
            'reason' => 'Guest request.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_refund_requires_a_reason(): void
    {
        $cashier = $this->createCashier('refund-reason@example.com');
        $transaction = $this->createPaidTransaction($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/refund", [
                'reason' => ' ',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
    }
}
