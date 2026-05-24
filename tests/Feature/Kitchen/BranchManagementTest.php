<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('staff', ['staff'])->plainTextToken;
    }

    private function managerToken(): string
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        return $manager->createToken('staff', ['staff'])->plainTextToken;
    }

    public function test_admin_can_list_branches_with_stats(): void
    {
        $response = $this->withToken($this->adminToken())
            ->getJson('/api/v1/branches');

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id', 'name', 'slug', 'address', 'gcash_number',
                    'is_active', 'staff_count', 'student_count', 'orders_today',
                ],
            ]);
    }

    public function test_non_admin_cannot_list_branches(): void
    {
        $response = $this->withToken($this->managerToken())
            ->getJson('/api/v1/branches');

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_list_branches(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }

    public function test_admin_can_update_branch(): void
    {
        $response = $this->withToken($this->adminToken())
            ->putJson("/api/v1/branches/{$this->branch->id}", [
                'name' => 'Updated Branch Name',
                'gcash_number' => '09111111111',
                'address' => '123 Test Street',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('branches', [
            'id' => $this->branch->id,
            'name' => 'Updated Branch Name',
            'gcash_number' => '09111111111',
            'address' => '123 Test Street',
        ]);
    }

    public function test_update_branch_requires_name(): void
    {
        $response = $this->withToken($this->adminToken())
            ->putJson("/api/v1/branches/{$this->branch->id}", [
                'name' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_non_admin_cannot_update_branch(): void
    {
        $response = $this->withToken($this->managerToken())
            ->putJson("/api/v1/branches/{$this->branch->id}", ['name' => 'Updated']);

        $response->assertForbidden();
    }

    public function test_admin_can_toggle_branch_to_inactive(): void
    {
        Branch::factory()->create(['is_active' => true]);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/branches/{$this->branch->id}/toggle");

        $response->assertOk()
            ->assertJson(['is_active' => false]);

        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'is_active' => false]);
    }

    public function test_admin_can_toggle_inactive_branch_back_to_active(): void
    {
        $inactive = Branch::factory()->create(['is_active' => false]);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/branches/{$inactive->id}/toggle");

        $response->assertOk()
            ->assertJson(['is_active' => true]);

        $this->assertDatabaseHas('branches', ['id' => $inactive->id, 'is_active' => true]);
    }

    public function test_admin_cannot_deactivate_last_active_branch(): void
    {
        Branch::query()->update(['is_active' => false]);
        $this->branch->refresh();
        $this->branch->update(['is_active' => true]);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/branches/{$this->branch->id}/toggle");

        $response->assertStatus(422)
            ->assertJson(['message' => 'At least one branch must remain active.']);

        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_toggle_branch(): void
    {
        $response = $this->withToken($this->managerToken())
            ->postJson("/api/v1/branches/{$this->branch->id}/toggle");

        $response->assertForbidden();
    }

    public function test_student_count_reflects_branch_students(): void
    {
        Student::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $response = $this->withToken($this->adminToken())
            ->getJson('/api/v1/branches');

        $response->assertOk();

        $branchData = collect($response->json())
            ->firstWhere('id', $this->branch->id);

        $this->assertEquals(3, $branchData['student_count']);
    }

    public function test_activity_is_logged_on_branch_update(): void
    {
        $this->withToken($this->adminToken())
            ->putJson("/api/v1/branches/{$this->branch->id}", [
                'name' => 'Logged Update Branch',
            ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'branches',
            'description' => 'branches.updated',
        ]);
    }

    public function test_activity_is_logged_on_branch_toggle(): void
    {
        Branch::factory()->create(['is_active' => true]);

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/branches/{$this->branch->id}/toggle");

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'branches',
            'description' => 'branches.toggled',
        ]);
    }
}
