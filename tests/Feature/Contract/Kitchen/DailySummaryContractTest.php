<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class DailySummaryContractTest extends ContractTestCase
{
    public function test_daily_summary_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/daily-summary');

        $this->assertContract($response, 'GET api/v1/reports/daily-summary');
    }

    public function test_daily_summary_with_an_invalid_date_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/daily-summary?date=not-a-date');

        $this->assertContract($response, 'GET api/v1/reports/daily-summary', '422-validation');
    }
}
