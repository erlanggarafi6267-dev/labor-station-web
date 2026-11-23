<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrabFoodPayoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(
        string $email,
        string $roleSlug
    ): User {
        $role = Role::create([
            'name' => ucfirst($roleSlug),
            'slug' => $roleSlug,
        ]);

        return User::create([
            'name' => 'Test ' . ucfirst($roleSlug),
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);
    }

    private function createGrabFoodTransaction(
        User $user,
        string $paymentStatus = 'PAID',
        ?string $payoutStatus = 'PENDING',
        int $total = 50000
    ): Transaction {
        return Transaction::create([
            'invoice_number' => 'LS-PAYOUT-' . uniqid(),
            'user_id' => $user->id,
            'customer_name' => 'Pelanggan GrabFood',
            'sales_channel' => 'GRABFOOD',
            'order_status' => 'COMPLETED',
            'payment_status' => $paymentStatus,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'platform_deduction' => null,
            'net_payout' => null,
            'payout_status' => $payoutStatus,
            'payout_date' => null,
        ]);
    }

    public function test_owner_can_record_grabfood_payout(): void
    {
        $owner = $this->createUser(
            'payout-owner@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'platform_deduction' => 5000,
            'net_payout' => 45000,
            'payout_status' => 'PAID_OUT',
        ]);

        $this->assertNotNull(
            DB::table('transactions')
                ->where('id', $transaction->id)
                ->value('payout_date')
        );
    }

    public function test_non_grabfood_transaction_cannot_be_paid_out(): void
    {
        $owner = $this->createUser(
            'payout-offline@example.com',
            'owner'
        );

        $transaction = Transaction::create([
            'invoice_number' => 'LS-OFFLINE-' . uniqid(),
            'user_id' => $owner->id,
            'customer_name' => 'Pelanggan Offline',
            'sales_channel' => 'OFFLINE',
            'order_status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'subtotal' => 50000,
            'discount' => 0,
            'total' => 50000,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'sales_channel' => 'OFFLINE',
            'payout_status' => null,
        ]);
    }

    public function test_unpaid_grabfood_transaction_cannot_be_paid_out(): void
    {
        $owner = $this->createUser(
            'payout-unpaid@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction(
            $owner,
            paymentStatus: 'UNPAID'
        );

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'UNPAID',
            'payout_status' => 'PENDING',
        ]);
    }

    public function test_platform_deduction_cannot_exceed_transaction_total(): void
    {
        $owner = $this->createUser(
            'payout-excess@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 50001,
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payout_status' => 'PENDING',
            'platform_deduction' => null,
            'net_payout' => null,
        ]);
    }

    public function test_client_cannot_manipulate_net_payout(): void
    {
        $owner = $this->createUser(
            'payout-manipulate@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                    'net_payout' => 999999999,
                    'payout_status' => 'PAID_OUT',
                ]
            );

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'platform_deduction' => 5000,
            'net_payout' => 45000,
            'payout_status' => 'PAID_OUT',
        ]);
    }

    public function test_grabfood_payout_cannot_be_processed_twice(): void
    {
        $owner = $this->createUser(
            'payout-twice@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $firstResponse = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $firstResponse->assertOk();

        $secondResponse = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 10000,
                ]
            );

        $secondResponse->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'platform_deduction' => 5000,
            'net_payout' => 45000,
            'payout_status' => 'PAID_OUT',
        ]);
    }

    public function test_admin_cannot_record_grabfood_payout(): void
    {
        $admin = $this->createUser(
            'payout-admin@example.com',
            'admin'
        );

        $transaction = $this->createGrabFoodTransaction($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $response->assertForbidden();
    }

    public function test_staff_cannot_record_grabfood_payout(): void
    {
        $staff = $this->createUser(
            'payout-staff@example.com',
            'staff'
        );

        $transaction = $this->createGrabFoodTransaction($staff);

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => 5000,
                ]
            );

        $response->assertForbidden();
    }

    public function test_guest_cannot_record_grabfood_payout(): void
    {
        $owner = $this->createUser(
            'payout-guest@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $response = $this->postJson(
            "/api/transactions/{$transaction->id}/payout",
            [
                'platform_deduction' => 5000,
            ]
        );

        $response->assertUnauthorized();
    }

    public function test_negative_platform_deduction_is_rejected(): void
    {
        $owner = $this->createUser(
            'payout-negative@example.com',
            'owner'
        );

        $transaction = $this->createGrabFoodTransaction($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/transactions/{$transaction->id}/payout",
                [
                    'platform_deduction' => -1000,
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payout_status' => 'PENDING',
            'platform_deduction' => null,
            'net_payout' => null,
        ]);
    }
}