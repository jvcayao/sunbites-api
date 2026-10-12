<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class StudentLedgerContractTest extends ContractTestCase
{
    public function test_student_ledger_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->getJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/ledger");

        $this->assertContract($response, 'GET api/v1/students/{student}/ledger');
    }

    public function test_student_ledger_with_an_unknown_entry_type_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->getJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/ledger?entry_type=unknown");

        $this->assertContract($response, 'GET api/v1/students/{student}/ledger', '422-validation');
    }

    public function test_other_branch_student_ledger_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)
            ->getJson("/api/v1/students/{$this->scenario->branchBStudent->id}/ledger");

        $this->assertContract($response, 'GET api/v1/students/{student}/ledger', 'other-branch');
    }
}
