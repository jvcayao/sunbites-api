<?php

namespace Tests\Feature\Contract\Public;

use Tests\Feature\Contract\ContractTestCase;

class BranchContractTest extends ContractTestCase
{
    public function test_public_branch_list_matches_contract(): void
    {
        $response = $this->getJson('/api/v1/public/branches');

        $this->assertContract($response, 'GET api/v1/public/branches');
    }
}
