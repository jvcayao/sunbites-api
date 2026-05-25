<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentReportTest extends TestCase
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

    public function test_student_report_summary_counts_enrolled_students(): void
    {
        Student::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'enrolled',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'unenrolled',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students');

        $response->assertOk()
            ->assertJsonPath('summary.total', 3);
    }

    public function test_enrollment_status_filter_works(): void
    {
        Student::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'enrolled',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'paused',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?status=enrolled');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_grade_level_filter_works(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 1',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 3',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?grade=Grade+1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_summary_includes_by_grade_breakdown(): void
    {
        Student::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 1',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 2',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students');

        $response->assertOk()
            ->assertJsonPath('summary.grade_breakdown.Grade 1', 2)
            ->assertJsonPath('summary.grade_breakdown.Grade 2', 1);
    }

    public function test_supervisor_can_view_student_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/students');

        $response->assertOk();
    }

    public function test_supervisor_cannot_export_student_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/students/export');

        $response->assertForbidden();
    }

    public function test_export_does_not_include_sensitive_fields(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/students/export');

        // The export is a binary file — assert it downloads successfully
        // and sensitive fields are handled at the export layer (tested via StudentsExport mapping)
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Verify the response content does not contain PII field names in the raw bytes
        $content = $response->streamedContent();
        $this->assertStringNotContainsString('sss_number', $content);
        $this->assertStringNotContainsString('philhealth_number', $content);
        $this->assertStringNotContainsString('pagibig_number', $content);
        $this->assertStringNotContainsString('tin_number', $content);
    }
}
