<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class InlineReloadContractTest extends ContractTestCase
{
    public function test_inline_reload_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $this->scenario->subscriptionStudent->id,
            'amount' => 150,
            'payment_method' => 'gcash',
            'reference_number' => 'GC909090',
            'order_context' => 'Short by 20 at checkout',
        ]);

        $this->assertContract($response, 'POST api/v1/pos/inline-reload');
    }

    public function test_inline_reload_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->cashier)->postJson('/api/v1/pos/inline-reload', [
            'student_id' => $this->scenario->subscriptionStudent->id,
            'amount' => 150,
            'payment_method' => 'gcash',
            'reference_number' => 'GC909090',
            'order_context' => 'Short by 20 at checkout',
        ]);

        $this->assertContract($response, 'POST api/v1/pos/inline-reload', '422-no-branch');
    }

    public function test_inline_reload_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->postJson('/api/v1/pos/inline-reload', []);

        $this->assertContract($response, 'POST api/v1/pos/inline-reload', '422-validation');
    }
}
