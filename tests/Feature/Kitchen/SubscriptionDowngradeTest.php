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
}
