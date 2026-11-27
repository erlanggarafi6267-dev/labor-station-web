<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerDashboardSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(): User
    {
        $role = Role::create([
            'name' => 'Owner',
            'slug' => 'owner',
        ]);

        return User::create([
            'name' => 'Test Owner',
            'email' => 'owner-dashboard@example.com',
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);
    }

    private function createTransaction(
        User $user,
        string $orderStatus,
        int $total
    ): Transaction {
        return Transaction::create([
            'invoice_number' => 'LS-DASH-' . uniqid(),
            'user_id' => $user->id,
            'customer_name' => 'Pelanggan Test',
            'sales_channel' => 'OFFLINE',
            'order_status' => $orderStatus,
            'payment_status' => 'UNPAID',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
        ]);
    }

    public function test_cancelled_orders_are_counted_in_order_statuses(): void
    {
        $owner = $this->createOwner();

        $this->createTransaction($owner, 'NEW', 15000);
        $this->createTransaction($owner, 'PREPARING', 20000);
        $this->createTransaction($owner, 'CANCELLED', 10000);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/owner/dashboard?period=today');

        $response->assertOk();

        $response->assertJsonPath(
            'transactions.order_statuses.NEW',
            1
        );

        $response->assertJsonPath(
            'transactions.order_statuses.PREPARING',
            1
        );

        $response->assertJsonPath(
            'transactions.order_statuses.CANCELLED',
            1
        );
    }

    public function test_cancelled_orders_are_excluded_from_sales_and_total_transactions(): void
    {
        $owner = $this->createOwner();

        $this->createTransaction($owner, 'NEW', 15000);
        $this->createTransaction($owner, 'CANCELLED', 10000);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/owner/dashboard?period=today');

        $response->assertOk();

        $response->assertJsonPath(
            'sales.offline',
            15000
        );

        $response->assertJsonPath(
            'sales.gross_total',
            15000
        );

        $response->assertJsonPath(
            'transactions.total',
            1
        );

        $response->assertJsonPath(
            'transactions.order_statuses.CANCELLED',
            1
        );
    }

    public function test_guest_cannot_access_owner_dashboard(): void
    {
        $response = $this->getJson('/api/owner/dashboard');

        $response->assertUnauthorized();
    }

    public function test_staff_cannot_access_owner_dashboard(): void
    {
        $role = Role::create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $staff = User::create([
            'name' => 'Test Staff',
            'email' => 'staff-dashboard@example.com',
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/owner/dashboard');

        $response->assertForbidden();
    }
}