<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class SubscriptionReportContractTest extends ContractTestCase
{
    public function test_subscription_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/subscription?month=october&year=2026');

        $this->assertContract($response, 'GET api/v1/reports/subscription', mapPaths: ['data[].subscription_monthly_status.categories']);
    }

    public function test_subscription_report_without_a_period_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/subscription');

        $this->assertContract($response, 'GET api/v1/reports/subscription', '422-validation');
    }
}
