<?php

namespace Tests\Feature\Kitchen;

use App\Mail\StaffResetPasswordMail;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * T-21 (design §16.5): proves the per-email ('login') and per-email-or-token
 * ('password-reset') rate-limit buckets in AppServiceProvider::configureDefaults(),
 * which AuthTest::test_rate_limiting_blocks_after_5_failed_attempts does not reach
 * (it only exhausts the per-IP bucket). Each attempt uses a distinct REMOTE_ADDR so
 * the per-IP bucket never fires, isolating the bucket under test.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function fromAddress(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_login_is_limited_per_email_across_ip_addresses(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        for ($i = 1; $i <= 10; $i++) {
            $response = $this->fromAddress("10.0.0.{$i}")->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPassword9',
            ]);

            $response->assertStatus(422);
        }

        $response = $this->fromAddress('10.0.0.11')->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword9',
        ]);

        $response->assertStatus(429);
    }

    public function test_login_limit_for_one_email_does_not_block_another_email(): void
    {
        $blocked = User::factory()->create();
        $blocked->assignRole('cashier');

        for ($i = 1; $i <= 10; $i++) {
            $this->fromAddress("10.0.1.{$i}")->postJson('/api/v1/auth/login', [
                'email' => $blocked->email,
                'password' => 'WrongPassword9',
            ]);
        }

        $this->fromAddress('10.0.1.11')->postJson('/api/v1/auth/login', [
            'email' => $blocked->email,
            'password' => 'WrongPassword9',
        ])->assertStatus(429);

        $other = User::factory()->create();
        $other->assignRole('cashier');

        $response = $this->fromAddress('10.0.1.12')->postJson('/api/v1/auth/login', [
            'email' => $other->email,
            'password' => 'WrongPassword9',
        ]);

        $response->assertStatus(422);
    }

    public function test_password_email_is_limited_per_email_across_ip_addresses(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->fromAddress("10.0.2.{$i}")->postJson('/api/v1/auth/password/email', [
                'email' => $user->email,
            ]);

            $response->assertOk();
        }

        $response = $this->fromAddress('10.0.2.6')->postJson('/api/v1/auth/password/email', [
            'email' => $user->email,
        ]);

        $response->assertStatus(429);
        Mail::assertQueued(StaffResetPasswordMail::class, 5);
    }

    public function test_password_reset_is_limited_per_email_across_ip_addresses(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->fromAddress("10.0.3.{$i}")->postJson('/api/v1/auth/password/reset', [
                'token' => 'not-a-real-token',
                'email' => $user->email,
                'password' => 'NewPassword1',
                'password_confirmation' => 'NewPassword1',
            ]);

            $response->assertStatus(422);
        }

        $response = $this->fromAddress('10.0.3.6')->postJson('/api/v1/auth/password/reset', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertStatus(429);
    }

    public function test_password_reset_without_email_is_limited_per_token_across_ip_addresses(): void
    {
        $token = 'shared-token-value';

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->fromAddress("10.0.4.{$i}")->postJson('/api/v1/auth/password/reset', [
                'token' => $token,
                'password' => 'NewPassword1',
                'password_confirmation' => 'NewPassword1',
            ]);

            $response->assertStatus(422);
        }

        $response = $this->fromAddress('10.0.4.6')->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertStatus(429);

        $response = $this->fromAddress('10.0.4.7')->postJson('/api/v1/auth/password/reset', [
            'token' => 'different-token-value',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertStatus(422);
    }
}
