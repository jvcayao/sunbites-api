<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * StudentLedgerQuery combines walletLeg() and creditLeg() via UNION ALL, which is
 * positional — the two SELECT lists must have an identical column count and order.
 * Adding wallet_topup_voids' `voided`/`wallet_transaction_id` columns to only one leg
 * would break this at the database level (a column-count error), not in a way a PHP-level
 * diff review would catch. This test is the regression guard design.md calls out for that
 * exact risk: it proves the union still executes end-to-end after both legs changed
 * together, across a mix of every row type the union produces.
 */
class StudentLedgerUnionParityTest extends TestCase
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

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_the_union_executes_without_a_column_mismatch_across_a_mix_of_row_types(): void
    {
        // A deposit, a withdrawal, a voided deposit + its reversal, and a credit entry —
        // one row of every entry_type the union can produce.
        $this->student->deposit(50000);
        $this->student->withdraw(2000);

        $toVoid = $this->student->deposit(10000, ['payment_method' => 'cash', 'performed_by' => $this->admin->id]);
        $voider = User::factory()->create();
        $voider->assignRole('admin');
        $voider->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($voider, ['staff']);
        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$this->student->id}/wallet/top-ups/{$toVoid->id}/void", [
                'reason' => 'Union parity fixture.',
            ])->assertOk();

        app(CreditLedgerService::class)->charge($this->student, 15.00, 'union parity fixture', $this->admin);

        $response = $this->asAdmin()->getJson("/api/v1/students/{$this->student->id}/ledger?entry_type=all");

        $response->assertOk();
        $entryTypes = collect($response->json('data'))->pluck('entry_type')->all();

        $this->assertContains('deposit', $entryTypes);
        $this->assertContains('withdraw', $entryTypes);
        $this->assertContains('topup_voided', $entryTypes);
        $this->assertContains('credit_charged', $entryTypes);
        // deposit(50000), withdraw(2000), deposit+withdraw from the void (2 rows), credit charge.
        $this->assertCount(5, $entryTypes);
    }
}
