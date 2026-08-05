<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the two new LedgerEntryFormatter output fields, `voided` and
 * `wallet_transaction_id`, added for wallet top-up void (spec 15). The
 * wallet_transaction_id case is the one most likely to regress: it must stay
 * conditioned on transactions.type = 'deposit', not be exposed unconditionally —
 * otherwise ordinary purchase rows and the topup_voided reversal row itself would
 * wrongly look voidable.
 */
class LedgerEntryFormatterTest extends TestCase
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
        $this->admin = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function ledgerRows(): Collection
    {
        $response = $this->asAdmin()->getJson("/api/v1/students/{$this->student->id}/ledger?entry_type=all");
        $response->assertOk();

        return collect($response->json('data'));
    }

    public function test_a_plain_deposit_row_is_not_voided_and_carries_its_wallet_transaction_id(): void
    {
        $deposit = $this->student->deposit(10000, ['payment_method' => 'cash', 'performed_by' => $this->admin->id]);

        $row = $this->ledgerRows()->firstWhere('entry_type', 'deposit');

        $this->assertFalse($row['voided']);
        $this->assertSame($deposit->id, $row['wallet_transaction_id']);
    }

    public function test_a_voided_deposit_row_is_flagged_voided_but_still_carries_its_wallet_transaction_id(): void
    {
        $deposit = $this->student->deposit(10000, ['payment_method' => 'cash', 'performed_by' => $this->admin->id]);

        $voider = User::factory()->create();
        $voider->assignRole('admin');
        $voider->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($voider, ['staff']);
        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$this->student->id}/wallet/top-ups/{$deposit->id}/void", [
                'reason' => 'Formatter coverage.',
            ])->assertOk();

        $row = $this->ledgerRows()->firstWhere('entry_type', 'deposit');

        $this->assertTrue($row['voided']);
        $this->assertSame($deposit->id, $row['wallet_transaction_id']);
    }

    public function test_a_credit_charged_row_is_not_voided_and_has_a_null_wallet_transaction_id(): void
    {
        app(CreditLedgerService::class)->charge($this->student, 25.00, 'formatter coverage', $this->admin);

        $row = $this->ledgerRows()->firstWhere('entry_type', 'credit_charged');

        $this->assertFalse($row['voided']);
        $this->assertNull($row['wallet_transaction_id']);
    }

    public function test_an_ordinary_purchase_row_has_a_null_wallet_transaction_id(): void
    {
        $this->student->deposit(10000);
        $this->student->withdraw(2000);

        $row = $this->ledgerRows()->firstWhere('entry_type', 'withdraw');

        $this->assertNull($row['wallet_transaction_id'], 'A purchase row must never expose a voidable wallet_transaction_id.');
    }

    public function test_the_topup_voided_reversal_row_has_a_null_wallet_transaction_id_and_resolves_note_and_performer(): void
    {
        $deposit = $this->student->deposit(10000, ['payment_method' => 'cash', 'performed_by' => $this->admin->id]);

        $voider = User::factory()->create(['first_name' => 'Voiding', 'last_name' => 'Manager']);
        $voider->assignRole('manager');
        $voider->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($voider, ['staff']);
        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$this->student->id}/wallet/top-ups/{$deposit->id}/void", [
                'reason' => 'Entered the wrong amount.',
            ])->assertOk();

        $row = $this->ledgerRows()->firstWhere('entry_type', 'topup_voided');

        $this->assertNull($row['wallet_transaction_id'], 'The reversal row itself must not look voidable.');
        $this->assertFalse($row['voided'], 'The reversal row is not itself a voided deposit.');
        $this->assertSame('Entered the wrong amount.', $row['note']);
        $this->assertSame('Voiding Manager', $row['performed_by']);
    }
}
