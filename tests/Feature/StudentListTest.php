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

class StudentListTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_student_list_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        Student::factory()->create(['branch_id' => $this->branch->id]);
        Student::withoutBranch()->create(Student::factory()->make(['branch_id' => $otherBranch->id])->toArray());

        $response = $this->asSupervisor()->getJson('/api/v1/students');

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_search_filter_works_by_name(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'last_name' => 'Santos']);
        Student::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Bob', 'last_name' => 'Cruz']);

        $response = $this->asSupervisor()->getJson('/api/v1/students?search=Ana');

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_search_filter_works_by_student_number(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'ANT-2025-001']);
        Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'ANT-2025-002']);

        $response = $this->asSupervisor()->getJson('/api/v1/students?search=ANT-2025-001');

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_status_filter_works(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'enrollment_status' => EnrollmentStatus::Enrolled->value]);
        Student::factory()->create(['branch_id' => $this->branch->id, 'enrollment_status' => EnrollmentStatus::Paused->value]);

        $response = $this->asSupervisor()->getJson('/api/v1/students?status=paused');

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_type_tab_filters_subscription_students(): void
    {
        Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asSupervisor()->getJson('/api/v1/students?type=subscription');

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_students_are_sorted_alphabetically_by_last_name(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'last_name' => 'Zapanta', 'first_name' => 'Ana']);
        Student::factory()->create(['branch_id' => $this->branch->id, 'last_name' => 'Aquino', 'first_name' => 'Bob']);

        $response = $this->asSupervisor()->getJson('/api/v1/students');

        $response->assertOk();
        $response->assertJsonPath('data.0.last_name', 'Aquino');
    }

    public function test_cashier_cannot_access_student_list(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson('/api/v1/students');

        $response->assertForbidden();
    }
}
