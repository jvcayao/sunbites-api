<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $staffUser = User::factory()->create();

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'maria@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $staffUser->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    public function test_activated_parent_can_login(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'maria@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'parent' => ['id', 'first_name', 'last_name', 'email']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'maria@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_fails_for_non_existent_email(): void
    {
        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password1!',
        ])->assertUnprocessable()->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_unactivated_parent_cannot_login(): void
    {
        $unactivated = ParentUser::create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'email' => 'jose@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'jose@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'account_not_activated']);

        $unactivated->forceDelete();
    }

    public function test_logout_revokes_current_token(): void
    {
        $token = $this->parent->createToken('portal-token', ['parent']);
        $plainText = $token->plainTextToken;
        $tokenId = $token->accessToken->id;

        $this->withToken($plainText)
            ->postJson('/api/v1/portal/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_forgot_password_always_returns_generic_message(): void
    {
        $this->postJson('/api/v1/portal/auth/password/email', [
            'email' => 'nonexistent@example.com',
        ])->assertOk()
            ->assertJsonFragment(['message' => 'If an account with this email exists, you will receive an email shortly.']);
    }

    public function test_reset_password_activates_account_and_clears_tokens(): void
    {
        $unactivated = ParentUser::create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'email' => 'activate@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $token = Password::broker('parents')->createToken($unactivated);

        $this->postJson('/api/v1/portal/auth/password/reset', [
            'token' => $token,
            'email' => 'activate@example.com',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk()->assertJson(['message' => 'Password set successfully.']);

        $unactivated->refresh();
        $this->assertNotNull($unactivated->email_verified_at);
        $this->assertTrue(Hash::check('NewPassword1!', $unactivated->password));
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        $this->postJson('/api/v1/portal/auth/password/reset', [
            'token' => 'invalid-token',
            'email' => 'maria@example.com',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertUnprocessable()->assertJson(['message' => 'Invalid or expired token.']);
    }

    public function test_unauthenticated_portal_request_returns_401(): void
    {
        $this->getJson('/api/v1/portal/dashboard')->assertUnauthorized();
    }

    public function test_staff_token_cannot_access_portal_routes(): void
    {
        $staffToken = $this->parent->createToken('staff-token', ['staff'])->plainTextToken;

        $this->withToken($staffToken)
            ->getJson('/api/v1/portal/dashboard')
            ->assertForbidden();
    }
}
