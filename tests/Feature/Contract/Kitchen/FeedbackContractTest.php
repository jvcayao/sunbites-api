<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Mail;
use Tests\Feature\Contract\ContractTestCase;

class FeedbackContractTest extends ContractTestCase
{
    public function test_feedback_inbox_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/references/feedback');

        $this->assertContract($response, 'GET api/v1/references/feedback');
    }

    public function test_feedback_inbox_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson('/api/v1/references/feedback');

        $this->assertContract($response, 'GET api/v1/references/feedback', '422-no-branch');
    }

    public function test_feedback_inbox_with_an_invalid_page_size_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/references/feedback?per_page=0');

        $this->assertContract($response, 'GET api/v1/references/feedback', '422-validation');
    }

    public function test_replying_to_feedback_matches_contract(): void
    {
        Mail::fake();

        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/references/feedback/{$this->scenario->feedback->id}/reply", ['reply' => 'Thanks, noted for next week.']);

        $this->assertContract($response, 'POST api/v1/references/feedback/{feedback}/reply');
    }

    public function test_replying_with_markup_only_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/references/feedback/{$this->scenario->feedback->id}/reply", ['reply' => '<br><br>']);

        $this->assertContract($response, 'POST api/v1/references/feedback/{feedback}/reply', '422-validation');
    }

    public function test_replying_to_other_branch_feedback_matches_contract(): void
    {
        Mail::fake();

        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/references/feedback/{$this->scenario->branchBFeedback->id}/reply", ['reply' => 'Thanks, noted for next week.']);

        $this->assertContract($response, 'POST api/v1/references/feedback/{feedback}/reply', 'other-branch');
    }

    public function test_marking_feedback_read_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->patchJson("/api/v1/references/feedback/{$this->scenario->feedback->id}/mark-read");

        $this->assertContract($response, 'PATCH api/v1/references/feedback/{feedback}/mark-read');
    }

    public function test_marking_other_branch_feedback_read_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->patchJson("/api/v1/references/feedback/{$this->scenario->branchBFeedback->id}/mark-read");

        $this->assertContract($response, 'PATCH api/v1/references/feedback/{feedback}/mark-read', 'other-branch');
    }
}
