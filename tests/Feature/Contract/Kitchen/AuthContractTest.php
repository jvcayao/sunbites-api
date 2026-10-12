<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Contract\ContractTestCase;

class AuthContractTest extends ContractTestCase
{
    public function test_staff_login_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->scenario->admin->email,
            'password' => 'Password1',
        ]);

        $this->assertContract($response, 'POST api/v1/auth/login');
    }

    public function test_staff_login_without_credentials_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertContract($response, 'POST api/v1/auth/login', '422-validation');
    }

    public function test_staff_login_with_a_wrong_password_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->scenario->admin->email,
            'password' => 'WrongPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/auth/login', '422-invalid-credentials');
    }

    public function test_staff_logout_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/auth/logout');

        $this->assertContract($response, 'POST api/v1/auth/logout');
    }

    public function test_current_staff_user_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/auth/user');

        $this->assertContract($response, 'GET api/v1/auth/user');
    }

    public function test_switching_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/auth/branch', [
            'branch_id' => $this->scenario->branchA->id,
            'from_branch_id' => $this->scenario->branchA->id,
        ]);

        $this->assertContract($response, 'POST api/v1/auth/branch');
    }

    public function test_switching_branch_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/auth/branch', []);

        $this->assertContract($response, 'POST api/v1/auth/branch', '422-validation');
    }

    public function test_switching_to_an_unassigned_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/auth/branch', [
            'branch_id' => $this->scenario->branchB->id,
        ]);

        $this->assertContract($response, 'POST api/v1/auth/branch', '403-not-assigned');
    }

    public function test_requesting_a_staff_reset_link_matches_contract(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/password/email', ['email' => $this->scenario->cashier->email]);

        $this->assertContract($response, 'POST api/v1/auth/password/email');
    }

    public function test_requesting_a_staff_reset_link_without_email_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/password/email', []);

        $this->assertContract($response, 'POST api/v1/auth/password/email', '422-validation');
    }

    public function test_resetting_a_staff_password_matches_contract(): void
    {
        $token = Password::createToken($this->scenario->cashier);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $this->scenario->cashier->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/auth/password/reset');
    }

    public function test_resetting_a_staff_password_without_input_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', []);

        $this->assertContract($response, 'POST api/v1/auth/password/reset', '422-validation');
    }

    public function test_resetting_a_staff_password_with_an_invalid_token_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'not-a-valid-token',
            'email' => $this->scenario->cashier->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/auth/password/reset', '422-invalid-token');
    }
}
