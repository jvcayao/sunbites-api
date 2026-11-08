<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Mail;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class ParentContractTest extends ContractTestCase
{
    public function test_parent_list_matches_contract(): void
    {
        $response = $this->asManager()->getJson('/api/v1/references/parents');

        $this->assertContract($response, 'GET api/v1/references/parents');
    }

    public function test_parent_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->manager)->getJson('/api/v1/references/parents');

        $this->assertContract($response, 'GET api/v1/references/parents', '422-no-branch');
    }

    public function test_parent_list_with_an_unknown_status_matches_contract(): void
    {
        $response = $this->asManager()->getJson('/api/v1/references/parents?status=unknown');

        $this->assertContract($response, 'GET api/v1/references/parents', '422-validation');
    }

    public function test_parent_detail_matches_contract(): void
    {
        $response = $this->asManager()->getJson("/api/v1/references/parents/{$this->scenario->parent->id}");

        $this->assertContract($response, 'GET api/v1/references/parents/{parent}');
    }

    public function test_other_branch_parent_detail_matches_contract(): void
    {
        $response = $this->asManager()->getJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}");

        $this->assertContract($response, 'GET api/v1/references/parents/{parent}', 'other-branch');
    }

    public function test_resending_parent_activation_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->unactivatedParent->id}/resend-activation");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/resend-activation');
    }

    public function test_resending_other_branch_parent_activation_matches_contract(): void
    {
        Mail::fake();

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}/resend-activation");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/resend-activation', 'other-branch');
    }

    public function test_disabling_a_parent_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->parent->id}/disable");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/disable');
    }

    public function test_disabling_an_other_branch_parent_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}/disable");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/disable', 'other-branch');
    }

    public function test_enabling_a_parent_matches_contract(): void
    {
        Mail::fake();
        $this->scenario->parent->update(['disabled_at' => now()]);

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->parent->id}/enable");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/enable');
    }

    public function test_enabling_an_other_branch_parent_matches_contract(): void
    {
        Mail::fake();
        $this->scenario->branchBParent->update(['disabled_at' => now()]);

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}/enable");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/enable', 'other-branch');
    }

    public function test_deleting_a_parent_matches_contract(): void
    {
        $response = $this->asManager()->deleteJson("/api/v1/references/parents/{$this->scenario->parent->id}");

        $this->assertContract($response, 'DELETE api/v1/references/parents/{parent}');
    }

    public function test_deleting_an_other_branch_parent_matches_contract(): void
    {
        $response = $this->asManager()->deleteJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}");

        $this->assertContract($response, 'DELETE api/v1/references/parents/{parent}', 'other-branch');
    }

    public function test_restoring_a_deleted_parent_matches_contract(): void
    {
        $this->scenario->parent->delete();

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->parent->id}/restore");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/restore');
    }

    public function test_restoring_a_parent_that_is_not_deleted_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->parent->id}/restore");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/restore', '404');
    }

    public function test_restoring_a_deleted_other_branch_parent_matches_contract(): void
    {
        $this->scenario->branchBParent->delete();

        $response = $this->asManager()->postJson("/api/v1/references/parents/{$this->scenario->branchBParent->id}/restore");

        $this->assertContract($response, 'POST api/v1/references/parents/{parent}/restore', 'other-branch');
    }

    private function asManager(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->manager);
    }
}
