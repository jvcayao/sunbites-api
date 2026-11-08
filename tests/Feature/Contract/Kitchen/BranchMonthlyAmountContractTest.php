<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class BranchMonthlyAmountContractTest extends ContractTestCase
{
    public function test_monthly_amount_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/branch-monthly-amounts?year=2026');

        $this->assertContract($response, 'GET api/v1/branch-monthly-amounts');
    }

    public function test_monthly_amount_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson('/api/v1/branch-monthly-amounts?year=2026');

        $this->assertContract($response, 'GET api/v1/branch-monthly-amounts', '422-no-branch');
    }

    public function test_creating_a_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'november',
            'year' => 2026,
            'days' => 20,
            'amount' => 2700,
        ]);

        $this->assertContract($response, 'POST api/v1/branch-monthly-amounts');
    }

    public function test_saving_an_existing_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'october',
            'year' => 2026,
            'days' => 22,
            'amount' => 2970,
        ]);

        $this->assertContract($response, 'POST api/v1/branch-monthly-amounts', 'ok-existing');
    }

    public function test_creating_a_monthly_amount_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/branch-monthly-amounts', []);

        $this->assertContract($response, 'POST api/v1/branch-monthly-amounts', '422-validation');
    }

    public function test_updating_a_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->putJson("/api/v1/branch-monthly-amounts/{$this->scenario->monthlyAmount->id}", ['days' => 22, 'amount' => 2970]);

        $this->assertContract($response, 'PUT api/v1/branch-monthly-amounts/{branchMonthlyAmount}');
    }

    public function test_updating_a_monthly_amount_without_days_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->putJson("/api/v1/branch-monthly-amounts/{$this->scenario->monthlyAmount->id}", []);

        $this->assertContract($response, 'PUT api/v1/branch-monthly-amounts/{branchMonthlyAmount}', '422-validation');
    }

    public function test_updating_the_other_branch_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->putJson("/api/v1/branch-monthly-amounts/{$this->scenario->branchBMonthlyAmount->id}", ['days' => 22, 'amount' => 2970]);

        $this->assertContract($response, 'PUT api/v1/branch-monthly-amounts/{branchMonthlyAmount}', 'other-branch');
    }

    public function test_deleting_a_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->deleteJson("/api/v1/branch-monthly-amounts/{$this->scenario->monthlyAmount->id}");

        $this->assertContract($response, 'DELETE api/v1/branch-monthly-amounts/{branchMonthlyAmount}');
    }

    public function test_deleting_the_other_branch_monthly_amount_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->deleteJson("/api/v1/branch-monthly-amounts/{$this->scenario->branchBMonthlyAmount->id}");

        $this->assertContract($response, 'DELETE api/v1/branch-monthly-amounts/{branchMonthlyAmount}', 'other-branch');
    }
}
