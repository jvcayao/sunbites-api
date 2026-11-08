<?php

namespace Tests\Feature\Contract\Portal;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class StudentPhotoContractTest extends ContractTestCase
{
    private const string SHOW_ROUTE = 'GET api/v1/portal/students/{student}/photo';

    private const string STORE_ROUTE = 'POST api/v1/portal/students/{student}/photo';

    public function test_student_photo_matches_contract(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put($this->scenario->subscriptionStudent->photo_path, UploadedFile::fake()->image('juan.jpg')->getContent());

        $response = $this->asParent()->get($this->photoUrl($this->scenario->subscriptionStudent->id));

        $this->assertFileContract($response, self::SHOW_ROUTE);
    }

    public function test_linked_student_without_a_photo_matches_contract(): void
    {
        Storage::fake('private');
        $this->scenario->parent->students()->attach($this->scenario->nonSubscriptionStudent->id, [
            'wallet_alert_threshold' => 0,
            'linked_at' => now(),
            'linked_by' => $this->scenario->admin->id,
        ]);

        $response = $this->asParent()->getJson($this->photoUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::SHOW_ROUTE, '404');
    }

    public function test_unlinked_student_photo_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->photoUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::SHOW_ROUTE, '403');
    }

    public function test_other_branch_student_photo_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->photoUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::SHOW_ROUTE, 'other-branch');
    }

    public function test_uploading_a_student_photo_matches_contract(): void
    {
        Storage::fake('private');

        $response = $this->asParent()->postJson($this->photoUrl($this->scenario->subscriptionStudent->id), [
            'photo' => UploadedFile::fake()->image('juan.jpg'),
        ]);

        $this->assertContract($response, self::STORE_ROUTE);
    }

    public function test_uploading_a_student_photo_without_a_file_matches_contract(): void
    {
        $response = $this->asParent()->postJson($this->photoUrl($this->scenario->subscriptionStudent->id), []);

        $this->assertContract($response, self::STORE_ROUTE, '422-validation');
    }

    public function test_uploading_an_unlinked_student_photo_matches_contract(): void
    {
        $response = $this->asParent()->postJson($this->photoUrl($this->scenario->nonSubscriptionStudent->id), [
            'photo' => UploadedFile::fake()->image('maria.jpg'),
        ]);

        $this->assertContract($response, self::STORE_ROUTE, '403');
    }

    public function test_uploading_an_other_branch_student_photo_matches_contract(): void
    {
        $response = $this->asParent()->postJson($this->photoUrl($this->scenario->branchBStudent->id), [
            'photo' => UploadedFile::fake()->image('ana.jpg'),
        ]);

        $this->assertContract($response, self::STORE_ROUTE, 'other-branch');
    }

    private function asParent(): TestCase
    {
        return $this->scenario->asParent($this->scenario->parent);
    }

    private function photoUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/photo";
    }
}
