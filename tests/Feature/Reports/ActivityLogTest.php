<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
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

    public function test_supervisor_cannot_access_activity_log(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/activity');

        $response->assertForbidden();
    }

    public function test_admin_can_view_activity_log(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        activity('students')
            ->causedBy($this->admin)
            ->performedOn($student)
            ->log('students.updated');

        $response = $this->asAdmin()->getJson('/api/v1/reports/activity');

        $response->assertOk()->assertJsonStructure(['data', 'meta']);

        // The log includes both model-event entries and our manual entry — confirm ours is present
        $descriptions = collect($response->json('data'))->pluck('description')->all();
        $this->assertContains('students.updated', $descriptions);
    }

    public function test_manager_can_view_activity_log(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/activity');

        $response->assertOk();
    }

    public function test_activity_log_is_read_only(): void
    {
        // No POST/PUT/DELETE routes exist for activity log
        $response = $this->asAdmin()->postJson('/api/v1/reports/activity', []);

        $response->assertStatus(405); // Method Not Allowed
    }

    public function test_date_filter_returns_correct_entries(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        // Use a unique log_name to isolate entries from model-event noise
        $logEntry = activity('test_date_log')
            ->causedBy($this->admin)
            ->performedOn($student)
            ->log('test.old_entry');

        Activity::where('id', $logEntry->id)->update(['created_at' => now()->subDays(10)]);

        activity('test_date_log')
            ->causedBy($this->admin)
            ->performedOn($student)
            ->log('test.new_entry');

        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/activity?date_from='.now()->toDateString().'&log_name=test_date_log',
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('test.new_entry', $response->json('data.0.description'));
    }

    public function test_user_id_filter_returns_correct_causer(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        activity('students')->causedBy($this->admin)->performedOn($student)->log('students.updated');
        activity('students')->causedBy($this->manager)->performedOn($student)->log('students.deleted');

        $response = $this->asAdmin()->getJson("/api/v1/reports/activity?user_id={$this->manager->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->manager->full_name, $response->json('data.0.causer'));
    }

    public function test_log_name_filter_works(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        activity('students')->causedBy($this->admin)->performedOn($student)->log('students.updated');
        activity('pos')->causedBy($this->admin)->log('pos.order_voided');

        $response = $this->asAdmin()->getJson('/api/v1/reports/activity?log_name=students');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_filter_matches_description(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        activity('students')->causedBy($this->admin)->performedOn($student)->log('students.type_changed');
        activity('students')->causedBy($this->admin)->performedOn($student)->log('students.qr_regenerated');

        $response = $this->asAdmin()->getJson('/api/v1/reports/activity?search=type_changed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
