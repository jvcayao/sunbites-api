<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class TransactionContractTest extends ContractTestCase
{
    public function test_transaction_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/pos/transactions');

        $this->assertContract($response, 'GET api/v1/pos/transactions');
    }

    public function test_transaction_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->cashier)->getJson('/api/v1/pos/transactions');

        $this->assertContract($response, 'GET api/v1/pos/transactions', '422-no-branch');
    }

    public function test_transaction_list_with_an_unknown_payment_method_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/pos/transactions?payment_method=bitcoin');

        $this->assertContract($response, 'GET api/v1/pos/transactions', '422-validation');
    }

    public function test_voiding_an_order_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/pos/transactions/{$this->scenario->cashOrder->id}/void", ['void_reason' => 'Customer changed their mind']);

        $this->assertContract($response, 'POST api/v1/pos/transactions/{order}/void');
    }

    public function test_voiding_an_order_without_a_reason_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/pos/transactions/{$this->scenario->cashOrder->id}/void", []);

        $this->assertContract($response, 'POST api/v1/pos/transactions/{order}/void', '422-validation');
    }

    public function test_voiding_an_already_voided_order_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/pos/transactions/{$this->scenario->voidedOrder->id}/void", ['void_reason' => 'Customer changed their mind']);

        $this->assertContract($response, 'POST api/v1/pos/transactions/{order}/void', '422-already-voided');
    }

    public function test_voiding_an_other_branch_order_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->postJson("/api/v1/pos/transactions/{$this->scenario->branchBOrder->id}/void", ['void_reason' => 'Customer changed their mind']);

        $this->assertContract($response, 'POST api/v1/pos/transactions/{order}/void', 'other-branch');
    }
}
