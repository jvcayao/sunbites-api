<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class WalletHistoryContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/reports/wallet/{student}/history';

    public function test_top_up_history_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson($this->historyUrl('topups'));

        $this->assertContract($response, self::ROUTE);
    }

    public function test_top_up_history_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->manager)->getJson($this->historyUrl('topups'));

        $this->assertContract($response, self::ROUTE, '422-no-branch');
    }

    public function test_purchase_history_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson($this->historyUrl('purchases'));

        $this->assertContract($response, self::ROUTE, 'ok-purchases');
    }

    public function test_credit_history_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson($this->historyUrl('credit'));

        $this->assertContract($response, self::ROUTE, 'ok-credit');
    }

    public function test_history_without_a_type_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->getJson("/api/v1/reports/wallet/{$this->scenario->subscriptionStudent->id}/history");

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_other_branch_history_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->getJson("/api/v1/reports/wallet/{$this->scenario->branchBStudent->id}/history?type=topups");

        $this->assertContract($response, self::ROUTE, 'other-branch');
    }

    private function historyUrl(string $type): string
    {
        return "/api/v1/reports/wallet/{$this->scenario->subscriptionStudent->id}/history?type={$type}";
    }
}
