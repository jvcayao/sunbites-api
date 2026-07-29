<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreditTransactionSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_settlement_columns_exist_on_the_table(): void
    {
        $this->assertTrue(Schema::hasColumns('credit_transactions', [
            'branch_id',
            'payment_method',
            'reference_number',
            'wallet_transaction_id',
        ]));
    }

    public function test_a_settled_entry_persists_and_reads_back_every_new_column(): void
    {
        $entry = CreditTransaction::create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'type' => CreditTransactionType::Settled->value,
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Gcash->value,
            'reference_number' => 'GC7X92A',
            'wallet_transaction_id' => 4242,
            'notes' => 'Paid by mother at counter.',
            'performed_by' => $this->staff->id,
            'created_at' => now(),
        ]);

        $fresh = $entry->fresh();

        $this->assertSame($this->branch->id, $fresh->branch_id);
        $this->assertSame(CreditSettlementMethod::Gcash, $fresh->payment_method);
        $this->assertSame('GC7X92A', $fresh->reference_number);
        $this->assertSame(4242, (int) $fresh->wallet_transaction_id);
        $this->assertSame('150.00', $fresh->amount);
    }

    public function test_payment_method_is_cast_to_the_settlement_method_enum(): void
    {
        $entry = CreditTransaction::factory()->settled(CreditSettlementMethod::Cash)->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        $this->assertInstanceOf(CreditSettlementMethod::class, $entry->fresh()->payment_method);
        $this->assertFalse($entry->fresh()->payment_method->requiresReferenceNumber());
    }

    public function test_charged_and_waived_entries_store_a_null_payment_method(): void
    {
        $charged = CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        $waived = CreditTransaction::factory()->waived()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        $this->assertNull($charged->fresh()->payment_method);
        $this->assertNull($waived->fresh()->payment_method);
        $this->assertSame(CreditTransactionType::Waived, $waived->fresh()->type);
    }

    public function test_notes_accepts_a_thousand_character_waive_reason(): void
    {
        $reason = str_repeat('a', 1000);

        $entry = CreditTransaction::factory()->waived()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
            'notes' => $reason,
        ]);

        $this->assertSame($reason, $entry->fresh()->notes);
        $this->assertSame(1000, strlen($entry->fresh()->notes));
    }

    public function test_backfill_populates_branch_id_from_the_owning_student(): void
    {
        $entry = CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        DB::table('credit_transactions')->where('id', $entry->id)->update(['branch_id' => null]);
        $this->assertNull($entry->fresh()->branch_id);

        $migration = require base_path('database/migrations/2026_07_29_221611_backfill_branch_id_on_credit_transactions.php');
        $migration->up();

        $this->assertSame($this->branch->id, $entry->fresh()->branch_id);
    }

    public function test_backfill_leaves_entries_alone_when_the_branch_snapshot_is_already_set(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);

        $entry = CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $otherBranch->id,
            'performed_by' => $this->staff->id,
        ]);

        $migration = require base_path('database/migrations/2026_07_29_221611_backfill_branch_id_on_credit_transactions.php');
        $migration->up();

        $this->assertSame(
            $otherBranch->id,
            $entry->fresh()->branch_id,
            'The backfill must only touch null snapshots, never rewrite an existing one.'
        );
    }

    public function test_branch_snapshot_is_nullable_so_history_survives_a_missing_branch(): void
    {
        $entry = CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        DB::table('credit_transactions')->where('id', $entry->id)->update(['branch_id' => null]);

        $this->assertDatabaseHas('credit_transactions', ['id' => $entry->id, 'branch_id' => null]);
        $this->assertNull($entry->fresh()->branch_id);
    }

    public function test_the_model_does_not_apply_the_branch_global_scope(): void
    {
        CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);

        CreditTransaction::factory()->charged()->create([
            'student_id' => $otherStudent->id,
            'branch_id' => $otherBranch->id,
            'performed_by' => $this->staff->id,
        ]);

        app()->instance('active_branch', $this->branch);

        $this->assertSame(2, CreditTransaction::count(), 'Credit entries must not be branch-scoped; the portal ledger depends on it.');
    }
}
