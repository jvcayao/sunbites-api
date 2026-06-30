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

class BillingReportVoidedTest extends TestCase
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

    public function test_billing_report_excludes_voided_by_default(): void
    {
        StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'status' => 'voided',
            'amount' => 2970,
            'voided_at' => now(),
            'voided_by' => $this->admin->id,
            'void_reason' => 'Test.',
        ]);

        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/billing?year='.now()->year.'&school_month=june'
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_billing_report_can_filter_by_voided(): void
    {
        StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'status' => 'voided',
            'amount' => 2970,
            'voided_at' => now(),
            'voided_by' => $this->admin->id,
            'void_reason' => 'Test.',
        ]);

        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/billing?year='.now()->year.'&school_month=june&status=voided'
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
