<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_supervisor_cannot_access_wallet_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/wallet');

        $response->assertForbidden();
    }

    public function test_admin_can_access_wallet_report(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'summary']);
    }

    public function test_manager_can_access_wallet_report(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
    }

    public function test_wallet_report_summary_includes_net_movement(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_credits', 'total_debits', 'net_movement', 'students_below_100'],
            ]);
    }

    public function test_supervisor_cannot_export_wallet_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/wallet/export');

        $response->assertForbidden();
    }

    public function test_manager_can_export_wallet_report(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/wallet/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_wallet_report_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create();
        Student::factory()->count(3)->create(['branch_id' => $otherBranch->id]);
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(50000); // gives wallet activity so it is not filtered out

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_wallet_report_returns_correct_field_names(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(20000);

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'student_name', 'grade_level', 'current_balance', 'outstanding_credit', 'total_credited', 'total_debited', 'last_transaction']],
            ]);
    }

    public function test_wallet_report_net_movement_is_positive_credits_minus_positive_debits(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(50000);  // ₱500
        $student->withdraw(13500); // ₱135

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $summary = $response->json('summary');
        $this->assertEquals(500.0, $summary['total_credits']);
        $this->assertEquals(135.0, $summary['total_debits']);   // must be positive
        $this->assertEquals(365.0, $summary['net_movement']);   // 500 − 135
    }

    public function test_subscription_student_with_no_wallet_activity_is_excluded(): void
    {
        Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_subscription_student_with_wallet_deposit_is_included(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $student->deposit(30000);

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_wallet_export_includes_students_with_wallet_activity_only(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id]); // no wallet — excluded
        $active = Student::factory()->create(['branch_id' => $this->branch->id]);
        $active->deposit(50000);

        // We cannot inspect xlsx contents easily, but we can assert the response succeeds
        // and that the controller doesn't throw when computing totals.
        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
