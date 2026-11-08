<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class DashboardContractTest extends ContractTestCase
{
    public function test_parent_dashboard_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/dashboard');

        $this->assertContract($response, 'GET api/v1/portal/dashboard');
    }
}
