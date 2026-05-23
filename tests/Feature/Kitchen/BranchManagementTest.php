<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession(['active_branch_id' => $this->branch->id]);
    }

    public function test_admin_can_view_branches(): void
    {
        $response = $this->actingAsAdmin()->get(route('kitchen.references.branches.index'));

        $response->assertOk();
    }

    public function test_non_admin_cannot_access_branch_management(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->actingAs($manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('kitchen.references.branches.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_update_branch(): void
    {
        $response = $this->actingAsAdmin()->put(route('kitchen.references.branches.update', $this->branch), [
            'name' => 'Updated Branch Name',
            'gcash_number' => '09111111111',
            'address' => '123 Test Street',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'id' => $this->branch->id,
            'name' => 'Updated Branch Name',
            'gcash_number' => '09111111111',
        ]);
    }

    public function test_admin_cannot_deactivate_last_active_branch(): void
    {
        Branch::query()->update(['is_active' => false]);
        $this->branch->refresh();
        $this->branch->update(['is_active' => true]);

        $response = $this->actingAsAdmin()->post(
            route('kitchen.references.branches.toggle-active', $this->branch)
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'is_active' => true]);
    }

    public function test_admin_can_deactivate_branch_when_another_is_active(): void
    {
        Branch::factory()->create(['is_active' => true]);

        $response = $this->actingAsAdmin()->post(
            route('kitchen.references.branches.toggle-active', $this->branch)
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'is_active' => false]);
    }

    public function test_user_can_switch_to_assigned_branch(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->actingAs($manager)->post(route('branch-selector.select'), [
            'branch_id' => $this->branch->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('active_branch_id', $this->branch->id);
    }

    public function test_user_cannot_switch_to_unassigned_branch(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $other = Branch::factory()->create(['is_active' => true]);

        $response = $this->actingAs($manager)->post(route('branch-selector.select'), [
            'branch_id' => $other->id,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_switch_to_any_active_branch(): void
    {
        $other = Branch::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin)->post(route('branch-selector.select'), [
            'branch_id' => $other->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('active_branch_id', $other->id);
    }
}
