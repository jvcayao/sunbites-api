<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class BranchContractTest extends ContractTestCase
{
    public function test_branch_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)->getJson('/api/v1/branches');

        $this->assertContract($response, 'GET api/v1/branches');
    }

    public function test_updating_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->putJson("/api/v1/branches/{$this->scenario->branchA->id}", $this->branchPayload());

        $this->assertContract($response, 'PUT api/v1/branches/{branch}');
    }

    public function test_updating_a_branch_without_a_name_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->putJson("/api/v1/branches/{$this->scenario->branchA->id}", []);

        $this->assertContract($response, 'PUT api/v1/branches/{branch}', '422-validation');
    }

    public function test_updating_the_other_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->putJson("/api/v1/branches/{$this->scenario->branchB->id}", $this->branchPayload());

        $this->assertContract($response, 'PUT api/v1/branches/{branch}', 'other-branch');
    }

    public function test_toggling_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/branches/{$this->scenario->branchA->id}/toggle");

        $this->assertContract($response, 'POST api/v1/branches/{branch}/toggle');
    }

    public function test_toggling_the_other_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/branches/{$this->scenario->branchB->id}/toggle");

        $this->assertContract($response, 'POST api/v1/branches/{branch}/toggle', 'other-branch');
    }

    public function test_deactivating_the_last_active_branch_matches_contract(): void
    {
        $this->scenario->branchB->update(['is_active' => false]);

        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/branches/{$this->scenario->branchA->id}/toggle");

        $this->assertContract($response, 'POST api/v1/branches/{branch}/toggle', '422-last-active-branch');
    }

    /**
     * @return array<string, string>
     */
    private function branchPayload(): array
    {
        return [
            'name' => 'Antipolo Main',
            'gcash_number' => '09170000009',
            'address' => 'Circumferential Road, Antipolo City',
        ];
    }
}
