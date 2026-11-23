<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderStatusSecurityTest extends TestCase
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
                ['slug' => 'transaction.status.update'],
                ['name' => 'Transaction Status Update']
            );

            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createTransaction(
        User $user,
        string $orderStatus = 'NEW',
        string $paymentStatus = 'UNPAID'
    ): Transaction {
        return Transaction::create([
            'invoice_number' => 'LS-STATUS-' . uniqid(),
            'user_id' => $user->id,
            'customer_name' => 'Pelanggan Test',
            'sales_channel' => 'OFFLINE',
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'subtotal' => 15000,
            'discount' => 0,
            'total' => 15000,
        ]);
    }

    public function test_new_order_can_move_to_preparing(): void
    {
        $user = $this->createCashier('status-preparing@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'PREPARING',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'PREPARING',
        ]);
    }

    public function test_preparing_order_can_move_to_ready(): void
    {
        $user = $this->createCashier('status-ready@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'PREPARING'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'READY',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'READY',
        ]);
    }

    public function test_ready_order_can_move_to_completed(): void
    {
        $user = $this->createCashier('status-completed@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'READY'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'COMPLETED',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'COMPLETED',
        ]);

        $this->assertNotNull(
            DB::table('transactions')
                ->where('id', $transaction->id)
                ->value('completed_at')
        );
    }

    public function test_new_order_can_be_cancelled(): void
    {
        $user = $this->createCashier('status-cancel@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'CANCELLED',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'CANCELLED',
        ]);

        $this->assertNotNull(
            DB::table('transactions')
                ->where('id', $transaction->id)
                ->value('cancelled_at')
        );
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        $user = $this->createCashier('status-paid@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'NEW',
            paymentStatus: 'PAID'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'CANCELLED',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'NEW',
            'payment_status' => 'PAID',
            'cancelled_at' => null,
        ]);
    }

    public function test_completed_order_cannot_change_to_another_status(): void
    {
        $user = $this->createCashier('status-final@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'COMPLETED',
            paymentStatus: 'PAID'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'CANCELLED',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'COMPLETED',
        ]);
    }

    public function test_cancelled_order_cannot_be_reactivated(): void
    {
        $user = $this->createCashier('status-reactivate@example.com');

        $transaction = $this->createTransaction(
            $user,
            orderStatus: 'CANCELLED'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'PREPARING',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'CANCELLED',
        ]);
    }

    public function test_invalid_order_status_is_rejected(): void
    {
        $user = $this->createCashier('status-invalid@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'HACKED',
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'NEW',
        ]);
    }

    public function test_staff_without_status_permission_cannot_update_order(): void
    {
        $user = $this->createCashier(
            'status-no-permission@example.com',
            false
        );

        $transaction = $this->createTransaction($user);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/transactions/{$transaction->id}/status", [
                'order_status' => 'PREPARING',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'NEW',
        ]);
    }

    public function test_guest_cannot_update_order_status(): void
    {
        $user = $this->createCashier('status-guest@example.com');
        $transaction = $this->createTransaction($user);

        $response = $this->patchJson(
            "/api/transactions/{$transaction->id}/status",
            [
                'order_status' => 'PREPARING',
            ]
        );

        $response->assertUnauthorized();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'order_status' => 'NEW',
        ]);
    }
}