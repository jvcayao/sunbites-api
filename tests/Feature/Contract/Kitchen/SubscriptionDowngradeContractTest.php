<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class SubscriptionDowngradeContractTest extends ContractTestCase
{
    public function test_downgrade_preview_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/subscription-downgrade-preview");

        $this->assertContract($response, 'GET api/v1/students/{student}/subscription-downgrade-preview');
    }

    public function test_downgrade_preview_for_a_non_subscription_student_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/subscription-downgrade-preview");

        $this->assertContract($response, 'GET api/v1/students/{student}/subscription-downgrade-preview', '422-not-subscription');
    }

    public function test_other_branch_downgrade_preview_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->branchBStudent->id}/subscription-downgrade-preview");

        $this->assertContract($response, 'GET api/v1/students/{student}/subscription-downgrade-preview', 'other-branch');
    }

    public function test_downgrading_a_subscription_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/downgrade-subscription");

        $this->assertContract($response, 'POST api/v1/students/{student}/downgrade-subscription');
    }

    public function test_downgrading_a_non_subscription_student_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/downgrade-subscription");

        $this->assertContract($response, 'POST api/v1/students/{student}/downgrade-subscription', '422-not-subscription');
    }

    public function test_downgrading_an_other_branch_student_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/downgrade-subscription");

        $this->assertContract($response, 'POST api/v1/students/{student}/downgrade-subscription', 'other-branch');
    }
}
