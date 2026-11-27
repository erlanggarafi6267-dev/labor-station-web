<?php

namespace Tests\Feature;

use App\Models\Refund;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dashboard_subtracts_offline_refunds_from_net_sales(): void
    {
        $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
        $owner = User::create([
            'name' => 'Dashboard Owner',
            'email' => 'refund-dashboard@example.com',
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        $refunded = Transaction::create([
            'invoice_number' => 'LS-DASH-REFUNDED',
            'user_id' => $owner->id,
            'sales_channel' => 'OFFLINE',
            'order_status' => 'COMPLETED',
            'payment_status' => 'REFUNDED',
            'subtotal' => 15000,
            'discount' => 0,
            'total' => 15000,
        ]);

        $refunded->payment()->create([
            'verified_by' => $owner->id,
            'method' => 'QRIS',
            'amount' => 15000,
            'paid_at' => now(),
        ]);

        Refund::create([
            'transaction_id' => $refunded->id,
            'processed_by' => $owner->id,
            'amount' => 15000,
            'method' => 'CASH',
            'reason' => 'Dashboard refund test.',
            'refunded_at' => now(),
        ]);

        $normal = Transaction::create([
            'invoice_number' => 'LS-DASH-NORMAL',
            'user_id' => $owner->id,
            'sales_channel' => 'OFFLINE',
            'order_status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'subtotal' => 10000,
            'discount' => 0,
            'total' => 10000,
        ]);

        $normal->payment()->create([
            'verified_by' => $owner->id,
            'method' => 'CASH',
            'amount' => 10000,
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/owner/dashboard?period=today');

        $response->assertOk()
            ->assertJsonPath('sales.gross_total', 25000)
            ->assertJsonPath('sales.net_total', 10000)
            ->assertJsonPath('refunds.total', 15000);
    }
}
