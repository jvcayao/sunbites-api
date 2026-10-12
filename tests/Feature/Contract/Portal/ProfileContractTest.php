<?php

namespace Tests\Feature\Contract\Portal;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class ProfileContractTest extends ContractTestCase
{
    public function test_parent_profile_matches_contract(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/profile');

        $this->assertContract($response, 'GET api/v1/portal/profile');
    }

    public function test_updating_the_parent_profile_matches_contract(): void
    {
        $response = $this->asParent()->patchJson('/api/v1/portal/profile', [
            'first_name' => 'Elena',
            'phone' => '09181113333',
            'address' => '46 Rizal Street, Antipolo',
        ]);

        $this->assertContract($response, 'PATCH api/v1/portal/profile');
    }

    public function test_updating_the_parent_profile_with_a_long_name_matches_contract(): void
    {
        $response = $this->asParent()->patchJson('/api/v1/portal/profile', ['first_name' => str_repeat('a', 101)]);

        $this->assertContract($response, 'PATCH api/v1/portal/profile', '422-validation');
    }

    public function test_changing_the_parent_password_matches_contract(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'Password1',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/profile/change-password');
    }

    public function test_changing_the_parent_password_without_input_matches_contract(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', []);

        $this->assertContract($response, 'POST api/v1/portal/profile/change-password', '422-validation');
    }

    public function test_changing_the_parent_password_with_a_wrong_current_password_matches_contract(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'WrongPassword1',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/profile/change-password', '422-wrong-current-password');
    }

    public function test_uploading_a_parent_photo_matches_contract(): void
    {
        Storage::fake('public');

        $response = $this->asParent()->postJson('/api/v1/portal/profile/photo', ['photo' => UploadedFile::fake()->image('elena.jpg')]);

        $this->assertContract($response, 'POST api/v1/portal/profile/photo');
    }

    public function test_uploading_a_parent_photo_without_a_file_matches_contract(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/photo', []);

        $this->assertContract($response, 'POST api/v1/portal/profile/photo', '422-validation');
    }

    private function asParent(): TestCase
    {
        return $this->scenario->asParent($this->scenario->parent);
    }
}
