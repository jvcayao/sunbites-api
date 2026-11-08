<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Notification;
use Tests\Feature\Contract\ContractTestCase;

class CreditContractTest extends ContractTestCase
{
    public function test_settling_credit_by_gcash_matches_contract(): void
    {
        Notification::fake();

        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/settle", [
                'amount' => 20,
                'payment_method' => 'gcash',
                'reference_number' => 'GC555777',
                'note' => 'Paid at the counter',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle');
    }

    public function test_settling_credit_by_gcash_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/settle", [
                'amount' => 20,
                'payment_method' => 'gcash',
                'reference_number' => 'GC555777',
                'note' => 'Paid at the counter',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle', '422-no-branch');
    }

    public function test_settling_credit_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/settle", []);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle', '422-validation');
    }

    public function test_settling_credit_for_a_student_without_credit_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/credit/settle", [
                'amount' => 20,
                'payment_method' => 'cash',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle', '422-no-outstanding-credit');
    }

    public function test_settling_credit_from_a_short_wallet_matches_contract(): void
    {
        $this->scenario->subscriptionStudent->withdraw(27000);

        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/settle", [
                'amount' => 50,
                'payment_method' => 'wallet',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle', '422-wallet-insufficient');
    }

    public function test_settling_credit_for_the_other_branch_student_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/credit/settle", [
                'amount' => 20,
                'payment_method' => 'gcash',
                'reference_number' => 'GC555777',
                'note' => 'Paid at the counter',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/settle', 'other-branch');
    }

    public function test_waiving_credit_matches_contract(): void
    {
        Notification::fake();

        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/waive", [
                'amount' => 20,
                'reason' => 'Goodwill write-off',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/waive');
    }

    public function test_waiving_credit_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/credit/waive", []);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/waive', '422-validation');
    }

    public function test_waiving_credit_for_a_student_without_credit_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/students/{$this->scenario->nonSubscriptionStudent->id}/credit/waive", [
                'amount' => 20,
                'reason' => 'Goodwill write-off',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/waive', '422-no-outstanding-credit');
    }

    public function test_waiving_credit_for_the_other_branch_student_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/credit/waive", [
                'amount' => 20,
                'reason' => 'Goodwill write-off',
            ]);

        $this->assertContract($response, 'POST api/v1/students/{student}/credit/waive', 'other-branch');
    }
}
