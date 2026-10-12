<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class PaymentContractTest extends ContractTestCase
{
    public function test_payment_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->subscriptionStudent->id}/payments");

        $this->assertContract($response, 'GET api/v1/students/{student}/payments');
    }

    public function test_other_branch_payment_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments");

        $this->assertContract($response, 'GET api/v1/students/{student}/payments', 'other-branch');
    }

    public function test_recording_a_payment_matches_contract(): void
    {
        $response = $this->asManager()->postJson($this->studentUrl('payments'), $this->recording('october', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments');
    }

    public function test_recording_a_payment_without_input_matches_contract(): void
    {
        $response = $this->asManager()->postJson($this->studentUrl('payments'), []);

        $this->assertContract($response, 'POST api/v1/students/{student}/payments', '422-validation');
    }

    public function test_recording_a_voided_payment_matches_contract(): void
    {
        $response = $this->asManager()->postJson($this->studentUrl('payments'), $this->recording('november', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments', '422-voided-record');
    }

    public function test_recording_a_month_without_a_payment_row_matches_contract(): void
    {
        $response = $this->asManager()->postJson($this->studentUrl('payments'), $this->recording('february', 2027));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments', '404');
    }

    public function test_recording_an_other_branch_payment_matches_contract(): void
    {
        $response = $this->asManager()
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments", $this->recording('october', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments', 'other-branch');
    }

    public function test_adding_a_payment_range_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('payments/range'), $this->range('july', 2026, 'september', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments/range');
    }

    public function test_adding_a_payment_range_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('payments/range'), []);

        $this->assertContract($response, 'POST api/v1/students/{student}/payments/range', '422-validation');
    }

    public function test_adding_a_payment_range_ending_before_it_starts_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson($this->studentUrl('payments/range'), $this->range('march', 2027, 'june', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments/range', '422-end-before-start');
    }

    public function test_adding_an_other_branch_payment_range_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->postJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments/range", $this->range('july', 2026, 'september', 2026));

        $this->assertContract($response, 'POST api/v1/students/{student}/payments/range', 'other-branch');
    }

    public function test_toggling_a_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id));

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}');
    }

    public function test_toggling_a_voided_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->novemberVoidedPayment->id));

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}', '422-voided');
    }

    public function test_toggling_another_students_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->branchBPayment->id));

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}', '403-wrong-student');
    }

    public function test_toggling_an_other_branch_payment_matches_contract(): void
    {
        $response = $this->asManager()
            ->patchJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments/{$this->scenario->branchBPayment->id}");

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}', 'other-branch');
    }

    public function test_changing_a_payment_amount_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id, '/amount'), ['amount' => 3000]);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/amount');
    }

    public function test_changing_a_payment_amount_without_input_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id, '/amount'), []);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/amount', '422-validation');
    }

    public function test_changing_a_paid_payment_amount_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->junePaidPayment->id, '/amount'), ['amount' => 3000]);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/amount', '422-not-unpaid');
    }

    public function test_changing_another_students_payment_amount_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->branchBPayment->id, '/amount'), ['amount' => 3000]);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/amount', '403-wrong-student');
    }

    public function test_changing_an_other_branch_payment_amount_matches_contract(): void
    {
        $response = $this->asManager()
            ->patchJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments/{$this->scenario->branchBPayment->id}/amount", ['amount' => 3000]);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/amount', 'other-branch');
    }

    public function test_voiding_a_paid_payment_matches_contract(): void
    {
        $this->payOctober();

        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id, '/void'), ['reason' => 'Refunded to parent']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void');
    }

    public function test_voiding_a_paid_payment_without_a_reason_matches_contract(): void
    {
        $this->payOctober();

        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id, '/void'), []);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void', '422-validation');
    }

    public function test_voiding_an_unpaid_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->octoberUnpaidPayment->id, '/void'), ['reason' => 'Refunded to parent']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void', '422-not-paid');
    }

    public function test_voiding_a_past_month_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->junePaidPayment->id, '/void'), ['reason' => 'Refunded to parent']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void', '422-past-month');
    }

    public function test_voiding_another_students_payment_matches_contract(): void
    {
        $response = $this->asManager()->patchJson($this->paymentUrl($this->scenario->branchBPayment->id, '/void'), ['reason' => 'Refunded to parent']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void', '404-wrong-student');
    }

    public function test_voiding_an_other_branch_payment_matches_contract(): void
    {
        $response = $this->asManager()
            ->patchJson("/api/v1/students/{$this->scenario->branchBStudent->id}/payments/{$this->scenario->branchBPayment->id}/void", ['reason' => 'Refunded to parent']);

        $this->assertContract($response, 'PATCH api/v1/students/{student}/payments/{payment}/void', 'other-branch');
    }

    private function asManager(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->manager);
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    private function studentUrl(string $path): string
    {
        return "/api/v1/students/{$this->scenario->subscriptionStudent->id}/{$path}";
    }

    private function paymentUrl(int $paymentId, string $suffix = ''): string
    {
        return $this->studentUrl("payments/{$paymentId}{$suffix}");
    }

    private function payOctober(): void
    {
        $this->scenario->octoberUnpaidPayment->update([
            'status' => 'paid',
            'recorded_at' => now(),
            'recorded_by' => $this->scenario->manager->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(string $month, int $year): array
    {
        return ['school_month' => $month, 'year' => $year, 'amount' => 2970];
    }

    /**
     * @return array<string, mixed>
     */
    private function range(string $startMonth, int $startYear, string $endMonth, int $endYear): array
    {
        return [
            'subscription_start_month' => $startMonth,
            'subscription_start_year' => $startYear,
            'subscription_end_month' => $endMonth,
            'subscription_end_year' => $endYear,
        ];
    }
}
