<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class ActivityContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/portal/students/{student}/activity';

    public function test_student_activity_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->activityUrl($this->scenario->subscriptionStudent->id));

        $this->assertContract($response, self::ROUTE);
    }

    public function test_student_activity_with_an_unknown_payment_method_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)
            ->getJson($this->activityUrl($this->scenario->subscriptionStudent->id).'?payment_method=bitcoin');

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_unlinked_student_activity_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->activityUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::ROUTE, '403');
    }

    public function test_other_branch_student_activity_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->activityUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::ROUTE, 'other-branch');
    }

    private function activityUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/activity";
    }
}
