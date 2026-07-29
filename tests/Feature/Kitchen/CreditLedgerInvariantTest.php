<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The ledger invariant: for every student, at all times,
 * credit_balance = Σcharged − Σsettled − Σwaived − Σvoided.
 *
 * This holds only because CreditLedgerService is the sole writer of both the ledger
 * and the balance column. These tests are the guard on that property.
 */
class CreditLedgerInvariantTest extends TestCase
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

    private function sumOf(Student $student, CreditTransactionType $type): float
    {
        return (float) CreditTransaction::where('student_id', $student->id)
            ->where('type', $type->value)
            ->sum('amount');
    }

    private function assertInvariantHolds(Student $student): void
    {
        $expected = round(
            $this->sumOf($student, CreditTransactionType::Charged)
            - $this->sumOf($student, CreditTransactionType::Settled)
            - $this->sumOf($student, CreditTransactionType::Waived)
            - $this->sumOf($student, CreditTransactionType::Voided),
            2,
        );

        $this->assertEquals(
            $expected,
            round((float) $student->fresh()->credit_balance, 2),
            'credit_balance drifted from the ledger: Σcharged − Σsettled − Σwaived − Σvoided.',
        );
    }

    public function test_invariant_holds_across_a_mixed_sequence_of_every_operation(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->ledger->charge($student, 100.00, 'R-1', $this->staff);
        $this->assertInvariantHolds($student);

        $this->ledger->charge($student, 50.00, 'R-2', $this->staff);
        $this->assertInvariantHolds($student);

        $this->ledger->settleWithPayment($student, 75.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->assertInvariantHolds($student);

        $this->ledger->waive($student, 25.00, 'Partial write-off for testing.', $this->staff);
        $this->assertInvariantHolds($student);

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->staff->id,
            'student_id' => $student->id,
            'payment_method' => PaymentMethod::Wallet->value,
            'is_credit' => true,
            'credit_amount' => 50.00,
            'total' => 50.00,
            'status' => OrderStatus::Completed->value,
        ]);

        $this->ledger->void($student, $order, $this->staff);
        $this->assertInvariantHolds($student);

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
    }

    public function test_invariant_holds_when_settling_from_wallet(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 0,
        ]);
        $student->deposit(50000);

        $this->ledger->charge($student, 200.00, 'R-W1', $this->staff);
        $this->ledger->settleFromWallet($student, 80.00, null, $this->staff);
        $this->assertInvariantHolds($student);

        $this->ledger->settleWithPayment($student, 20.00, CreditSettlementMethod::Gcash, 'GC1', null, $this->staff);
        $this->assertInvariantHolds($student);

        $this->ledger->settleFromWallet($student, 100.00, null, $this->staff);
        $this->assertInvariantHolds($student);

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
    }

    public function test_invariant_survives_fractional_amounts(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->ledger->charge($student, 33.33, 'R-F1', $this->staff);
        $this->ledger->charge($student, 33.33, 'R-F2', $this->staff);
        $this->ledger->charge($student, 33.34, 'R-F3', $this->staff);
        $this->assertInvariantHolds($student);
        $this->assertEquals(100.0, (float) $student->fresh()->credit_balance);

        $this->ledger->settleWithPayment($student, 0.01, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->assertInvariantHolds($student);
        $this->assertEquals(99.99, (float) $student->fresh()->credit_balance);
    }

    public function test_a_rejected_settlement_leaves_the_balance_untouched(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 40.00,
        ]);

        try {
            $this->ledger->settleWithPayment($student, 40.01, CreditSettlementMethod::Cash, null, null, $this->staff);
        } catch (HttpException) {
            // expected
        }

        $this->assertEquals(40.0, (float) $student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_a_second_full_settlement_is_rejected_rather_than_driving_the_balance_negative(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);

        $this->ledger->settleWithPayment($student, 150.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);

        try {
            $this->ledger->settleWithPayment($student, 150.00, CreditSettlementMethod::Cash, null, null, $this->staff);
            $this->fail('A duplicate full settlement must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('No outstanding credit to settle.', $e->getMessage());
        }

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
        $this->assertSame(1, CreditTransaction::where('student_id', $student->id)->count());
    }

    public function test_the_balance_is_never_negative_after_any_operation(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(100000);

        $this->ledger->charge($student, 300.00, 'R-N1', $this->staff);

        foreach ([100.00, 100.00, 100.00] as $amount) {
            $this->ledger->settleFromWallet($student, $amount, null, $this->staff);
            $this->assertGreaterThanOrEqual(0, (float) $student->fresh()->credit_balance);
            $this->assertInvariantHolds($student);
        }

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
    }

    public function test_invariant_holds_independently_per_student(): void
    {
        $first = Student::factory()->create(['branch_id' => $this->branch->id]);
        $second = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->ledger->charge($first, 100.00, 'R-A', $this->staff);
        $this->ledger->charge($second, 40.00, 'R-B', $this->staff);
        $this->ledger->settleWithPayment($first, 60.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->ledger->waive($second, 40.00, 'Written off for the second student.', $this->staff);

        $this->assertInvariantHolds($first);
        $this->assertInvariantHolds($second);

        $this->assertEquals(40.0, (float) $first->fresh()->credit_balance);
        $this->assertEquals(0.0, (float) $second->fresh()->credit_balance);
    }
}
