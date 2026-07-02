<?php

namespace Tests\Feature\Kitchen;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionDowngradeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asUserWithRole(string $role): static
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    // -----------------------------------------------------------------------
    // preview
    // -----------------------------------------------------------------------

    public function test_admin_can_preview_downgrade_with_mixed_payments(): void
    {
        $now = now();
        // Past paid month (cannot void)
        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => $now->year - 1,
            'amount' => 2970,
        ]);
        // Current month paid (voidable)
        $currentSchoolMonth = SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';
        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => $currentSchoolMonth,
            'year' => $now->year,
            'amount' => 2970,
        ]);
        // Future unpaid (to be deleted)
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'march',
            'year' => $now->year + 1,
            'amount' => 945,
        ]);

        $response = $this->asAdmin()->getJson(
            "/api/v1/students/{$this->student->id}/subscription-downgrade-preview"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'paid_months_retained',
            'paid_voidable_months',
            'unpaid_months_to_delete',
            'unpaid_months_to_delete_count',
            'wallet_balance',
        ]);
        $this->assertCount(1, $response->json('paid_months_retained'));
        $this->assertCount(1, $response->json('paid_voidable_months'));
        $this->assertCount(1, $response->json('unpaid_months_to_delete'));
        $this->assertEquals(1, $response->json('unpaid_months_to_delete_count'));
    }

    public function test_preview_does_not_list_voided_payments_as_deletable(): void
    {
        $now = now();

        // A month that was voided *before* the downgrade (e.g. July was voided first).
        $voided = StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => $now->year,
            'status' => 'voided',
            'amount' => 2970,
            'voided_at' => now(),
        ]);

        // A genuinely unpaid month that SHOULD be listed for deletion.
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'august',
            'year' => $now->year,
            'amount' => 2970,
        ]);

        $response = $this->asAdmin()->getJson(
            "/api/v1/students/{$this->student->id}/subscription-downgrade-preview"
        );

        $response->assertOk();

        // The voided month must NOT be reported as something that will be deleted...
        $this->assertNotContains('July '.$now->year, $response->json('unpaid_months_to_delete'));
        $this->assertEquals(['August '.$now->year], $response->json('unpaid_months_to_delete'));
        $this->assertEquals(1, $response->json('unpaid_months_to_delete_count'));

        // ...nor be reported as retained/voidable paid history.
        $paidIds = array_merge(
            array_column($response->json('paid_months_retained'), 'id'),
            array_column($response->json('paid_voidable_months'), 'id'),
        );
        $this->assertNotContains($voided->id, $paidIds);
    }

    public function test_supervisor_can_access_preview(): void
    {
        $response = $this->asUserWithRole('supervisor')->getJson(
            "/api/v1/students/{$this->student->id}/subscription-downgrade-preview"
        );
        $response->assertOk();
    }

    public function test_preview_returns_422_for_non_subscription_student(): void
    {
        $nonSub = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson(
            "/api/v1/students/{$nonSub->id}/subscription-downgrade-preview"
        );

        $response->assertUnprocessable();
    }

    // -----------------------------------------------------------------------
    // execute
    // -----------------------------------------------------------------------

    public function test_admin_can_downgrade_subscription_student(): void
    {
        $now = now();
        $currentMonth = SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => $now->year - 1,
            'amount' => 2970,
        ]);
        $unpaid = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => $currentMonth,
            'year' => $now->year,
            'amount' => 2970,
        ]);

        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/downgrade-subscription"
        );

        $response->assertOk();
        $response->assertJsonPath('student_type', 'non_subscription');

        // Unpaid must be hard-deleted
        $this->assertDatabaseMissing('student_monthly_payments', ['id' => $unpaid->id]);

        // Past paid must remain
        $this->assertDatabaseCount('student_monthly_payments', 1);
        $this->assertDatabaseHas('students', [
            'id' => $this->student->id,
            'student_type' => 'non_subscription',
        ]);
    }

    public function test_downgrade_logs_activity_with_deleted_months(): void
    {
        $now = now();
        $currentMonth = SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => $currentMonth,
            'year' => $now->year,
            'amount' => 2970,
        ]);

        $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/downgrade-subscription"
        );

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Student::class,
            'subject_id' => $this->student->id,
            'description' => 'students.downgraded_to_non_subscription',
        ]);
    }

    public function test_downgrade_fails_if_student_is_not_subscription(): void
    {
        $nonSub = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$nonSub->id}/downgrade-subscription"
        );

        $response->assertUnprocessable();
    }

    public function test_supervisor_cannot_execute_downgrade(): void
    {
        $response = $this->asUserWithRole('supervisor')->postJson(
            "/api/v1/students/{$this->student->id}/downgrade-subscription"
        );

        $response->assertForbidden();
    }
}
