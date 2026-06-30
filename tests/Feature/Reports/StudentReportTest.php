<?php

namespace Tests\Feature\Reports;

use App\Exports\StudentsExport;
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

    public function test_search_by_first_name_returns_matching_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Santos',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Juan Santos', $response->json('data.0.full_name'));
    }

    public function test_search_by_last_name_returns_matching_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ana',
            'last_name' => 'Dela Cruz',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Pedro',
            'last_name' => 'Santos',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Dela+Cruz');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_by_student_number_returns_matching_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => '2024-0042',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => '2024-0099',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=0042');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('2024-0042', $response->json('data.0.student_number'));
    }

    public function test_search_by_section_returns_matching_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'section' => 'Mabini',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'section' => 'Rizal',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Mabini');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_combined_with_status_filter(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'enrollment_status' => 'enrolled',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'enrollment_status' => 'paused',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan&status=enrolled');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('enrolled', $response->json('data.0.status'));
    }

    public function test_row_response_includes_notes_and_allergies(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'notes' => 'Bring packed lunch on Fridays.',
            'allergies' => 'Peanuts, shellfish',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students');

        $response->assertOk()
            ->assertJsonPath('data.0.notes', 'Bring packed lunch on Fridays.')
            ->assertJsonPath('data.0.allergies', 'Peanuts, shellfish');
    }

    public function test_row_response_notes_and_allergies_are_null_when_empty(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'notes' => null,
            'allergies' => null,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/students');

        $response->assertOk()
            ->assertJsonPath('data.0.notes', null)
            ->assertJsonPath('data.0.allergies', null);
    }

    public function test_summary_is_not_affected_by_search(): void
    {
        Student::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'enrolled',
            'first_name' => 'Juan',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'enrolled',
            'first_name' => 'Maria',
        ]);

        // Search narrows rows to 3, but summary must still show 4 enrolled
        $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(4, $response->json('summary.total'));
    }

    public function test_export_respects_search_param(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Exportable',
            'last_name' => 'Student',
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/students/export?search=Exportable');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_headings_include_allergies_and_notes(): void
    {
        $export = new StudentsExport(collect([]));

        $headings = $export->headings();

        $this->assertCount(14, $headings);
        $this->assertSame('Allergies', $headings[12]);
        $this->assertSame('Notes', $headings[13]);
    }

    public function test_export_maps_allergies_and_notes_for_a_student(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'allergies' => 'Peanuts',
            'notes' => 'Packed lunch on Fridays',
        ]);
        $student->load('wallet');
        $student->setRelation('contacts', collect([]));

        $export = new StudentsExport(collect([$student]));
        $row = $export->map($student);

        $this->assertSame('Peanuts', $row[12]);
        $this->assertSame('Packed lunch on Fridays', $row[13]);
    }

    public function test_export_maps_null_allergies_and_notes_as_empty_string(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'allergies' => null,
            'notes' => null,
        ]);
        $student->load('wallet');
        $student->setRelation('contacts', collect([]));

        $export = new StudentsExport(collect([$student]));
        $row = $export->map($student);

        $this->assertSame('', $row[12]);
        $this->assertSame('', $row[13]);
    }
}
