<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class StudentContractTest extends ContractTestCase
{
    public function test_linked_student_list_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/students');

        $this->assertContract($response, 'GET api/v1/portal/students', mapPaths: ['data[].subscription_monthly_status.categories']);
    }
}
