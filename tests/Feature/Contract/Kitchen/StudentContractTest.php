<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class StudentContractTest extends ContractTestCase
{
    public function test_student_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/students');

        $this->assertContract($response, 'GET api/v1/students');
    }

    public function test_student_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson('/api/v1/students');

        $this->assertContract($response, 'GET api/v1/students', '422-no-branch');
    }

    public function test_deleted_student_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/students?deleted=1');

        $this->assertContract($response, 'GET api/v1/students', 'ok-deleted');
    }

    public function test_student_detail_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->studentUrl());

        $this->assertContract($response, 'GET api/v1/students/{student}', mapPaths: ['subscription_monthly_status.categories']);
    }

    public function test_other_branch_student_detail_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->otherBranchUrl());

        $this->assertContract($response, 'GET api/v1/students/{student}', 'other-branch');
    }

    public function test_updating_a_student_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->studentUrl(), $this->details());

        $this->assertContract($response, 'PUT api/v1/students/{student}');
    }

    public function test_updating_a_student_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->studentUrl(), []);

        $this->assertContract($response, 'PUT api/v1/students/{student}', '422-validation');
    }

    public function test_updating_an_other_branch_student_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->otherBranchUrl(), $this->details());

        $this->assertContract($response, 'PUT api/v1/students/{student}', 'other-branch');
    }

    public function test_deleting_a_student_matches_contract(): void
    {
        $response = $this->asSupervisor()->deleteJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}");

        $this->assertContract($response, 'DELETE api/v1/students/{student}');
    }

    public function test_deleting_an_other_branch_student_matches_contract(): void
    {
        $response = $this->asSupervisor()->deleteJson($this->otherBranchUrl());

        $this->assertContract($response, 'DELETE api/v1/students/{student}', 'other-branch');
    }

    public function test_student_photo_matches_contract(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put($this->scenario->subscriptionStudent->photo_path, UploadedFile::fake()->image('juan.jpg')->getContent());

        $response = $this->asSupervisor()->get($this->studentUrl('/photo'));

        $this->assertFileContract($response, 'GET api/v1/students/{student}/photo');
    }

    public function test_student_without_a_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asSupervisor()->getJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/photo");

        $this->assertContract($response, 'GET api/v1/students/{student}/photo', '404');
    }

    public function test_other_branch_student_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asSupervisor()->getJson($this->otherBranchUrl('/photo'));

        $this->assertContract($response, 'GET api/v1/students/{student}/photo', 'other-branch');
    }

    public function test_uploading_a_student_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asSupervisor()->postJson($this->studentUrl('/photo'), ['photo' => UploadedFile::fake()->image('juan.jpg')]);

        $this->assertContract($response, 'POST api/v1/students/{student}/photo');
    }

    public function test_uploading_a_student_photo_without_a_file_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('/photo'), []);

        $this->assertContract($response, 'POST api/v1/students/{student}/photo', '422-validation');
    }

    public function test_uploading_an_other_branch_student_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asSupervisor()->postJson($this->otherBranchUrl('/photo'), ['photo' => UploadedFile::fake()->image('ana.jpg')]);

        $this->assertContract($response, 'POST api/v1/students/{student}/photo', 'other-branch');
    }

    public function test_regenerating_a_qr_code_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('/regenerate-qr'));

        $this->assertContract($response, 'POST api/v1/students/{student}/regenerate-qr');
    }

    public function test_regenerating_an_other_branch_qr_code_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->otherBranchUrl('/regenerate-qr'));

        $this->assertContract($response, 'POST api/v1/students/{student}/regenerate-qr', 'other-branch');
    }

    public function test_restoring_a_deleted_student_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/students/{$this->scenario->deletedStudent->id}/restore");

        $this->assertContract($response, 'POST api/v1/students/{student}/restore');
    }

    public function test_restoring_a_student_that_is_not_deleted_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/restore");

        $this->assertContract($response, 'POST api/v1/students/{student}/restore', '422-not-deleted');
    }

    public function test_restoring_an_other_branch_student_matches_contract(): void
    {
        $this->scenario->branchBStudent->delete();

        $response = $this->asSupervisor()->postJson($this->otherBranchUrl('/restore'));

        $this->assertContract($response, 'POST api/v1/students/{student}/restore', 'other-branch');
    }

    public function test_changing_enrollment_status_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->studentUrl('/status'), ['enrollment_status' => 'paused']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/status');
    }

    public function test_banning_without_a_reason_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->studentUrl('/status'), ['enrollment_status' => 'banned']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/status', '422-validation');
    }

    public function test_changing_enrollment_status_without_a_status_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->studentUrl('/status'), []);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/status', '422-missing-status');
    }

    public function test_changing_an_other_branch_enrollment_status_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->otherBranchUrl('/status'), ['enrollment_status' => 'paused']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/status', 'other-branch');
    }

    public function test_upgrading_a_student_type_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/type", ['student_type' => 'subscription']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/type');
    }

    public function test_changing_to_an_unknown_student_type_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/type", ['student_type' => 'premium']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/type', '422-validation');
    }

    public function test_downgrading_through_the_type_endpoint_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->studentUrl('/type'), ['student_type' => 'non_subscription']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/type', '422-use-downgrade');
    }

    public function test_changing_an_other_branch_student_type_matches_contract(): void
    {
        $response = $this->asSupervisor()->patchJson($this->otherBranchUrl('/type'), ['student_type' => 'subscription']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/type', 'other-branch');
    }

    public function test_student_orders_match_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->studentUrl('/orders'));

        $this->assertContract($response, 'GET api/v1/students/{student}/orders');
    }

    public function test_student_orders_with_an_invalid_page_size_match_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->studentUrl('/orders?per_page=0'));

        $this->assertContract($response, 'GET api/v1/students/{student}/orders', '422-validation');
    }

    public function test_other_branch_student_orders_match_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->otherBranchUrl('/orders'));

        $this->assertContract($response, 'GET api/v1/students/{student}/orders', 'other-branch');
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    private function studentUrl(string $suffix = ''): string
    {
        return "/api/v1/students/{$this->scenario->subscriptionStudent->id}{$suffix}";
    }

    private function otherBranchUrl(string $suffix = ''): string
    {
        return "/api/v1/students/{$this->scenario->branchBStudent->id}{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        return [
            'first_name' => 'Juan Miguel',
            'last_name' => 'Dela Cruz',
            'student_number' => 'ANT-2026-001',
            'grade_level' => 'Grade 4',
            'section' => 'Rizal',
            'birthday' => '2017-04-12',
            'allergies' => 'Peanuts',
            'notes' => 'Moved up a grade.',
        ];
    }
}
