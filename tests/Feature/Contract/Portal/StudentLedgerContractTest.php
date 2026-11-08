<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class StudentLedgerContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/portal/students/{student}/ledger';

    public function test_student_ledger_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->ledgerUrl($this->scenario->subscriptionStudent->id));

        $this->assertContract($response, self::ROUTE);
    }

    public function test_student_ledger_with_an_unknown_entry_type_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)
            ->getJson($this->ledgerUrl($this->scenario->subscriptionStudent->id).'?entry_type=unknown');

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_unlinked_student_ledger_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->ledgerUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::ROUTE, '403');
    }

    public function test_other_branch_student_ledger_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson($this->ledgerUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::ROUTE, 'other-branch');
    }

    private function ledgerUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/ledger";
    }
}
