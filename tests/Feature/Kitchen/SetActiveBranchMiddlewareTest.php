<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SetActiveBranchMiddlewareTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function staffToken(User $user): string
    {
        return $user->createToken('staff', ['staff'])->plainTextToken;
    }

    public function test_middleware_binds_branch_for_admin_with_any_branch(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->withToken($this->staffToken($admin))
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertTrue(app()->bound('active_branch'));
        $this->assertEquals($this->branch->id, app('active_branch')->id);
    }

    public function test_middleware_binds_branch_for_assigned_user(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->withToken($this->staffToken($cashier))
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertTrue(app()->bound('active_branch'));
    }

    public function test_middleware_rejects_unassigned_branch_for_non_admin(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $other = Branch::factory()->create(['is_active' => true]);

        $response = $this->withToken($this->staffToken($cashier))
            ->withHeader('X-Branch-Id', (string) $other->id)
            ->getJson('/api/v1/auth/user');

        $response->assertForbidden();
    }

    public function test_middleware_skips_gracefully_when_no_header(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $response = $this->withToken($this->staffToken($cashier))
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertFalse(app()->bound('active_branch'));
    }

    public function test_middleware_ignores_nonexistent_branch_id(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $response = $this->withToken($this->staffToken($cashier))
            ->withHeader('X-Branch-Id', '99999')
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertFalse(app()->bound('active_branch'));
    }

    public function test_middleware_skips_inactive_branch(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $inactive = Branch::factory()->create(['is_active' => false]);
        $cashier->branches()->attach($inactive->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->withToken($this->staffToken($cashier))
            ->withHeader('X-Branch-Id', (string) $inactive->id)
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertFalse(app()->bound('active_branch'));
    }
}
