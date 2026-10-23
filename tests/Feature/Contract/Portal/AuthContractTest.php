<?php

namespace Tests\Feature\Contract\Portal;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Contract\ContractTestCase;

class AuthContractTest extends ContractTestCase
{
    public function test_parent_login_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $this->scenario->parent->email,
            'password' => 'Password1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/login');
    }

    public function test_parent_login_without_credentials_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/login', []);

        $this->assertContract($response, 'POST api/v1/portal/auth/login', '422-validation');
    }

    public function test_parent_login_with_a_wrong_password_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $this->scenario->parent->email,
            'password' => 'WrongPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/login', '422-invalid-credentials');
    }

    public function test_unactivated_parent_login_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $this->scenario->unactivatedParent->email,
            'password' => 'Password1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/login', '401-not-activated');
    }

    public function test_disabled_parent_login_with_the_correct_password_matches_contract(): void
    {
        $this->scenario->parent->update(['disabled_at' => now()]);

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $this->scenario->parent->email,
            'password' => 'Password1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/login', '401-disabled');
    }

    public function test_disabled_parent_login_with_a_wrong_password_matches_contract(): void
    {
        $this->scenario->parent->update(['disabled_at' => now()]);

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $this->scenario->parent->email,
            'password' => 'WrongPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/login', '422-disabled-wrong-password');
    }

    public function test_parent_logout_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->postJson('/api/v1/portal/auth/logout');

        $this->assertContract($response, 'POST api/v1/portal/auth/logout');
    }

    public function test_requesting_a_parent_reset_link_matches_contract(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/portal/auth/password/email', ['email' => $this->scenario->parent->email]);

        $this->assertContract($response, 'POST api/v1/portal/auth/password/email');
    }

    public function test_requesting_a_parent_reset_link_without_email_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/password/email', []);

        $this->assertContract($response, 'POST api/v1/portal/auth/password/email', '422-validation');
    }

    public function test_setting_a_parent_password_matches_contract(): void
    {
        $token = Password::broker('parents')->createToken($this->scenario->unactivatedParent);

        $response = $this->postJson('/api/v1/portal/auth/password/reset', [
            'token' => $token,
            'email' => $this->scenario->unactivatedParent->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/password/reset');
    }

    public function test_setting_a_parent_password_without_input_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/password/reset', []);

        $this->assertContract($response, 'POST api/v1/portal/auth/password/reset', '422-validation');
    }

    public function test_setting_a_parent_password_with_an_invalid_token_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/password/reset', [
            'token' => 'not-a-valid-token',
            'email' => $this->scenario->unactivatedParent->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/auth/password/reset', '422-invalid-token');
    }
}
