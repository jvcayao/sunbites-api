<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class ActivityLogContractTest extends ContractTestCase
{
    public function test_activity_log_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/activity');

        $this->assertContract($response, 'GET api/v1/reports/activity');
    }

    public function test_activity_log_with_invalid_page_size_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/activity?per_page=0');

        $this->assertContract($response, 'GET api/v1/reports/activity', '422-validation');
    }
}
