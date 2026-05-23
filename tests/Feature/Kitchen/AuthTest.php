<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function staffToken(User $user): string
    {
        return $user->createToken('staff', ['staff'])->plainTextToken;
    }

    public function test_staff_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'roles', 'branches'],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword9',
        ]);

        $response->assertUnprocessable();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();
        $user->assignRole('cashier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response->assertUnprocessable();
    }

    public function test_rate_limiting_blocks_after_5_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPassword9',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword9',
        ]);

        $response->assertStatus(429);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('staff', ['staff']);
        $tokenId = $token->accessToken->id;

        $response = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_user_endpoint_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $token = $this->staffToken($user);

        $response = $this->withToken($token)->getJson('/api/v1/auth/user');

        $response->assertOk()
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    }

    public function test_user_with_no_branch_assignment_can_still_login(): void
    {
        // Branch check is enforced at the middleware/header level, not at login time.
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_set_active_branch_middleware_rejects_unassigned_branch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $token = $this->staffToken($user);

        $response = $this->withToken($token)
            ->withHeader('X-Branch-Id', (string) $otherBranch->id)
            ->getJson('/api/v1/auth/user');

        $response->assertForbidden();
    }

    public function test_set_active_branch_middleware_accepts_assigned_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $token = $this->staffToken($user);

        $response = $this->withToken($token)
            ->withHeader('X-Branch-Id', (string) $branch->id)
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
    }

    public function test_admin_can_access_any_branch_via_header(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $anyBranch = Branch::factory()->create(['is_active' => true]);
        $token = $this->staffToken($admin);

        // Admin has access.any_branch permission — the middleware must not return 403.
        $response = $this->withToken($token)
            ->withHeader('X-Branch-Id', (string) $anyBranch->id)
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
    }
}
