<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class StudentPaymentHistoryContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/portal/students/{student}/payment-history';

    public function test_payment_history_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->historyUrl($this->scenario->subscriptionStudent->id));

        $this->assertContract($response, self::ROUTE);
    }

    public function test_unlinked_student_payment_history_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->historyUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::ROUTE, '403');
    }

    public function test_other_branch_student_payment_history_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->historyUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::ROUTE, 'other-branch');
    }

    private function historyUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/payment-history";
    }
}
