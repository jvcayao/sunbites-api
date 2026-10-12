<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class DashboardContractTest extends ContractTestCase
{
    public function test_dashboard_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/dashboard');

        $this->assertContract($response, 'GET api/v1/dashboard');
    }

    public function test_updating_staff_status_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/dashboard/staff-status', [
            'user_id' => $this->scenario->cashier->id,
            'status' => 'OnBreak',
        ]);

        $this->assertContract($response, 'POST api/v1/dashboard/staff-status');
    }

    public function test_updating_staff_status_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/dashboard/staff-status', []);

        $this->assertContract($response, 'POST api/v1/dashboard/staff-status', '422-validation');
    }

    public function test_updating_the_other_branch_staff_status_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/dashboard/staff-status', [
            'user_id' => $this->scenario->managerB->id,
            'status' => 'OnBreak',
        ]);

        $this->assertContract($response, 'POST api/v1/dashboard/staff-status', 'other-branch');
    }
}
