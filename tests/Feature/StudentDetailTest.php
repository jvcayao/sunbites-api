<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_manager_can_view_student_detail(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $response->assertJsonStructure(['student', 'wallet_transactions', 'activity_logs']);
    }

    public function test_manager_can_update_student_profile(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'grade_level' => 'Grade 5',
            'section' => 'Section B',
            'birthday' => '2015-06-01',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Updated']);
    }

    public function test_profile_update_strips_html_from_freetext_fields(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->asManager()->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 3',
            'birthday' => '2015-01-01',
            'allergies' => '<b>Peanuts</b>',
            'notes' => '<script>evil()</script>Note',
        ]);

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'allergies' => 'Peanuts',
            'notes' => 'evil()Note',
        ]);
    }

    public function test_qr_regeneration_replaces_old_code(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'qr_code' => 'SB-ORIGINALCODE0']);

        $response = $this->asManager()->postJson("/api/v1/students/{$student->id}/regenerate-qr");

        $response->assertOk();
        $this->assertStringStartsWith('SB-', $response->json('qr_code'));
        $this->assertNotEquals('SB-ORIGINALCODE0', $response->json('qr_code'));
    }

    public function test_regenerated_qr_is_globally_unique(): void
    {
        $existingCode = 'SB-EXISTINGCODE0';
        Student::factory()->create(['branch_id' => $this->branch->id, 'qr_code' => $existingCode]);
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->postJson("/api/v1/students/{$student->id}/regenerate-qr");

        $response->assertOk();
        $this->assertNotEquals($existingCode, $response->json('qr_code'));
    }

    public function test_status_change_without_reason_for_non_requiring_status(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => EnrollmentStatus::Enrolled->value,
        ]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/status", [
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);
    }

    public function test_status_change_to_banned_requires_reason(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/status", [
            'enrollment_status' => EnrollmentStatus::Banned->value,
        ]);

        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_manager_can_soft_delete_student(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->deleteJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_manager_can_downgrade_subscription_student_to_wallet(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'non_subscription',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'student_type' => 'non_subscription',
        ]);
    }

    public function test_manager_can_upgrade_wallet_student_to_subscription(): void
    {
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'subscription',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'student_type' => 'subscription',
        ]);
    }

    public function test_invalid_student_type_is_rejected(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'premium',
        ]);

        $response->assertUnprocessable();
    }
}
