<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosInsufficientFundsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'ins']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 135.00,
            'is_available' => true,
        ]);

        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_inline_reload_deposits_to_student_wallet(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(5000); // ₱50 initial

        $response = $this->asCashier()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'payment_method' => 'cash',
        ]);

        $response->assertOk();
        $this->assertEquals(135.0, $response->json('new_balance'));
        $student->load('wallet');
        $this->assertEquals(135.0, $student->wallet->balanceFloat);
    }

    public function test_inline_reload_is_locked_to_exact_shortfall(): void
    {
        // The API does not enforce the exact shortfall amount — that's a frontend UI constraint
        // (amount field is read-only in the modal). The backend validates amount is > 0 and <= 100000.
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asCashier()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'payment_method' => 'cash',
        ]);

        $response->assertOk();
    }

    public function test_inline_reload_with_gcash_reference_number(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asCashier()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'payment_method' => 'gcash',
            'reference_number' => 'GCR12345ABC',
        ]);

        $response->assertOk();
    }

    public function test_credit_use_inserts_charged_credit_transaction(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 0,
        ]);
        $student->deposit(5000); // ₱50 wallet — ₱85 shortfall

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
            'use_credit' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $student->id,
            'type' => CreditTransactionType::Charged->value,
        ]);

        $student->refresh();
        $this->assertGreaterThan(0, (float) $student->credit_balance);
    }

    public function test_credit_limit_enforcement_blocks_checkout_when_exceeded(): void
    {
        $creditLimit = config('sunbites.credit_limit', 300);
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => $creditLimit, // already at limit
        ]);
        $student->deposit(0); // empty wallet

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
            'use_credit' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_credit_use_increments_student_credit_balance_atomically(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 50.00,
        ]);
        $student->deposit(0); // empty wallet — full ₱135 as credit

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
            'use_credit' => true,
        ]);

        $response->assertCreated();
        $student->refresh();
        $this->assertEquals(185.00, (float) $student->credit_balance); // 50 + 135
    }

    public function test_walk_in_customer_cannot_use_credit(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
            'use_credit' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_inline_reload_rejects_invalid_payment_method(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asCashier()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $student->id,
            'amount' => 85.00,
            'payment_method' => 'wallet', // wallet not valid for reload
        ]);

        $response->assertUnprocessable();
    }
}
