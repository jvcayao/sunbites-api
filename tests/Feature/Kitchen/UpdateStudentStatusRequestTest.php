<?php

namespace Tests\Feature\Kitchen;

use App\Enums\EnrollmentStatus;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A missing or unknown status is a validation error, not a server error (Spec 16 B-14).
 */
class UpdateStudentStatusRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    /**
     * Debug mode is off, as in production and CI, so the body has no exception details.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->enrolled()->create(['branch_id' => $this->branch->id]);
    }

    public function test_missing_status_returns_422_not_500(): void
    {
        $response = $this->updateStatus([]);

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => ['enrollment_status' => ['The enrollment status field is required.']],
        ]);
        $this->assertSame(EnrollmentStatus::Enrolled, $this->student->fresh()->enrollment_status);
    }

    public function test_unknown_status_returns_422_not_500(): void
    {
        $response = $this->updateStatus(['enrollment_status' => 'expelled']);

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => ['enrollment_status' => ['The selected enrollment status is invalid.']],
        ]);
        $this->assertSame(EnrollmentStatus::Enrolled, $this->student->fresh()->enrollment_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateStatus(array $payload): TestResponse
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->patchJson("/api/v1/students/{$this->student->id}/status", $payload);
    }
}
