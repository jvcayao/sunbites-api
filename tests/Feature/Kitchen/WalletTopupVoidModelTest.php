<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTopupVoid;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WalletTopupVoidModelTest extends TestCase
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

    public function test_amount_columns_are_cast_to_float_comparable_decimals(): void
    {
        $void = WalletTopupVoid::factory()->fullyRecovered()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'original_amount' => 150.5,
            'voided_amount' => 150.5,
            'voided_by' => $this->staff->id,
        ]);

        $fresh = $void->fresh();

        $this->assertEquals(150.5, (float) $fresh->original_amount);
        $this->assertEquals(150.5, (float) $fresh->voided_amount);
        $this->assertEquals(0.0, (float) $fresh->shortfall_amount);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->created_at);
    }

    public function test_the_four_relations_resolve(): void
    {
        $creditEntry = CreditTransaction::factory()->charged()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'performed_by' => $this->staff->id,
        ]);

        $void = WalletTopupVoid::factory()->withShortfall()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'credit_transaction_id' => $creditEntry->id,
            'voided_by' => $this->staff->id,
        ]);

        $this->assertTrue($void->student->is($this->student));
        $this->assertTrue($void->branch->is($this->branch));
        $this->assertTrue($void->creditTransaction->is($creditEntry));
        $this->assertTrue($void->voidedBy->is($this->staff));
    }

    public function test_the_model_has_no_updated_at_timestamp(): void
    {
        $void = WalletTopupVoid::factory()->fullyRecovered()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'voided_by' => $this->staff->id,
        ]);

        $this->assertFalse($void->usesTimestamps());
        $this->assertArrayNotHasKey('updated_at', $void->fresh()->getAttributes());
    }

    public function test_the_model_does_not_apply_the_branch_global_scope(): void
    {
        WalletTopupVoid::factory()->fullyRecovered()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'voided_by' => $this->staff->id,
        ]);

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);

        WalletTopupVoid::factory()->fullyRecovered()->create([
            'student_id' => $otherStudent->id,
            'branch_id' => $otherBranch->id,
            'voided_by' => $this->staff->id,
        ]);

        app()->instance('active_branch', $this->branch);

        $this->assertSame(2, WalletTopupVoid::count(), 'Void records must not be branch-scoped; report queries filter explicitly.');
    }

    public function test_the_fully_recovered_factory_state_has_no_shortfall(): void
    {
        $void = WalletTopupVoid::factory()->fullyRecovered()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'voided_by' => $this->staff->id,
        ]);

        $this->assertEquals(0.0, (float) $void->shortfall_amount);
        $this->assertNull($void->credit_transaction_id);
        $this->assertEquals((float) $void->original_amount, (float) $void->voided_amount);
    }

    public function test_the_shortfall_factory_state_links_a_credit_transaction(): void
    {
        $void = WalletTopupVoid::factory()->withShortfall()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'voided_by' => $this->staff->id,
        ]);

        $this->assertGreaterThan(0, (float) $void->shortfall_amount);
        $this->assertNotNull($void->credit_transaction_id);
        $this->assertInstanceOf(CreditTransaction::class, $void->creditTransaction);
    }
}
