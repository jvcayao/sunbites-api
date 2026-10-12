<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class UserManagementContractTest extends ContractTestCase
{
    public function test_staff_list_matches_contract(): void
    {
        $response = $this->asManager()->getJson('/api/v1/users');

        $this->assertContract($response, 'GET api/v1/users');
    }

    public function test_staff_list_for_an_admin_matches_contract(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/users');

        $this->assertContract($response, 'GET api/v1/users', 'ok-as-admin');
    }

    public function test_staff_detail_matches_contract(): void
    {
        $response = $this->asManager()->getJson($this->userUrl());

        $this->assertContract($response, 'GET api/v1/users/{user}');
    }

    public function test_staff_detail_for_an_admin_matches_contract(): void
    {
        $response = $this->asAdmin()->getJson($this->userUrl());

        $this->assertContract($response, 'GET api/v1/users/{user}', 'ok-as-admin');
    }

    public function test_other_branch_staff_detail_matches_contract(): void
    {
        $response = $this->asManager()->getJson($this->otherBranchUrl());

        $this->assertContract($response, 'GET api/v1/users/{user}', 'other-branch');
    }

    public function test_creating_a_staff_member_matches_contract(): void
    {
        $response = $this->asManager()->postJson('/api/v1/users', $this->newStaff());

        $this->assertContract($response, 'POST api/v1/users');
    }

    public function test_creating_a_staff_member_without_input_matches_contract(): void
    {
        $response = $this->asManager()->postJson('/api/v1/users', []);

        $this->assertContract($response, 'POST api/v1/users', '422-validation');
    }

    public function test_manager_assigning_the_admin_role_matches_contract(): void
    {
        $response = $this->asManager()->postJson('/api/v1/users', $this->newStaff(['role' => 'admin']));

        $this->assertContract($response, 'POST api/v1/users', '403-admin-role');
    }

    public function test_updating_a_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->putJson($this->userUrl(), $this->staffDetails($this->scenario->cashier->email));

        $this->assertContract($response, 'PUT api/v1/users/{user}');
    }

    public function test_updating_a_staff_member_without_input_matches_contract(): void
    {
        $response = $this->asAdmin()->putJson($this->userUrl(), []);

        $this->assertContract($response, 'PUT api/v1/users/{user}', '422-validation');
    }

    public function test_updating_an_other_branch_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->putJson($this->otherBranchUrl(), $this->staffDetails($this->scenario->managerB->email, 'manager'));

        $this->assertContract($response, 'PUT api/v1/users/{user}', 'other-branch');
    }

    public function test_deactivating_a_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->userUrl('/deactivate'));

        $this->assertContract($response, 'POST api/v1/users/{user}/deactivate');
    }

    public function test_deactivating_yourself_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson("/api/v1/users/{$this->scenario->admin->id}/deactivate");

        $this->assertContract($response, 'POST api/v1/users/{user}/deactivate', '403-self');
    }

    public function test_deactivating_an_other_branch_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->otherBranchUrl('/deactivate'));

        $this->assertContract($response, 'POST api/v1/users/{user}/deactivate', 'other-branch');
    }

    public function test_reactivating_a_staff_member_matches_contract(): void
    {
        $this->deactivate($this->scenario->cashier);

        $response = $this->asAdmin()->postJson($this->userUrl('/reactivate'));

        $this->assertContract($response, 'POST api/v1/users/{user}/reactivate');
    }

    public function test_reactivating_an_other_branch_staff_member_matches_contract(): void
    {
        $this->deactivate($this->scenario->managerB);

        $response = $this->asAdmin()->postJson($this->otherBranchUrl('/reactivate'));

        $this->assertContract($response, 'POST api/v1/users/{user}/reactivate', 'other-branch');
    }

    public function test_sending_a_staff_password_reset_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asAdmin()->postJson($this->userUrl('/reset-password'));

        $this->assertContract($response, 'POST api/v1/users/{user}/reset-password');
    }

    public function test_sending_an_other_branch_staff_password_reset_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asAdmin()->postJson($this->otherBranchUrl('/reset-password'));

        $this->assertContract($response, 'POST api/v1/users/{user}/reset-password', 'other-branch');
    }

    public function test_assigning_a_branch_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->userUrl('/branches'), ['branch_id' => $this->scenario->branchB->id]);

        $this->assertContract($response, 'POST api/v1/users/{user}/branches');
    }

    public function test_assigning_a_branch_without_input_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->userUrl('/branches'), []);

        $this->assertContract($response, 'POST api/v1/users/{user}/branches', '422-validation');
    }

    public function test_assigning_a_branch_to_an_other_branch_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->otherBranchUrl('/branches'), ['branch_id' => $this->scenario->branchA->id]);

        $this->assertContract($response, 'POST api/v1/users/{user}/branches', 'other-branch');
    }

    public function test_removing_a_branch_matches_contract(): void
    {
        $response = $this->asAdmin()->deleteJson($this->userUrl("/branches/{$this->scenario->branchA->id}"));

        $this->assertContract($response, 'DELETE api/v1/users/{user}/branches/{branch}');
    }

    public function test_removing_a_branch_from_an_other_branch_staff_member_matches_contract(): void
    {
        $response = $this->asAdmin()->deleteJson($this->otherBranchUrl("/branches/{$this->scenario->branchB->id}"));

        $this->assertContract($response, 'DELETE api/v1/users/{user}/branches/{branch}', 'other-branch');
    }

    /**
     * The route admits managers, but `UserPolicy::update` requires `users.manage`, which only the
     * admin role has, so the successful request is the admin's.
     */
    public function test_uploading_a_staff_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asAdmin()->postJson($this->userUrl('/photo'), ['photo' => UploadedFile::fake()->image('carlo.jpg')]);

        $this->assertContract($response, 'POST api/v1/users/{user}/photo');
    }

    public function test_manager_uploading_a_staff_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asManager()->postJson($this->userUrl('/photo'), ['photo' => UploadedFile::fake()->image('carlo.jpg')]);

        $this->assertContract($response, 'POST api/v1/users/{user}/photo', '403-not-allowed');
    }

    public function test_uploading_a_staff_photo_without_a_file_matches_contract(): void
    {
        $response = $this->asAdmin()->postJson($this->userUrl('/photo'), []);

        $this->assertContract($response, 'POST api/v1/users/{user}/photo', '422-validation');
    }

    public function test_uploading_an_other_branch_staff_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asAdmin()->postJson($this->otherBranchUrl('/photo'), ['photo' => UploadedFile::fake()->image('bea.jpg')]);

        $this->assertContract($response, 'POST api/v1/users/{user}/photo', 'other-branch');
    }

    private function asAdmin(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->admin);
    }

    private function asManager(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->manager);
    }

    private function userUrl(string $suffix = ''): string
    {
        return "/api/v1/users/{$this->scenario->cashier->id}{$suffix}";
    }

    private function otherBranchUrl(string $suffix = ''): string
    {
        return "/api/v1/users/{$this->scenario->managerB->id}{$suffix}";
    }

    private function deactivate(User $user): void
    {
        $user->update(['is_active' => false]);
        $user->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newStaff(array $overrides = []): array
    {
        return [
            ...$this->staffDetails('dina.dizon@sunbites.test'),
            'first_name' => 'Dina',
            'last_name' => 'Dizon',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffDetails(string $email, string $role = 'cashier'): array
    {
        return [
            'first_name' => 'Carlo',
            'last_name' => 'Cruz',
            'middle_name' => 'Reyes',
            'nickname' => 'Carlo',
            'email' => $email,
            'birthday' => '1992-08-14',
            'gender' => 'male',
            'civil_status' => 'married',
            'phone' => '09171234567',
            'emergency_contact_name' => 'Rosa Cruz',
            'emergency_contact_phone' => '09179876543',
            'emergency_contact_relationship' => 'Spouse',
            'address_line' => '18 Sumulong Street',
            'city' => 'Antipolo',
            'province' => 'Rizal',
            'zip_code' => '1870',
            'position' => 'Cashier',
            'employment_type' => 'full_time',
            'date_hired' => '2025-01-06',
            'daily_rate' => 600,
            'sss_number' => '34-7654321-0',
            'pagibig_number' => '1212-0000-5656',
            'philhealth_number' => '12-000000001-2',
            'tin_number' => '123-000-789-000',
            'role' => $role,
            'branch_ids' => [$this->scenario->branchA->id],
        ];
    }
}
