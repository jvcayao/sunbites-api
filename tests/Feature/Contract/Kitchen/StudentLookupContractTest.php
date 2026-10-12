<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class StudentLookupContractTest extends ContractTestCase
{
    /** @var list<string> */
    private const array STATUS_MAPS = ['student.subscription_daily_status', 'student.subscription_monthly_status.categories'];

    public function test_qr_lookup_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => $this->scenario->subscriptionStudent->qr_code,
        ]);

        $this->assertContract($response, 'POST api/v1/pos/students/lookup', mapPaths: self::STATUS_MAPS);
    }

    public function test_search_lookup_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', ['type' => 'search', 'value' => 'Dela']);

        $this->assertContract($response, 'POST api/v1/pos/students/lookup', 'ok-search');
    }

    public function test_lookup_without_input_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', []);

        $this->assertContract($response, 'POST api/v1/pos/students/lookup', '422-validation');
    }

    public function test_lookup_of_an_unknown_qr_code_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', ['type' => 'qr', 'value' => 'SB-UNKNOWNCODE1']);

        $this->assertContract($response, 'POST api/v1/pos/students/lookup', '404');
    }

    public function test_lookup_of_a_non_enrolled_student_matches_contract(): void
    {
        $this->scenario->nonSubscriptionStudent->update(['enrollment_status' => 'banned']);

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => $this->scenario->nonSubscriptionStudent->qr_code,
        ]);

        $this->assertContract($response, 'POST api/v1/pos/students/lookup', '422-not-enrolled');
    }

    public function test_pos_student_detail_matches_contract(): void
    {
        $response = $this->asCashier()->getJson("/api/v1/pos/students/{$this->scenario->subscriptionStudent->id}");

        $this->assertContract($response, 'GET api/v1/pos/students/{student}', mapPaths: self::STATUS_MAPS);
    }

    public function test_other_branch_pos_student_detail_matches_contract(): void
    {
        $response = $this->asCashier()->getJson("/api/v1/pos/students/{$this->scenario->branchBStudent->id}");

        $this->assertContract($response, 'GET api/v1/pos/students/{student}', 'other-branch');
    }

    private function asCashier(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->cashier);
    }
}
