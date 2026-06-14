<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingReportTest extends TestCase
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

    private function createPayment(Student $student, string $status = 'unpaid', float $amount = 800.00): StudentMonthlyPayment
    {
        return StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'status' => $status,
            'amount' => $amount,
            'recorded_at' => $status === 'paid' ? now() : null,
            'recorded_by' => $status === 'paid' ? $this->admin->id : null,
        ]);
    }

    public function test_billing_report_returns_paginated_payments(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $this->createPayment($student, 'paid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'summary']);
    }

    public function test_billing_report_includes_student_full_name(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
        $this->createPayment($student);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertSame('Juan Dela Cruz', $response->json('data.0.student.full_name'));
    }

    public function test_default_school_month_and_year_filters_to_current_school_period(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        // Current period payment — should appear
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => strtolower(now()->format('F')),
            'year' => now()->month >= 6 ? now()->year : now()->year - 1,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        // Different month payment — must NOT appear
        $otherMonth = now()->month === 6 ? 'july' : 'june';
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => $otherMonth,
            'year' => now()->month >= 6 ? now()->year : now()->year - 1,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/billing');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_supervisor_can_view_billing_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/billing');

        $response->assertOk();
    }

    public function test_unpaid_records_appear_first(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid');
        $this->createPayment($student2, 'unpaid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertEquals('unpaid', $response->json('data.0.status'));
    }

    public function test_status_filter_returns_only_paid(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid');
        $this->createPayment($student2, 'unpaid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&status=paid");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_summary_collection_rate_is_calculated(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid', 800.00);
        $this->createPayment($student2, 'unpaid', 800.00);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertEquals(800.0, $response->json('summary.total_collected'));
        $this->assertEquals(800.0, $response->json('summary.total_outstanding'));
        $this->assertEquals(50.0, $response->json('summary.collection_rate'));
    }

    public function test_supervisor_cannot_export_billing_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/billing/export');

        $response->assertForbidden();
    }

    public function test_manager_can_export_billing_report(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/billing/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_grade_level_filter_works(): void
    {
        $grade1Student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 1',
        ]);
        $grade3Student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 3',
        ]);

        $this->createPayment($grade1Student);
        $this->createPayment($grade3Student);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&grade_level=Grade+1");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_by_first_name_returns_matching_students(): void
    {
        $year = now()->year;

        $maria = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
        $pedro = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $maria->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $pedro->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=maria");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Maria Santos', $response->json('data.0.student.full_name'));
    }

    public function test_search_by_student_number_returns_matching_student(): void
    {
        $year = now()->year;

        $target = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'STU-2026-001',
        ]);
        $other = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'STU-2026-002',
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $target->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $other->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=STU-2026-001");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_with_no_match_returns_empty_data(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=doesnotexist");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_recorded_by_filter_returns_only_payments_by_that_staff_member(): void
    {
        $year = now()->year;

        $student1 = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::create([
            'student_id' => $student1->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $student2->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->manager->id,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_by={$this->admin->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
