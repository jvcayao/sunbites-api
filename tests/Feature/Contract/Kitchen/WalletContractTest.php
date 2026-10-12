<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class WalletContractTest extends ContractTestCase
{
    private const string VOID_ROUTE = 'POST api/v1/students/{student}/wallet/top-ups/{transaction}/void';

    public function test_topping_up_a_wallet_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('/wallet/top-up'), $this->topUp());

        $this->assertContract($response, 'POST api/v1/students/{student}/wallet/top-up');
    }

    public function test_topping_up_a_wallet_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('/wallet/top-up'), []);

        $this->assertContract($response, 'POST api/v1/students/{student}/wallet/top-up', '422-validation');
    }

    public function test_topping_up_an_other_branch_wallet_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/wallet/top-up", $this->topUp());

        $this->assertContract($response, 'POST api/v1/students/{student}/wallet/top-up', 'other-branch');
    }

    public function test_voiding_a_top_up_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->voidUrl($this->scenario->depositTransactionId), ['reason' => 'Entered twice']);

        $this->assertContract($response, self::VOID_ROUTE);
    }

    public function test_voiding_a_top_up_without_a_reason_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->voidUrl($this->scenario->depositTransactionId), []);

        $this->assertContract($response, self::VOID_ROUTE, '422-validation');
    }

    public function test_voiding_an_unknown_top_up_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->voidUrl(999999), ['reason' => 'Entered twice']);

        $this->assertContract($response, self::VOID_ROUTE, '404');
    }

    public function test_voiding_an_already_voided_top_up_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->voidUrl($this->scenario->voidedDepositTransactionId), ['reason' => 'Entered twice']);

        $this->assertContract($response, self::VOID_ROUTE, '422-already-voided');
    }

    public function test_voiding_your_own_top_up_matches_contract(): void
    {
        $ownDeposit = $this->scenario->subscriptionStudent->deposit(5000, [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $this->scenario->supervisor->id,
        ]);

        $response = $this->asSupervisor()->postJson($this->voidUrl($ownDeposit->id), ['reason' => 'Entered twice']);

        $this->assertContract($response, self::VOID_ROUTE, '403-self-void');
    }

    public function test_voiding_a_top_up_from_an_earlier_day_matches_contract(): void
    {
        $scenarioTime = now();
        $this->travelTo($scenarioTime->subDay());
        $earlierDeposit = $this->scenario->subscriptionStudent->deposit(5000, [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $this->scenario->cashier->id,
        ]);
        $this->travelTo($scenarioTime);

        $response = $this->asSupervisor()->postJson($this->voidUrl($earlierDeposit->id), ['reason' => 'Entered twice']);

        $this->assertContract($response, self::VOID_ROUTE, '403-outside-today');
    }

    public function test_voiding_an_other_branch_top_up_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson(
            "/api/v1/students/{$this->scenario->branchBStudent->id}/wallet/top-ups/{$this->scenario->depositTransactionId}/void",
            ['reason' => 'Entered twice'],
        );

        $this->assertContract($response, self::VOID_ROUTE, 'other-branch');
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    private function studentUrl(string $suffix): string
    {
        return "/api/v1/students/{$this->scenario->subscriptionStudent->id}{$suffix}";
    }

    private function voidUrl(int $transactionId): string
    {
        return $this->studentUrl("/wallet/top-ups/{$transactionId}/void");
    }

    /**
     * @return array<string, mixed>
     */
    private function topUp(): array
    {
        return [
            'amount' => 200,
            'payment_method' => 'gcash',
            'reference_number' => 'GC246810',
            'note' => 'Weekly allowance',
        ];
    }
}
