<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditLedgerServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staff;

    private CreditLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->ledger = app(CreditLedgerService::class);
    }

    private function studentOwing(float $credit, float $walletPesos = 0): Student
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => $credit,
        ]);

        if ($walletPesos > 0) {
            $student->deposit((int) round($walletPesos * 100));
        }

        return $student->refresh();
    }

    public function test_charge_increases_the_balance_and_records_the_receipt(): void
    {
        $student = $this->studentOwing(0);

        $entry = $this->ledger->charge($student, 60.00, 'ANTIPOLO-2026-0412', $this->staff);

        $this->assertEquals(60.0, (float) $student->fresh()->credit_balance);
        $this->assertSame(CreditTransactionType::Charged, $entry->type);
        $this->assertSame('60.00', $entry->amount);
        $this->assertStringContainsString('ANTIPOLO-2026-0412', $entry->notes);
        $this->assertNull($entry->order_id);
    }

    public function test_charge_accumulates_across_multiple_orders(): void
    {
        $student = $this->studentOwing(0);

        $this->ledger->charge($student, 25.00, 'R-1', $this->staff);
        $this->ledger->charge($student, 35.50, 'R-2', $this->staff);

        $this->assertEquals(60.5, (float) $student->fresh()->credit_balance);
        $this->assertSame(2, CreditTransaction::where('student_id', $student->id)->count());
    }

    public function test_full_cash_settlement_zeroes_the_balance(): void
    {
        $student = $this->studentOwing(150.00);

        $entry = $this->ledger->settleWithPayment($student, 150.00, CreditSettlementMethod::Cash, null, null, $this->staff);

        $this->assertSame('0.00', $student->fresh()->credit_balance);
        $this->assertSame(CreditTransactionType::Settled, $entry->type);
        $this->assertSame(CreditSettlementMethod::Cash, $entry->payment_method);
        $this->assertSame($this->branch->id, $entry->branch_id);
        $this->assertSame($this->staff->id, $entry->performed_by);
    }

    public function test_partial_settlement_reduces_by_exactly_the_amount(): void
    {
        $student = $this->studentOwing(150.00);

        $this->ledger->settleWithPayment($student, 60.25, CreditSettlementMethod::Cash, null, null, $this->staff);

        $this->assertEquals(89.75, (float) $student->fresh()->credit_balance);
    }

    public function test_gcash_and_bank_transfer_settlements_persist_their_reference(): void
    {
        $student = $this->studentOwing(200.00);

        $gcash = $this->ledger->settleWithPayment($student, 50.00, CreditSettlementMethod::Gcash, 'GC7X92A', null, $this->staff);
        $bank = $this->ledger->settleWithPayment($student, 50.00, CreditSettlementMethod::BankTransfer, 'BDO1234', null, $this->staff);

        $this->assertSame(CreditSettlementMethod::Gcash, $gcash->payment_method);
        $this->assertSame('GC7X92A', $gcash->reference_number);
        $this->assertSame(CreditSettlementMethod::BankTransfer, $bank->payment_method);
        $this->assertSame('BDO1234', $bank->reference_number);
        $this->assertEquals(100.0, (float) $student->fresh()->credit_balance);
    }

    public function test_settling_more_than_outstanding_aborts_and_writes_nothing(): void
    {
        $student = $this->studentOwing(150.00);

        try {
            $this->ledger->settleWithPayment($student, 150.01, CreditSettlementMethod::Cash, null, null, $this->staff);
            $this->fail('Settling more than the outstanding credit must abort.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('exceeds outstanding credit', $e->getMessage());
        }

        $this->assertEquals(150.0, (float) $student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_settling_with_no_outstanding_credit_aborts(): void
    {
        $student = $this->studentOwing(0);

        try {
            $this->ledger->settleWithPayment($student, 10.00, CreditSettlementMethod::Cash, null, null, $this->staff);
            $this->fail('Settling with no outstanding credit must abort.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('No outstanding credit to settle.', $e->getMessage());
        }

        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_settling_from_wallet_debits_the_wallet_and_reduces_the_debt(): void
    {
        $student = $this->studentOwing(150.00, 500.00);

        $entry = $this->ledger->settleFromWallet($student, 150.00, 'Applied from balance.', $this->staff);

        $student = $student->fresh()->load('wallet');

        $this->assertEquals(350.0, (float) $student->wallet->balanceFloat);
        $this->assertSame('0.00', $student->credit_balance);
        $this->assertSame(CreditSettlementMethod::Wallet, $entry->payment_method);
        $this->assertFalse($entry->payment_method->bringsInCash());
    }

    public function test_settling_from_wallet_links_the_wallet_transaction(): void
    {
        $student = $this->studentOwing(100.00, 300.00);

        $entry = $this->ledger->settleFromWallet($student, 100.00, null, $this->staff);

        $this->assertNotNull($entry->wallet_transaction_id);
        $this->assertDatabaseHas('transactions', [
            'id' => $entry->wallet_transaction_id,
            'type' => 'withdraw',
        ]);
    }

    public function test_settling_from_wallet_with_insufficient_balance_leaves_both_ledgers_untouched(): void
    {
        $student = $this->studentOwing(150.00, 40.00);

        try {
            $this->ledger->settleFromWallet($student, 150.00, null, $this->staff);
            $this->fail('An insufficient wallet balance must abort the settlement.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('not enough to settle', $e->getMessage());
        }

        $student = $student->fresh()->load('wallet');

        $this->assertEquals(40.0, (float) $student->wallet->balanceFloat, 'The wallet must not be debited.');
        $this->assertEquals(150.0, (float) $student->credit_balance, 'The debt must not be reduced.');
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_settling_from_wallet_never_drives_the_wallet_negative(): void
    {
        $student = $this->studentOwing(500.00, 100.00);

        try {
            $this->ledger->settleFromWallet($student, 500.00, null, $this->staff);
        } catch (HttpException) {
            // expected
        }

        $student->refresh()->load('wallet');
        $this->assertGreaterThanOrEqual(0, (float) $student->wallet->balanceFloat);
    }

    public function test_partial_wallet_settlement_leaves_the_remainder_outstanding(): void
    {
        $student = $this->studentOwing(150.00, 100.00);

        $this->ledger->settleFromWallet($student, 100.00, null, $this->staff);

        $student = $student->fresh()->load('wallet');

        $this->assertEquals(0.0, (float) $student->wallet->balanceFloat);
        $this->assertEquals(50.0, (float) $student->credit_balance);
    }

    public function test_waive_writes_a_waived_entry_with_no_payment_method(): void
    {
        $student = $this->studentOwing(150.00);
        $reason = 'Student graduated; written off per admin approval.';

        $entry = $this->ledger->waive($student, 150.00, $reason, $this->staff);

        $this->assertSame(CreditTransactionType::Waived, $entry->type);
        $this->assertNull($entry->payment_method);
        $this->assertSame($reason, $entry->notes);
        $this->assertSame('0.00', $student->fresh()->credit_balance);
    }

    public function test_partial_waive_reduces_by_exactly_the_amount(): void
    {
        $student = $this->studentOwing(150.00);

        $this->ledger->waive($student, 50.00, 'Goodwill adjustment for disputed charge.', $this->staff);

        $this->assertEquals(100.0, (float) $student->fresh()->credit_balance);
    }

    public function test_waiving_more_than_outstanding_aborts_and_writes_nothing(): void
    {
        $student = $this->studentOwing(150.00);

        try {
            $this->ledger->waive($student, 200.00, 'Too much to waive.', $this->staff);
            $this->fail('Waiving more than the outstanding credit must abort.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertEquals(150.0, (float) $student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_every_entry_records_the_branch_and_the_performer(): void
    {
        $student = $this->studentOwing(100.00, 200.00);

        $this->ledger->charge($student, 20.00, 'R-9', $this->staff);
        $this->ledger->settleWithPayment($student, 30.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->ledger->settleFromWallet($student, 30.00, null, $this->staff);
        $this->ledger->waive($student, 20.00, 'Remaining balance waived.', $this->staff);

        $entries = CreditTransaction::where('student_id', $student->id)->get();

        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $this->assertSame($this->branch->id, $entry->branch_id, "{$entry->type->value} is missing its branch snapshot.");
            $this->assertSame($this->staff->id, $entry->performed_by, "{$entry->type->value} is missing its performer.");
            $this->assertNotNull($entry->created_at);
        }
    }

    public function test_the_service_does_not_leak_validation_exceptions(): void
    {
        $student = $this->studentOwing(10.00);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Amount exceeds outstanding credit of 10.00.');

        try {
            $this->ledger->settleWithPayment($student, 999.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        } catch (ValidationException $e) {
            $this->fail('Business-rule rejections must surface as HTTP 422 aborts, not validation exceptions.');
        }
    }
}
