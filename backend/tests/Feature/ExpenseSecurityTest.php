<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasPermission;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(
        string $email,
        string $roleName = 'Owner',
        string $roleSlug = 'owner',
        array $permissions = []
    ): User {
        $role = Role::create([
            'name' => $roleName,
            'slug' => $roleSlug,
        ]);

        $user = User::create([
            'name' => 'Expense Test User',
            'email' => $email,
            'password' => 'TestPassword2026!',
            'role_id' => $role->id,
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucwords(str_replace('.', ' ', $slug))]
            );

            $user->permissions()->attach($permission->id);
        }

        return $user;
    }

    private function createCategory(
        bool $isActive = true
    ): ExpenseCategory {
        return ExpenseCategory::create([
            'name' => 'Kategori Pengeluaran Test',
            'slug' => 'kategori-pengeluaran-test-' . uniqid(),
            'description' => 'Kategori untuk pengujian.',
            'is_active' => $isActive,
        ]);
    }

    private function createExpense(
        User $user,
        ExpenseCategory $category
    ): Expense {
        return Expense::create([
            'expense_category_id' => $category->id,
            'user_id' => $user->id,
            'description' => 'Pengeluaran Test',
            'amount' => 25000,
            'expense_date' => now()->toDateString(),
            'notes' => 'Catatan awal.',
        ]);
    }

    public function test_owner_can_create_expense_and_audit_log(): void
    {
        $owner = $this->createUser(
            'expense-create@example.com'
        );

        $category = $this->createCategory();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Pembelian bahan baku',
                'amount' => 50000,
                'expense_date' => now()->toDateString(),
                'notes' => 'Pembelian bahan dapur.',
            ]);

        $response->assertCreated();

        $expenseId = $response->json('data.id');

        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'user_id' => $owner->id,
            'amount' => 50000,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'action' => 'CREATE_EXPENSE',
            'entity_type' => 'EXPENSE',
            'entity_id' => $expenseId,
        ]);

        $audit = AuditLog::where(
            'entity_id',
            $expenseId
        )->firstOrFail();

        $this->assertSame(
            50000,
            $audit->metadata['after']['amount']
        );
    }

    public function test_owner_can_update_expense_and_audit_before_after(): void
    {
        $owner = $this->createUser(
            'expense-update@example.com'
        );

        $category = $this->createCategory();

        $expense = $this->createExpense(
            $owner,
            $category
        );

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/expenses/{$expense->id}", [
                'description' => 'Pembelian bahan baku baru',
                'amount' => 75000,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'description' => 'Pembelian bahan baku baru',
            'amount' => 75000,
        ]);

        $audit = AuditLog::where(
            'action',
            'UPDATE_EXPENSE'
        )->where(
            'entity_id',
            $expense->id
        )->firstOrFail();

        $this->assertSame(
            25000,
            $audit->metadata['before']['amount']
        );

        $this->assertSame(
            75000,
            $audit->metadata['after']['amount']
        );
    }

    public function test_owner_can_delete_expense_and_audit_log_is_preserved(): void
    {
        $owner = $this->createUser(
            'expense-delete@example.com'
        );

        $category = $this->createCategory();

        $expense = $this->createExpense(
            $owner,
            $category
        );

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('expenses', [
            'id' => $expense->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'action' => 'DELETE_EXPENSE',
            'entity_type' => 'EXPENSE',
            'entity_id' => $expense->id,
        ]);

        $audit = AuditLog::where(
            'action',
            'DELETE_EXPENSE'
        )->where(
            'entity_id',
            $expense->id
        )->firstOrFail();

        $this->assertSame(
            25000,
            $audit->metadata['before']['amount']
        );
    }

    public function test_inactive_category_cannot_be_used_for_new_expense(): void
    {
        $owner = $this->createUser(
            'expense-inactive-category@example.com'
        );

        $category = $this->createCategory(false);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Pengeluaran kategori nonaktif',
                'amount' => 50000,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('expenses', [
            'expense_category_id' => $category->id,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'CREATE_EXPENSE',
            'entity_type' => 'EXPENSE',
        ]);
    }

    public function test_inactive_category_cannot_be_selected_during_update(): void
    {
        $owner = $this->createUser(
            'expense-update-category@example.com'
        );

        $activeCategory = $this->createCategory();

        $inactiveCategory = ExpenseCategory::create([
            'name' => 'Kategori Nonaktif',
            'slug' => 'kategori-nonaktif-test',
            'description' => 'Kategori nonaktif.',
            'is_active' => false,
        ]);

        $expense = $this->createExpense(
            $owner,
            $activeCategory
        );

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_category_id' => $inactiveCategory->id,
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'expense_category_id' => $activeCategory->id,
        ]);
    }

    public function test_expense_amount_must_be_positive(): void
    {
        $owner = $this->createUser(
            'expense-invalid-amount@example.com'
        );

        $category = $this->createCategory();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Nominal tidak valid',
                'amount' => 0,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_expense_notes_cannot_exceed_5000_characters(): void
    {
        $owner = $this->createUser(
            'expense-long-notes@example.com'
        );

        $category = $this->createCategory();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Catatan terlalu panjang',
                'amount' => 10000,
                'expense_date' => now()->toDateString(),
                'notes' => str_repeat('A', 5001),
            ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_staff_without_expense_permission_cannot_create_expense(): void
    {
        $staff = $this->createUser(
            'expense-no-permission@example.com',
            'Staff',
            'staff'
        );

        $category = $this->createCategory();

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Percobaan tanpa permission',
                'amount' => 10000,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_staff_with_expense_create_permission_can_create_expense(): void
    {
        $staff = $this->createUser(
            'expense-with-permission@example.com',
            'Staff',
            'staff',
            ['expense.create']
        );

        $category = $this->createCategory();

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Pengeluaran oleh staff',
                'amount' => 30000,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('expenses', [
            'user_id' => $staff->id,
            'description' => 'Pengeluaran oleh staff',
            'amount' => 30000,
        ]);
    }

    public function test_controller_rechecks_create_permission_without_middleware(): void
    {
        $staff = $this->createUser(
            'expense-controller-create@example.com',
            'Staff',
            'staff'
        );

        $category = $this->createCategory();

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($staff, 'sanctum')
            ->postJson('/api/expenses', [
                'expense_category_id' => $category->id,
                'description' => 'Percobaan create tanpa permission',
                'amount' => 10000,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'CREATE_EXPENSE',
            'entity_type' => 'EXPENSE',
        ]);
    }

    public function test_controller_rechecks_update_permission_without_middleware(): void
    {
        $staff = $this->createUser(
            'expense-controller-update@example.com',
            'Staff',
            'staff'
        );

        $category = $this->createCategory();

        $expense = $this->createExpense($staff, $category);

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($staff, 'sanctum')
            ->putJson("/api/expenses/{$expense->id}", [
                'description' => 'Percobaan update tanpa permission',
                'amount' => 50000,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'description' => 'Pengeluaran Test',
            'amount' => 25000,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'UPDATE_EXPENSE',
            'entity_type' => 'EXPENSE',
            'entity_id' => $expense->id,
        ]);
    }

    public function test_controller_rechecks_delete_permission_without_middleware(): void
    {
        $staff = $this->createUser(
            'expense-controller-delete@example.com',
            'Staff',
            'staff'
        );

        $category = $this->createCategory();

        $expense = $this->createExpense($staff, $category);

        $response = $this
            ->withoutMiddleware(EnsureUserHasPermission::class)
            ->actingAs($staff, 'sanctum')
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'description' => 'Pengeluaran Test',
            'amount' => 25000,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'DELETE_EXPENSE',
            'entity_type' => 'EXPENSE',
            'entity_id' => $expense->id,
        ]);
    }

    public function test_guest_cannot_access_expenses(): void
    {
        $response = $this->getJson('/api/expenses');

        $response->assertUnauthorized();
    }
}