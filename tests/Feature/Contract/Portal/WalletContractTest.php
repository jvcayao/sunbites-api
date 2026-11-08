<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class WalletContractTest extends ContractTestCase
{
    private const string INDEX_ROUTE = 'GET api/v1/portal/students/{student}/wallet';

    private const string ALERT_ROUTE = 'PATCH api/v1/portal/students/{student}/wallet/alert';

    public function test_student_wallet_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->walletUrl($this->scenario->subscriptionStudent->id));

        $this->assertContract($response, self::INDEX_ROUTE);
    }

    public function test_student_wallet_with_an_unknown_type_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->walletUrl($this->scenario->subscriptionStudent->id).'?type=unknown');

        $this->assertContract($response, self::INDEX_ROUTE, '422-validation');
    }

    public function test_unlinked_student_wallet_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->walletUrl($this->scenario->nonSubscriptionStudent->id));

        $this->assertContract($response, self::INDEX_ROUTE, '403');
    }

    public function test_other_branch_student_wallet_matches_contract(): void
    {
        $response = $this->asParent()->getJson($this->walletUrl($this->scenario->branchBStudent->id));

        $this->assertContract($response, self::INDEX_ROUTE, 'other-branch');
    }

    public function test_setting_a_wallet_alert_matches_contract(): void
    {
        $response = $this->asParent()->patchJson($this->walletUrl($this->scenario->subscriptionStudent->id).'/alert', ['threshold' => 150]);

        $this->assertContract($response, self::ALERT_ROUTE);
    }

    public function test_setting_a_wallet_alert_without_a_threshold_matches_contract(): void
    {
        $response = $this->asParent()->patchJson($this->walletUrl($this->scenario->subscriptionStudent->id).'/alert', []);

        $this->assertContract($response, self::ALERT_ROUTE, '422-validation');
    }

    public function test_setting_an_unlinked_student_wallet_alert_matches_contract(): void
    {
        $response = $this->asParent()->patchJson($this->walletUrl($this->scenario->nonSubscriptionStudent->id).'/alert', ['threshold' => 150]);

        $this->assertContract($response, self::ALERT_ROUTE, '403');
    }

    public function test_setting_an_other_branch_student_wallet_alert_matches_contract(): void
    {
        $response = $this->asParent()->patchJson($this->walletUrl($this->scenario->branchBStudent->id).'/alert', ['threshold' => 150]);

        $this->assertContract($response, self::ALERT_ROUTE, 'other-branch');
    }

    private function asParent(): TestCase
    {
        return $this->scenario->asParent($this->scenario->parent);
    }

    private function walletUrl(int $studentId): string
    {
        return "/api/v1/portal/students/{$studentId}/wallet";
    }
}
