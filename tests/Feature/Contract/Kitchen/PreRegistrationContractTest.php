<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Mail;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class PreRegistrationContractTest extends ContractTestCase
{
    public function test_pre_registration_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/pre-registrations');

        $this->assertContract($response, 'GET api/v1/pre-registrations');
    }

    public function test_pre_registration_list_with_an_unknown_status_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/pre-registrations?status=unknown');

        $this->assertContract($response, 'GET api/v1/pre-registrations', '422-validation');
    }

    public function test_pre_registration_detail_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}");

        $this->assertContract($response, 'GET api/v1/pre-registrations/{preRegistration}');
    }

    public function test_other_branch_pre_registration_detail_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson("/api/v1/pre-registrations/{$this->scenario->branchBPreRegistration->id}");

        $this->assertContract($response, 'GET api/v1/pre-registrations/{preRegistration}', 'other-branch');
    }

    public function test_editing_a_pending_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}", $this->edits());

        $this->assertContract($response, 'PATCH api/v1/pre-registrations/{preRegistration}');
    }

    public function test_editing_a_pre_registration_with_an_unknown_grade_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}", ['grade_level' => 'Grade 99']);

        $this->assertContract($response, 'PATCH api/v1/pre-registrations/{preRegistration}', '422-validation');
    }

    public function test_editing_an_approved_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/pre-registrations/{$this->scenario->approvedPreRegistration->id}", $this->edits());

        $this->assertContract($response, 'PATCH api/v1/pre-registrations/{preRegistration}', '422-not-pending');
    }

    public function test_editing_an_other_branch_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->patchJson("/api/v1/pre-registrations/{$this->scenario->branchBPreRegistration->id}", $this->edits());

        $this->assertContract($response, 'PATCH api/v1/pre-registrations/{preRegistration}', 'other-branch');
    }

    public function test_approving_a_pre_registration_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/approve");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/approve');
    }

    public function test_approving_an_approved_pre_registration_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->approvedPreRegistration->id}/approve");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/approve', '422-not-pending');
    }

    public function test_approving_a_duplicate_of_an_enrolled_student_matches_contract(): void
    {
        $this->scenario->pendingPreRegistration->update([
            'first_name' => $this->scenario->subscriptionStudent->first_name,
            'last_name' => $this->scenario->subscriptionStudent->last_name,
            'birthday' => $this->scenario->subscriptionStudent->birthday,
        ]);

        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/approve");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/approve', '422-duplicate-student');
    }

    public function test_approving_a_duplicate_student_number_matches_contract(): void
    {
        $this->scenario->pendingPreRegistration->update([
            'student_number' => $this->scenario->subscriptionStudent->student_number,
        ]);

        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/approve");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/approve', '422-duplicate-student-number');
    }

    public function test_approving_an_other_branch_pre_registration_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->branchBPreRegistration->id}/approve");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/approve', 'other-branch');
    }

    public function test_reactivating_an_expired_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$this->scenario->expiredPreRegistration->id}/reactivate");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reactivate');
    }

    public function test_reactivating_a_pending_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/reactivate");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reactivate', '422-not-expired');
    }

    public function test_reactivating_an_other_branch_pre_registration_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$this->scenario->branchBPreRegistration->id}/reactivate");

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reactivate', 'other-branch');
    }

    public function test_rejecting_a_pre_registration_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()
            ->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/reject", ['rejection_reason' => 'Incomplete documents.']);

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reject');
    }

    public function test_rejecting_a_pre_registration_without_a_reason_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/pre-registrations/{$this->scenario->pendingPreRegistration->id}/reject", []);

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reject', '422-validation');
    }

    public function test_rejecting_an_approved_pre_registration_matches_contract(): void
    {
        $response = $this->asManager()
            ->postJson("/api/v1/pre-registrations/{$this->scenario->approvedPreRegistration->id}/reject", ['rejection_reason' => 'Incomplete documents.']);

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reject', '422-not-pending');
    }

    public function test_rejecting_an_other_branch_pre_registration_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()
            ->postJson("/api/v1/pre-registrations/{$this->scenario->branchBPreRegistration->id}/reject", ['rejection_reason' => 'Incomplete documents.']);

        $this->assertContract($response, 'POST api/v1/pre-registrations/{preRegistration}/reject', 'other-branch');
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    private function asManager(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->manager);
    }

    /**
     * @return array<string, mixed>
     */
    private function edits(): array
    {
        return [
            'first_name' => 'Lucas',
            'section' => 'Del Pilar',
            'allergies' => 'Eggs, nuts',
            'notes' => 'Parent asked for a morning call.',
            'contacts' => [
                [
                    'full_name' => 'Mother of Lucas',
                    'relationship' => 'Mother',
                    'phone' => '09190000001',
                    'address' => '9 Bonifacio Street',
                    'email' => 'lucas.mother@example.test',
                    'is_primary' => true,
                ],
            ],
        ];
    }
}
