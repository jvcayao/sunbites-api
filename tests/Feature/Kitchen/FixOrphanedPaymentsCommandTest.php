<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class FixOrphanedPaymentsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
    }

    public function test_dry_run_makes_no_database_changes(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments')
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_deletes_unpaid_orphaned_payments(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_retains_paid_orphaned_payments(): void
    {
        $payment = StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_skips_voided_payments(): void
    {
        // No voided() factory state exists; set status directly.
        $payment = StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
            'status' => 'voided',
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_logs_activity_with_no_causer(): void
    {
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $activity = Activity::where('description', 'students.orphaned_payments_cleaned')
            ->where('subject_id', $this->student->id)
            ->first();

        $this->assertNotNull($activity, 'Expected activity log entry not found.');
        $this->assertNull($activity->causer_id);
        $this->assertEquals(1, $activity->properties['deleted_count']);
        $this->assertContains('july 2025', $activity->properties['deleted_months']);
    }

    public function test_student_with_no_orphaned_payments_is_skipped(): void
    {
        // student has no monthly payments at all
        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            Activity::where('description', 'students.orphaned_payments_cleaned')->count()
        );
    }

    public function test_branch_flag_restricts_scope_to_one_branch(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->nonSubscription()->create(['branch_id' => $otherBranch->id]);

        $otherPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $otherStudent->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $thisPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'august',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', [
            '--execute' => true,
            '--branch' => $this->branch->id,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('student_monthly_payments', ['id' => $thisPayment->id]);
        $this->assertDatabaseHas('student_monthly_payments', ['id' => $otherPayment->id]);
    }

    public function test_branch_flag_with_invalid_id_aborts_with_failure(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', [
            '--execute' => true,
            '--branch' => 99999,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_command_is_idempotent(): void
    {
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])->assertExitCode(0);
        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])->assertExitCode(0);

        $this->assertSame(
            1,
            Activity::where('description', 'students.orphaned_payments_cleaned')->count()
        );
    }
}
