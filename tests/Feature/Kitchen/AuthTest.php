<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();
        $user->assignRole('cashier');

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $this->assertGuest();
    }

    public function test_user_with_no_branch_is_rejected_after_login(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        // HasBranch middleware should redirect to login
        $response->assertRedirect(route('login'));
    }

    public function test_user_with_single_branch_skips_selector(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->actingAs($user)->withSession([])->get(route('dashboard'));

        // HasBranch auto-sets the branch and passes through to dashboard
        $this->assertEquals($branch->id, session('active_branch_id'));
    }

    public function test_user_with_multiple_branches_is_redirected_to_selector(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('manager');
        $user->branches()->attach([$branch1->id => ['assigned_at' => now(), 'assigned_by' => null]]);
        $user->branches()->attach([$branch2->id => ['assigned_at' => now(), 'assigned_by' => null]]);

        $this->actingAs($user)->withSession([]);

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('branch-selector'));
    }

    public function test_admin_can_access_dashboard_without_branch_pivot(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->withSession(['active_branch_id' => $branch->id]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_branch_selector_stores_active_branch_in_session(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('manager');
        $user->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->actingAs($user);

        $response = $this->post(route('branch-selector.select'), ['branch_id' => $branch->id]);

        $this->assertEquals($branch->id, session('active_branch_id'));
        $response->assertRedirect(route('dashboard'));
    }

    public function test_user_cannot_select_unassigned_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->actingAs($user);

        $response = $this->post(route('branch-selector.select'), ['branch_id' => $otherBranch->id]);

        $response->assertForbidden();
    }

    public function test_password_policy_requires_uppercase_and_number(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->withSession(['active_branch_id' => 1]);

        $response = $this->post(route('kitchen.references.users.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'newuser@test.com',
            'password' => 'alllowercase',
            'password_confirmation' => 'alllowercase',
            'role' => 'cashier',
        ]);

        $response->assertSessionHasErrors(['password']);
    }
}
