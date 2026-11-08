<?php

namespace Tests\Feature\Contract\Public;

use Tests\Feature\Contract\ContractTestCase;

class HealthContractTest extends ContractTestCase
{
    public function test_health_check_matches_contract(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertContract($response, 'GET api/health');
    }
}
