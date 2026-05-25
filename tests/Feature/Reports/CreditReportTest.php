<?php

namespace Tests\Feature\Reports;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditReportTest extends TestCase
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

    private function createCreditTransaction(Student $student, CreditTransactionType $type, float $amount = 100.00): CreditTransaction
    {
        return CreditTransaction::create([
            'student_id' => $student->id,
            'order_id' => null,
            'type' => $type,
            'amount' => $amount,
            'notes' => "Test {$type->value} transaction.",
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);
    }

    public function test_supervisor_cannot_access_credit_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/credits');

        $response->assertForbidden();
    }

    public function test_admin_can_access_credit_report(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/reports/credits');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'summary']);
    }

    public function test_manager_can_access_credit_report(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/credits');

        $response->assertOk();
    }

    public function test_summary_totals_are_correct(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createCreditTransaction($student, CreditTransactionType::Charged, 200.00);
        $this->createCreditTransaction($student, CreditTransactionType::Settled, 150.00);
        $this->createCreditTransaction($student, CreditTransactionType::Voided, 50.00);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits');

        $response->assertOk();
        $this->assertEquals(200.0, $response->json('summary.total_charged'));
        $this->assertEquals(150.0, $response->json('summary.total_settled'));
        $this->assertEquals(50.0, $response->json('summary.total_voided'));
        $this->assertEquals(0.0, $response->json('summary.net_outstanding'));
    }

    public function test_type_filter_returns_only_charged_transactions(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createCreditTransaction($student, CreditTransactionType::Charged);
        $this->createCreditTransaction($student, CreditTransactionType::Settled);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits?type=charged');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('charged', $response->json('data.0.type'));
    }

    public function test_credit_report_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);
        $thisStudent = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createCreditTransaction($otherStudent, CreditTransactionType::Charged);
        $this->createCreditTransaction($thisStudent, CreditTransactionType::Charged);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_date_filter_works(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $oldTx = $this->createCreditTransaction($student, CreditTransactionType::Charged);
        CreditTransaction::where('id', $oldTx->id)->update(['created_at' => now()->subDays(30)]);

        $this->createCreditTransaction($student, CreditTransactionType::Charged);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits?date_from='.now()->toDateString());

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_net_outstanding_reflects_charged_minus_settled_and_voided(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createCreditTransaction($student, CreditTransactionType::Charged, 500.00);
        $this->createCreditTransaction($student, CreditTransactionType::Settled, 200.00);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits');

        $response->assertOk();
        $this->assertEquals(300.0, $response->json('summary.net_outstanding'));
    }
}
