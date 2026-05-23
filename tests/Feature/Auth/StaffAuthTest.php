<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_staff_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cashier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'first_name', 'last_name', 'email', 'roles', 'branches']])
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertStatus(422);
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $tokenResult = $user->createToken('staff-token', ['staff']);
        $tokenId = $tokenResult->accessToken->id;

        $this->withToken($tokenResult->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_user_endpoint_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('staff-token', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_parent_token_cannot_access_staff_routes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('parent-token', ['parent'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertForbidden();
    }
}
