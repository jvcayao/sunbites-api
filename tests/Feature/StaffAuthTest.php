<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_staff_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password'), 'is_active' => true]);
        $user->assignRole('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'first_name', 'last_name', 'email', 'roles', 'branches'],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password'), 'is_active' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => bcrypt('password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password'), 'is_active' => true]);
        $newToken = $user->createToken('staff-token', ['staff']);
        $plainText = $newToken->plainTextToken;
        $tokenId = $newToken->accessToken->id;

        $this->withToken($plainText)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);

        // The personal access token record must be gone from the database.
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_user_endpoint_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        $token = $user->createToken('staff-token', ['staff'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/auth/user');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $user->id,
                'email' => $user->email,
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
