<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->seed(PermissionSeeder::class);

        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('dashboard'));

        $response->assertOk();
    }
}
