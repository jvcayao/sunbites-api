<?php

namespace App\Services;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use App\Notifications\CreditChargedNotification;
use App\Notifications\CreditSettledNotification;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of the student credit ledger.
 *
 * Nothing outside this class may write to `credit_transactions` or
 * `students.credit_balance`. That constraint is what keeps the invariant
 * `credit_balance = Σcharged − Σsettled − Σwaived − Σvoided` true for every student.
 *
 * Every method locks the student row and performs all writes inside one transaction.
 * When called from a caller that already opened a transaction — CheckoutController and
 * TransactionController both do — Laravel nests via savepoint, which is the intended
 * behaviour and needs no special handling.
 */
class CreditLedgerService
{
    /**
     * Charge credit for an order whose wallet balance fell short.
     *
     * Takes the receipt number rather than an Order because CheckoutController charges
     * credit before it creates the order, so no Order exists yet. `order_id` is left
     * null, matching every charge row written before this service existed.
     */
    public function charge(Student $student, float $amount, string $receiptNumber, User $performer): CreditTransaction
    {
        return DB::transaction(function () use ($student, $amount, $receiptNumber, $performer): CreditTransaction {
            $locked = $this->lockStudent($student);
            $charged = round($amount, 2);

            $entry = $this->writeEntry($locked, [
                'type' => CreditTransactionType::Charged,
                'amount' => $charged,
                'notes' => "Credit used for order {$receiptNumber}.",
            ], $performer);

            $this->setBalance($locked, (float) $locked->credit_balance + $charged);
            $this->notifyParentsOfCharge($locked, $charged);

            return $entry;
        });
    }

    /**
     * Record a counter payment — cash, GCash, or bank transfer — against outstanding credit.
     */
    public function settleWithPayment(
        Student $student,
        float $amount,
        CreditSettlementMethod $method,
        ?string $referenceNumber,
        ?string $note,
        User $performer,
    ): CreditTransaction {
        return DB::transaction(function () use ($student, $amount, $method, $referenceNumber, $note, $performer): CreditTransaction {
            $locked = $this->lockStudent($student);
            $settled = $this->assertSettleable($locked, $amount);

            $entry = $this->writeEntry($locked, [
                'type' => CreditTransactionType::Settled,
                'amount' => $settled,
                'payment_method' => $method,
                'reference_number' => $referenceNumber,
                'notes' => $note,
            ], $performer);

            $this->setBalance($locked, (float) $locked->credit_balance - $settled);
            $this->notifyParentsOfSettlement($locked, $settled, wasWaived: false);

            return $entry;
        });
    }

    /**
     * Apply an existing wallet balance against outstanding credit.
     *
     * The wallet withdrawal and the ledger entry share one transaction, so the wallet can
     * never be debited without the debt being reduced by the same amount, or vice versa.
     */
    public function settleFromWallet(Student $student, float $amount, ?string $note, User $performer): CreditTransaction
    {
        return DB::transaction(function () use ($student, $amount, $note, $performer): CreditTransaction {
            $locked = $this->lockStudent($student);
            $settled = $this->assertSettleable($locked, $amount);

            $walletBalance = (float) ($locked->wallet?->balanceFloat ?? 0);

            abort_if(
                $walletBalance < $settled,
                422,
                'Wallet balance of '.number_format($walletBalance, 2).' is not enough to settle '.number_format($settled, 2).'.',
            );

            $walletTransaction = $locked->withdraw((int) round($settled * 100), [
                'source' => 'credit_settlement',
                'performed_by' => $performer->id,
            ]);

            $entry = $this->writeEntry($locked, [
                'type' => CreditTransactionType::Settled,
                'amount' => $settled,
                'payment_method' => CreditSettlementMethod::Wallet,
                'wallet_transaction_id' => $walletTransaction->id,
                'notes' => $note,
            ], $performer);

            $this->setBalance($locked, (float) $locked->credit_balance - $settled);
            $this->notifyParentsOfSettlement($locked, $settled, wasWaived: false);

            return $entry;
        });
    }

