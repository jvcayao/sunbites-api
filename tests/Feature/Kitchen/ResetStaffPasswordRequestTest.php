<?php

namespace Tests\Feature\Kitchen;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The reset still hands the broker the raw `email`, `password`, `password_confirmation` and
 * `token` input, not the validated subset (Spec 16 T-6.1, hazard H7).
 */
class ResetStaffPasswordRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_reset_link_changes_the_password(): void
    {
        $user = User::factory()->create(['email' => 'cook@sunbites.test']);
        $user->createToken('staff-token', ['staff']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'cook@sunbites.test',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Your password has been reset.']);
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword1', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
