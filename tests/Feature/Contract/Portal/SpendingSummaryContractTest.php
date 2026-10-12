<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class SpendingSummaryContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/portal/students/{student}/spending-summary';

    public function test_spending_summary_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->summaryUrl($this->scenario->subscriptionStudent->id));

        $this->assertContract($response, self::ROUTE, mapPaths: ['payment_method_split']);
    }

    public function test_spending_summary_with_an_invalid_month_count_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)
            ->getJson($this->summaryUrl($this->scenario->subscriptionStudent->id).'?months=0');

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_unlinked_student_spending_summary_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->summaryUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::ROUTE, '403');
    }

    public function test_other_branch_student_spending_summary_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->summaryUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::ROUTE, 'other-branch');
    }

    private function summaryUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/spending-summary";
    }
}
