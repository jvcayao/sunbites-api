<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRestoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        return $this->asUser($this->admin);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_admin_can_restore_soft_deleted_student(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->delete();

        $response = $this->asAdmin()->postJson("/api/v1/students/{$student->id}/restore");

        $response->assertOk();
        $response->assertJsonPath('deleted_at', null);
        $this->assertNull($student->fresh()->deleted_at);

        // Restored student appears in the active list
        $listResponse = $this->asAdmin()->getJson('/api/v1/students');
        $listResponse->assertOk();
        $ids = collect($listResponse->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($student->id));
    }

    public function test_restore_logs_students_restored(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->delete();

        $this->asAdmin()->postJson("/api/v1/students/{$student->id}/restore");

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'students',
            'description' => 'students.restored',
            'subject_type' => Student::class,
            'subject_id' => $student->id,
        ]);
    }

    public function test_cashier_cannot_restore_student(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->delete();

        $response = $this->asUser($cashier)->postJson("/api/v1/students/{$student->id}/restore");

        $response->assertForbidden();
    }

    public function test_restoring_active_student_returns_422(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->postJson("/api/v1/students/{$student->id}/restore");

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'Student is not deleted.');
    }
}
