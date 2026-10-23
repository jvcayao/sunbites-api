<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class CreditReportContractTest extends ContractTestCase
{
    public function test_credit_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/credits');

        $this->assertContract($response, 'GET api/v1/reports/credits', mapPaths: ['summary.settled_by_method']);
    }

    public function test_credit_report_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->manager)->getJson('/api/v1/reports/credits');

        $this->assertContract($response, 'GET api/v1/reports/credits', '422-no-branch');
    }

    public function test_credit_report_with_an_unknown_type_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/credits?type=refunded');

        $this->assertContract($response, 'GET api/v1/reports/credits', '422-validation');
    }
}
