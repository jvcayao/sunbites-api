<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wallet top-up never settles credit. This is a deliberate product decision, not an
 * oversight: it keeps the wallet ledger and the credit ledger independently auditable, and
 * it stops a parent's money for next week's lunches being consumed by an older debt without
 * a staff decision.
 *
 * These tests exist to stop a future contributor "fixing" the behaviour back into a
 * coupling. Credit is settled only through the deliberate settle and waive endpoints.
 */
class WalletTopUpCreditIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_wallet_top_up_deposits_the_full_amount_and_leaves_credit_untouched(): void
    {
        $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 500.00,
            'payment_method' => 'cash',
        ])->assertOk();

        $student = $this->student->fresh()->load('wallet');

        $this->assertEquals(500.0, (float) $student->wallet->balanceFloat, 'The full amount must land in the wallet.');
        $this->assertEquals(150.0, (float) $student->credit_balance, 'Top-up must never settle credit.');
    }

    public function test_wallet_top_up_creates_no_credit_ledger_entry(): void
    {
        $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 500.00,
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_a_top_up_larger_than_the_debt_still_leaves_the_debt_intact(): void
    {
        $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 5000.00,
            'payment_method' => 'gcash',
            'reference_number' => 'GC999',
        ])->assertOk();

        $this->assertEquals(150.0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_pos_inline_reload_leaves_credit_untouched(): void
    {
        $this->asAdmin()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $this->student->id,
            'amount' => 300.00,
            'payment_method' => 'cash',
        ])->assertOk();

        $student = $this->student->fresh()->load('wallet');

        $this->assertEquals(300.0, (float) $student->wallet->balanceFloat);
        $this->assertEquals(150.0, (float) $student->credit_balance, 'Inline reload must never settle credit.');
    }

    public function test_pos_inline_reload_creates_no_credit_ledger_entry(): void
    {
        $this->asAdmin()->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $this->student->id,
            'amount' => 300.00,
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_repeated_top_ups_never_erode_the_debt(): void
    {
        foreach ([100.00, 200.00, 300.00] as $amount) {
            $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
                'amount' => $amount,
                'payment_method' => 'cash',
            ])->assertOk();
        }

        $student = $this->student->fresh()->load('wallet');

        $this->assertEquals(600.0, (float) $student->wallet->balanceFloat);
        $this->assertEquals(150.0, (float) $student->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
