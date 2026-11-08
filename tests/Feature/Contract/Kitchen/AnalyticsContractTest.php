<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class AnalyticsContractTest extends ContractTestCase
{
    public function test_analytics_for_a_month_range_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->getJson('/api/v1/reports/analytics?from_month=june&from_year=2026&to_month=october&to_year=2026');

        $this->assertContract($response, 'GET api/v1/reports/analytics');
    }

    public function test_analytics_for_a_month_range_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->manager)
            ->getJson('/api/v1/reports/analytics?from_month=june&from_year=2026&to_month=october&to_year=2026');

        $this->assertContract($response, 'GET api/v1/reports/analytics', '422-no-branch');
    }

    public function test_analytics_without_a_range_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/analytics');

        $this->assertContract($response, 'GET api/v1/reports/analytics', '422-validation');
    }
}
