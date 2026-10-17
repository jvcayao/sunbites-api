<?php

namespace App\Actions\Wallet;

use App\Models\Student;
use App\Models\User;
use App\Models\WalletTopupVoid;
use App\Services\CreditLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Void a wallet top-up: withdraw what is still in the wallet and charge the rest as credit.
 *
 * The student comes from branch-scoped route model binding. The transaction lookup is limited
 * to that student's wallet, so another student's top-up is reported as not found.
 */
class VoidWalletTopUpAction
{
    public function __construct(private readonly CreditLedgerService $creditLedger) {}

    public function execute(Student $student, int $transactionId, User $performer, string $reason): WalletTopupVoid
    {
        $walletId = $student->wallet?->id;
        abort_if($walletId === null, 404, 'Top-up transaction not found.');

        $depositTransaction = DB::table('transactions')
            ->where('id', $transactionId)
            ->where('wallet_id', $walletId)
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->whereNull('deleted_at')
            ->first();

        abort_if($depositTransaction === null, 404, 'Top-up transaction not found.');

        abort_if(
            WalletTopupVoid::where('wallet_transaction_id', $transactionId)->exists(),
            422,
            'This top-up has already been voided.'
        );

        $meta = json_decode((string) $depositTransaction->meta, true) ?? [];
        $originalPerformerId = $meta['performed_by'] ?? $meta['cashier_id'] ?? null;
        $originalPerformerId = $originalPerformerId === null ? null : (int) $originalPerformerId;

        abort_if(
            $originalPerformerId === $performer->id,
            403,
            'You cannot void a top-up you performed yourself. Ask another admin, manager, or supervisor to void it.'
        );

        $isAdmin = $performer->hasRole('admin');
        $createdToday = Carbon::parse($depositTransaction->created_at)->isToday();

        abort_if(
            ! $isAdmin && ! $createdToday,
            403,
            'This top-up is outside today and can only be voided by an admin.'
        );

        $void = DB::transaction(function () use ($student, $transactionId, $meta, $reason, $performer) {
            $locked = Student::withoutBranch()->lockForUpdate()->findOrFail($student->id);
            $locked->load('wallet');

            // Race guard: two concurrent void requests for the same transaction both pass the
            // unlocked check above; the loser here fails cleanly instead of double-refunding.
            // The DB-level UNIQUE index on wallet_transaction_id (see migration) is the backstop
            // if this in-transaction check is ever bypassed by a future code path.
            abort_if(
                WalletTopupVoid::where('wallet_transaction_id', $transactionId)->exists(),
                422,
                'This top-up has already been voided.'
            );

            $depositTransaction = DB::table('transactions')->where('id', $transactionId)->first();
            $originalAmount = round(abs((float) $depositTransaction->amount) / 100, 2);
            $currentBalance = (float) ($locked->wallet?->balanceFloatNum ?? 0.0);

            $voidedAmount = round(min($originalAmount, $currentBalance), 2);
            $shortfallAmount = round($originalAmount - $voidedAmount, 2);

            $refundTransactionId = null;
            if ($voidedAmount > 0) {
                $refundTransaction = $locked->withdraw((int) round($voidedAmount * 100), [
                    'source' => 'topup_void_refund',
                    'note' => $reason,
                    'performed_by' => $performer->id,
                    'original_wallet_transaction_id' => $transactionId,
                ]);
                $refundTransactionId = $refundTransaction->id;
            }

            $creditTransactionId = null;
            if ($shortfallAmount > 0) {
                $creditEntry = $this->creditLedger->charge(
                    $locked,
                    $shortfallAmount,
                    "voided top-up #{$transactionId}",
                    $performer,
                );
                $creditTransactionId = $creditEntry->id;
            }

            $void = WalletTopupVoid::create([
                'student_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'wallet_transaction_id' => $transactionId,
                'refund_wallet_transaction_id' => $refundTransactionId,
                'credit_transaction_id' => $creditTransactionId,
                'original_amount' => $originalAmount,
                'voided_amount' => $voidedAmount,
                'shortfall_amount' => $shortfallAmount,
                'void_reason' => $reason,
                'voided_by' => $performer->id,
                'created_at' => now(),
            ]);

            DB::table('transactions')->where('id', $transactionId)->update([
                'meta' => json_encode([
                    ...$meta,
                    'voided_at' => now()->toIso8601String(),
                    'wallet_topup_void_id' => $void->id,
                ]),
            ]);

            return $void;
        });

        $student->refresh()->load('wallet');

        activity('wallet')
            ->causedBy($performer)
            ->performedOn($student)
            ->withProperties([
                'original_amount' => (float) $void->original_amount,
                'voided_amount' => (float) $void->voided_amount,
                'shortfall_amount' => (float) $void->shortfall_amount,
                'void_reason' => $void->void_reason,
                'original_performer_id' => $originalPerformerId,
                'voided_by' => $performer->id,
            ])
            ->log('wallet.topup_voided');

        return $void;
    }
}
