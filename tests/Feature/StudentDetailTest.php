<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function actingAsManager(): static
    {
        return $this->actingAs($this->manager)->withSession(['active_branch_id' => $this->branch->id]);
    }

    public function test_manager_can_view_student_detail(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAsManager()->get(route('kitchen.students.show', $student));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('kitchen/students/show'));
    }

    public function test_manager_can_update_student_profile(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAsManager()->put(route('kitchen.students.update', $student), [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'grade_level' => 'Grade 5',
            'section' => 'Section B',
            'birthday' => '2015-06-01',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Updated']);
    }

    public function test_profile_update_strips_html_from_freetext_fields(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAsManager()->put(route('kitchen.students.update', $student), [
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

        $this->actingAsManager()->post(route('kitchen.students.regenerate-qr', $student));

        $student->refresh();
        $this->assertNotEquals('SB-ORIGINALCODE0', $student->qr_code);
        $this->assertStringStartsWith('SB-', $student->qr_code);
    }

    public function test_regenerated_qr_is_globally_unique(): void
    {
        $existingCode = 'SB-EXISTINGCODE0';
        Student::factory()->create(['branch_id' => $this->branch->id, 'qr_code' => $existingCode]);
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAsManager()->post(route('kitchen.students.regenerate-qr', $student));

        $student->refresh();
        $this->assertNotEquals($existingCode, $student->qr_code);
    }

    public function test_status_change_without_reason_for_non_requiring_status(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => EnrollmentStatus::Enrolled->value,
        ]);

        $response = $this->actingAsManager()->post(route('kitchen.students.update-status', $student), [
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);
    }

    public function test_status_change_to_banned_requires_reason(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAsManager()->post(route('kitchen.students.update-status', $student), [
            'enrollment_status' => EnrollmentStatus::Banned->value,
        ]);

        $response->assertSessionHasErrors('reason');
    }

    public function test_manager_can_soft_delete_student(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAsManager()->delete(route('kitchen.students.destroy', $student));

        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }
}
