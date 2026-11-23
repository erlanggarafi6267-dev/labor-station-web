<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createCashier(
        string $email,
        bool $hasPermission = true
    ): User {
        $role = Role::create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $user = User::create([
            'name' => 'Test Cashier',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        if ($hasPermission) {
            $permission = Permission::firstOrCreate(
                ['slug' => 'transaction.payment'],
                ['name' => 'Transaction Payment']
            );

            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createTransaction(
        User $user,
        string $channel = 'OFFLINE',
        string $orderStatus = 'NEW',
        string $paymentStatus = 'UNPAID',
        int $total = 15000
    ): Transaction {
        return Transaction::create([
            'invoice_number' => 'LS-TEST-' . uniqid(),
            'user_id' => $user->id,
            'customer_name' => 'Pelanggan Test',
            'sales_channel' => $channel,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'notes' => null,
            'payout_status' => $channel === 'GRABFOOD'
                ? 'PENDING'
                : null,
        ]);
    }

    public function test_cash_payment_records_correct_amount_and_change(): void
    {
        $user = $this->createCashier('cash-success@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'verified_by' => $user->id,
            'method' => 'CASH',
            'amount' => 15000,
            'cash_received' => 20000,
            'change_amount' => 5000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_cash_payment_rejects_insufficient_cash(): void
    {
        $user = $this->createCashier('cash-insufficient@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 10000,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'UNPAID',
        ]);
    }

    public function test_client_cannot_manipulate_payment_amount(): void
    {
        $user = $this->createCashier('payment-amount@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'QRIS',
                'amount' => 1,
                'cash_received' => 1,
                'change_amount' => 999999,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'method' => 'QRIS',
            'amount' => 15000,
            'cash_received' => null,
            'change_amount' => null,
        ]);
    }

    public function test_transaction_cannot_be_paid_twice(): void
    {
        $user = $this->createCashier('double-payment@example.com');
        $transaction = $this->createTransaction($user);

        $firstResponse = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $firstResponse->assertCreated();

        $secondResponse = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $secondResponse->assertUnprocessable();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_cancelled_transaction_cannot_be_paid(): void
    {
        $user = $this->createCashier('cancelled-payment@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'CANCELLED'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_grabfood_transaction_rejects_offline_payment_method(): void
    {
        $user = $this->createCashier('grab-method@example.com');

        $transaction = $this->createTransaction(
            $user,
            channel: 'GRABFOOD'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_offline_transaction_rejects_grab_platform_payment(): void
    {
        $user = $this->createCashier('offline-grab@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'GRAB_PLATFORM',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_staff_without_payment_permission_is_forbidden(): void
    {
        $user = $this->createCashier(
            'no-payment-permission@example.com',
            false
        );

        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_cannot_record_payment(): void
    {
        $user = $this->createCashier('guest-payment@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->postJson(
            "/api/transactions/{$transaction->id}/payment",
            [
                'method' => 'CASH',
                'cash_received' => 20000,
            ]
        );

        $response->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_zero_total_transaction_cannot_be_paid(): void
    {
        $user = $this->createCashier('zero-total@example.com');

        $transaction = $this->createTransaction(
            $user,
            total: 0
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/payment", [
                'method' => 'QRIS',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }
}