    /**
     * Write off credit that will not be collected. Admin-only, enforced by route middleware.
     */
    public function waive(Student $student, float $amount, string $reason, User $performer): CreditTransaction
    {
        return DB::transaction(function () use ($student, $amount, $reason, $performer): CreditTransaction {
            $locked = $this->lockStudent($student);
            $waived = $this->assertSettleable($locked, $amount);

            $entry = $this->writeEntry($locked, [
                'type' => CreditTransactionType::Waived,
                'amount' => $waived,
                'notes' => $reason,
            ], $performer);

            $this->setBalance($locked, (float) $locked->credit_balance - $waived);
            $this->notifyParentsOfSettlement($locked, $waived, wasWaived: true);

            return $entry;
        });
    }

    /**
     * Reverse the credit charged by an order that is being voided.
     *
     * Exempt from the abort-on-negative rule that governs settling and waiving: if the
     * credit was already settled before the void, only the outstanding remainder is
     * reversed and the difference is recorded in the notes. Aborting instead would block
     * the whole void, including its inventory restock and wallet refund.
     */
    public function void(Student $student, Order $order, User $performer): CreditTransaction
    {
        return DB::transaction(function () use ($student, $order, $performer): CreditTransaction {
            $locked = $this->lockStudent($student);

            $chargedAmount = round((float) $order->credit_amount, 2);
            $outstanding = round((float) $locked->credit_balance, 2);
            $reversible = round(min($chargedAmount, $outstanding), 2);

            $notes = "Credit reversed for voided order {$order->receipt_number}.";

            if ($reversible < $chargedAmount) {
                $notes .= ' '.number_format($chargedAmount - $reversible, 2)
                    .' of credit was already settled and is not reversed here.';
            }

            $entry = $this->writeEntry($locked, [
                'type' => CreditTransactionType::Voided,
                'amount' => $reversible,
                'order_id' => $order->id,
                'notes' => $notes,
            ], $performer);

            $this->setBalance($locked, $outstanding - $reversible);

            return $entry;
        });
    }

    private function lockStudent(Student $student): Student
    {
        return Student::withoutBranch()->lockForUpdate()->findOrFail($student->id);
    }

    /**
     * Tell every linked parent their child used credit.
     *
     * Debounced to one notification per parent per student per day. A student can take
     * several small credit charges in a single day, and the payload always states the
     * current outstanding total, so one message carries the full picture without spamming.
     */
    private function notifyParentsOfCharge(Student $locked, float $amount): void
    {
        foreach ($locked->parents as $parent) {
            $alreadySentToday = $parent->notifications()
                ->where('type', CreditChargedNotification::class)
                ->whereDate('created_at', now()->toDateString())
                ->get()
                ->contains(fn ($notification) => (int) ($notification->data['student_id'] ?? 0) === $locked->id);

            if ($alreadySentToday) {
                continue;
            }

            $parent->notify(new CreditChargedNotification(
                $parent,
                $locked,
                $amount,
                (float) $locked->credit_balance,
            ));
        }
    }

    /**
     * Tell every linked parent their child's credit was cleared. Never debounced: these are
     * rare, and a parent who paid needs immediate confirmation.
     */
    private function notifyParentsOfSettlement(Student $locked, float $amount, bool $wasWaived): void
    {
        foreach ($locked->parents as $parent) {
            $parent->notify(new CreditSettledNotification(
                $parent,
                $locked,
                $amount,
                (float) $locked->credit_balance,
                $wasWaived,
            ));
        }
    }

    /**
     * Resolve how much of the requested amount may be applied, rejecting anything
     * that would drive the balance negative rather than silently clamping it.
     */
    private function assertSettleable(Student $locked, float $amount): float
    {
        $outstanding = round((float) $locked->credit_balance, 2);
        $requested = round($amount, 2);

        abort_if($outstanding <= 0, 422, 'No outstanding credit to settle.');

        abort_if(
            $requested > $outstanding,
            422,
            'Amount exceeds outstanding credit of '.number_format($outstanding, 2).'.',
        );

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeEntry(Student $locked, array $attributes, User $performer): CreditTransaction
    {
        return CreditTransaction::create(array_merge([
            'student_id' => $locked->id,
            'branch_id' => $locked->branch_id,
            'order_id' => null,
            'payment_method' => null,
            'reference_number' => null,
            'wallet_transaction_id' => null,
            'notes' => null,
            'performed_by' => $performer->id,
            'created_at' => now(),
        ], $attributes));
    }

    private function setBalance(Student $locked, float $balance): void
    {
        $locked->update(['credit_balance' => round(max(0, $balance), 2)]);
    }
}
