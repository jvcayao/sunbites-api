<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CreditSettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cashPayload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Cash->value,
        ], $overrides);
    }

    private function settleUrl(): string
    {
        return "/api/v1/students/{$this->student->id}/credit/settle";
    }

    public function test_admin_can_settle_credit(): void
    {
        $admin = $this->staffWithRole('admin');

        $response = $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload());

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'type' => CreditTransactionType::Settled->value,
            'payment_method' => CreditSettlementMethod::Cash->value,
        ]);
    }

    public function test_manager_can_settle_credit(): void
    {
        $manager = $this->staffWithRole('manager');

        $response = $this->asUser($manager)->postJson($this->settleUrl(), $this->cashPayload());

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
    }

    public function test_supervisor_can_settle_credit(): void
    {
        $supervisor = $this->staffWithRole('supervisor');

        $response = $this->asUser($supervisor)->postJson($this->settleUrl(), $this->cashPayload());

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
    }

    public function test_cashier_can_settle_credit_at_the_counter(): void
    {
        $cashier = $this->staffWithRole('cashier');

        $response = $this->asUser($cashier)->postJson($this->settleUrl(), $this->cashPayload());

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
    }

    public function test_credit_balance_is_zeroed_atomically(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload());

        $this->assertEquals(0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_credit_transaction_record_is_created_on_settlement(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload());

        $this->assertDatabaseCount('credit_transactions', 1);
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'amount' => '150.00',
            'type' => CreditTransactionType::Settled->value,
            'performed_by' => $admin->id,
        ]);
    }

    public function test_settling_zero_credit_balance_returns_error(): void
    {
        $admin = $this->staffWithRole('admin');
        $this->student->update(['credit_balance' => 0]);

        $response = $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload());

        $response->assertStatus(422);
        $response->assertJson(['message' => 'No outstanding credit to settle.']);
    }

    public function test_response_carries_the_settled_amount_and_both_balances(): void
    {
        $admin = $this->staffWithRole('admin');
        $this->student->deposit(20000);

        $response = $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload(['amount' => 60.00]));

        $response->assertOk()->assertJson([
            'message' => 'Credit settled.',
            'amount_settled' => 60.0,
            'credit_balance' => 90.0,
            'wallet_balance' => 200.0,
        ]);

        $response->assertJsonStructure([
            'transaction' => ['id', 'date', 'type', 'amount', 'payment_method', 'reference_number', 'note'],
        ]);
    }

    public function test_partial_settlement_leaves_the_remainder_outstanding(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload(['amount' => 40.25]))
            ->assertOk();

        $this->assertEquals(109.75, (float) $this->student->fresh()->credit_balance);
    }

    public function test_gcash_settlement_stores_its_reference_number(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Gcash->value,
            'reference_number' => 'GC7X92A',
        ])->assertOk();

        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'payment_method' => CreditSettlementMethod::Gcash->value,
            'reference_number' => 'GC7X92A',
        ]);
    }

    public function test_bank_transfer_settlement_stores_its_reference_number(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::BankTransfer->value,
            'reference_number' => 'BDO1234',
        ])->assertOk();

        $this->assertDatabaseHas('credit_transactions', [
            'payment_method' => CreditSettlementMethod::BankTransfer->value,
            'reference_number' => 'BDO1234',
        ]);
    }

    public function test_settling_from_wallet_debits_the_wallet_and_links_the_transaction(): void
    {
        $admin = $this->staffWithRole('admin');
        $this->student->deposit(50000);

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Wallet->value,
        ])->assertOk()->assertJson([
            'credit_balance' => 0.0,
            'wallet_balance' => 350.0,
        ]);

        $entry = CreditTransaction::where('student_id', $this->student->id)->sole();

        $this->assertSame(CreditSettlementMethod::Wallet, $entry->payment_method);
        $this->assertNotNull($entry->wallet_transaction_id);
    }

    public function test_settling_from_wallet_with_insufficient_balance_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');
        $this->student->deposit(4000);

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Wallet->value,
        ])->assertStatus(422);

        $this->assertEquals(150.0, (float) $this->student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_settling_more_than_outstanding_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload(['amount' => 150.01]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertEquals(150.0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_gcash_without_a_reference_number_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Gcash->value,
        ])->assertStatus(422)->assertJsonValidationErrors('reference_number');
    }

    public function test_a_non_alphanumeric_reference_number_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Gcash->value,
            'reference_number' => 'GC-7X/92',
        ])->assertStatus(422)->assertJsonValidationErrors('reference_number');
    }

    public function test_amount_and_payment_method_are_both_required(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'payment_method']);
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => 'cheque',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_notes_are_stripped_of_markup(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), $this->cashPayload([
            'note' => '<b>Paid</b> by mother',
        ]))->assertOk();

        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'notes' => 'Paid by mother',
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson($this->settleUrl(), $this->cashPayload())->assertStatus(401);
    }

    public function test_an_activity_log_entry_is_written_without_the_reference_number(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->settleUrl(), [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Gcash->value,
            'reference_number' => 'GC7X92A',
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'wallet',
            'description' => 'wallet.credit_settled',
            'subject_id' => $this->student->id,
            'causer_id' => $admin->id,
        ]);

        $activity = Activity::where('description', 'wallet.credit_settled')->sole();

        $this->assertEquals(150.0, $activity->properties['amount_settled']);
        $this->assertSame('gcash', $activity->properties['payment_method']);
        $this->assertArrayNotHasKey('reference_number', $activity->properties->toArray());
    }

    public function test_a_student_outside_the_active_branch_is_not_reachable(): void
    {
        $admin = $this->staffWithRole('admin');

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create([
            'branch_id' => $otherBranch->id,
            'credit_balance' => 100.00,
        ]);

        $this->asUser($admin)
            ->postJson("/api/v1/students/{$otherStudent->id}/credit/settle", $this->cashPayload(['amount' => 100.00]))
            ->assertStatus(404);
    }
}
