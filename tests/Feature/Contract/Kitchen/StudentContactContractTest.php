<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Models\StudentContact;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class StudentContactContractTest extends ContractTestCase
{
    public function test_contact_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson($this->contactsUrl());

        $this->assertContract($response, 'GET api/v1/students/{student}/contacts');
    }

    public function test_contact_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson($this->contactsUrl());

        $this->assertContract($response, 'GET api/v1/students/{student}/contacts', '422-no-branch');
    }

    public function test_other_branch_contact_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson("/api/v1/students/{$this->scenario->branchBStudent->id}/contacts");

        $this->assertContract($response, 'GET api/v1/students/{student}/contacts', 'other-branch');
    }

    public function test_adding_a_contact_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asSupervisor()->postJson($this->contactsUrl(), $this->contact());

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts');
    }

    public function test_adding_a_contact_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->contactsUrl(), []);

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts', '422-validation');
    }

    public function test_adding_a_fourth_contact_matches_contract(): void
    {
        StudentContact::factory()->create([
            'student_id' => $this->scenario->subscriptionStudent->id,
            'full_name' => 'Tita Dela Cruz',
            'relationship' => 'Aunt',
            'phone' => '09181119999',
            'address' => '45 Rizal Street, Antipolo',
            'email' => null,
            'is_primary' => false,
        ]);

        $response = $this->asSupervisor()->postJson($this->contactsUrl(), $this->contact());

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts', '422-max-contacts');
    }

    public function test_adding_an_other_branch_contact_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asSupervisor()->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/contacts", $this->contact());

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts', 'other-branch');
    }

    public function test_updating_a_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->contactsUrl($this->scenario->primaryContact->id), ['phone' => '09181110000']);

        $this->assertContract($response, 'PUT api/v1/students/{student}/contacts/{contact}');
    }

    public function test_updating_a_contact_with_an_invalid_email_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->contactsUrl($this->scenario->primaryContact->id), ['email' => 'not-an-email']);

        $this->assertContract($response, 'PUT api/v1/students/{student}/contacts/{contact}', '422-validation');
    }

    public function test_updating_another_students_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson($this->contactsUrl($this->scenario->nonSubscriptionContact->id), ['phone' => '09181110000']);

        $this->assertContract($response, 'PUT api/v1/students/{student}/contacts/{contact}', '404-wrong-student');
    }

    public function test_updating_an_other_branch_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->putJson(
            "/api/v1/students/{$this->scenario->branchBStudent->id}/contacts/{$this->scenario->branchBContact->id}",
            ['phone' => '09181110000'],
        );

        $this->assertContract($response, 'PUT api/v1/students/{student}/contacts/{contact}', 'other-branch');
    }

    public function test_deleting_a_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->deleteJson($this->contactsUrl($this->scenario->secondaryContact->id));

        $this->assertContract($response, 'DELETE api/v1/students/{student}/contacts/{contact}');
    }

    public function test_deleting_another_students_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->deleteJson($this->contactsUrl($this->scenario->nonSubscriptionContact->id));

        $this->assertContract($response, 'DELETE api/v1/students/{student}/contacts/{contact}', '404-wrong-student');
    }

    public function test_deleting_an_other_branch_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->deleteJson("/api/v1/students/{$this->scenario->branchBStudent->id}/contacts/{$this->scenario->branchBContact->id}");

        $this->assertContract($response, 'DELETE api/v1/students/{student}/contacts/{contact}', 'other-branch');
    }

    public function test_resending_contact_activation_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asSupervisor()->postJson($this->contactsUrl($this->scenario->primaryContact->id).'/resend-activation');

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts/{contact}/resend-activation');
    }

    public function test_resending_activation_to_a_contact_without_email_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->contactsUrl($this->scenario->secondaryContact->id).'/resend-activation');

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts/{contact}/resend-activation', '422-no-email');
    }

    public function test_resending_activation_to_a_contact_without_a_portal_account_matches_contract(): void
    {
        $this->scenario->secondaryContact->update(['email' => 'jose.delacruz@example.test']);

        $response = $this->asSupervisor()->postJson($this->contactsUrl($this->scenario->secondaryContact->id).'/resend-activation');

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts/{contact}/resend-activation', '404-no-portal-account');
    }

    public function test_resending_activation_to_another_students_contact_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->contactsUrl($this->scenario->nonSubscriptionContact->id).'/resend-activation');

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts/{contact}/resend-activation', '404-wrong-student');
    }

    public function test_resending_activation_to_an_other_branch_contact_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asSupervisor()
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/contacts/{$this->scenario->branchBContact->id}/resend-activation");

        $this->assertContract($response, 'POST api/v1/students/{student}/contacts/{contact}/resend-activation', 'other-branch');
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    private function contactsUrl(?int $contactId = null): string
    {
        $url = "/api/v1/students/{$this->scenario->subscriptionStudent->id}/contacts";

        return $contactId === null ? $url : "{$url}/{$contactId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(): array
    {
        return [
            'full_name' => 'Lola Dela Cruz',
            'relationship' => 'Grandmother',
            'phone' => '09182223333',
            'address' => '45 Rizal Street, Antipolo',
            'email' => 'lola.delacruz@example.test',
            'is_primary' => false,
        ];
    }
}
