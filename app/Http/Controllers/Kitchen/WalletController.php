<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\VoidWalletTopUpRequest;
use App\Models\Student;
use App\Models\WalletTopupVoid;
use App\Services\CreditLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function __construct(private CreditLedgerService $creditLedger) {}

    public function topUp(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_method' => ['required', 'in:cash,gcash,bank_transfer'],
            'reference_number' => [
                'nullable',
                'string',
                'alpha_num',
                'max:50',
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $student->deposit((int) round($validated['amount'] * 100), [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'note' => isset($validated['note']) ? strip_tags($validated['note']) : null,
            'performed_by' => $request->user()->id,
        ]);

        $student->load('wallet');

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference_number'] ?? null,
                'new_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            ])
            ->log('wallet.topped_up');

        return response()->json([
            'message' => 'Wallet topped up successfully.',
            'new_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
        ]);
    }

    public function voidTopUp(VoidWalletTopUpRequest $request, Student $student, int|string $transaction): JsonResponse
    {
        // The route constrains this segment to digits only (whereNumber), but a numeric
        // string longer than PHP's native int range would still throw a TypeError under
        // implicit coercion against a plain `int` parameter. Accepting int|string and
        // casting explicitly clamps instead of throwing, so an absurdly long digit string
        // resolves to a clean 404 (no matching transaction) rather than a 500.
        $transaction = (int) $transaction;

        $walletId = $student->wallet?->id;
        abort_if($walletId === null, 404, 'Top-up transaction not found.');

        $depositTransaction = DB::table('transactions')
            ->where('id', $transaction)
            ->where('wallet_id', $walletId)
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->whereNull('deleted_at')
            ->first();

        abort_if($depositTransaction === null, 404, 'Top-up transaction not found.');

        abort_if(
            WalletTopupVoid::where('wallet_transaction_id', $transaction)->exists(),
            422,
            'This top-up has already been voided.'
        );

        $meta = json_decode((string) $depositTransaction->meta, true) ?? [];
        $originalPerformerId = $meta['performed_by'] ?? $meta['cashier_id'] ?? null;
        $originalPerformerId = $originalPerformerId === null ? null : (int) $originalPerformerId;

        abort_if(
            $originalPerformerId === $request->user()->id,
            403,
            'You cannot void a top-up you performed yourself. Ask another admin, manager, or supervisor to void it.'
        );

        $isAdmin = $request->user()->hasRole('admin');
        $createdToday = Carbon::parse($depositTransaction->created_at)->isToday();

        abort_if(
            ! $isAdmin && ! $createdToday,
            403,
            'This top-up is outside today and can only be voided by an admin.'
        );

        $reason = $request->validated('reason');

        $void = DB::transaction(function () use ($student, $transaction, $meta, $reason, $request) {
            $locked = Student::withoutBranch()->lockForUpdate()->findOrFail($student->id);
            $locked->load('wallet');

            // Race guard: two concurrent void requests for the same transaction both pass the
            // unlocked check above; the loser here fails cleanly instead of double-refunding.
            // The DB-level UNIQUE index on wallet_transaction_id (see migration) is the backstop
            // if this in-transaction check is ever bypassed by a future code path.
            abort_if(
                WalletTopupVoid::where('wallet_transaction_id', $transaction)->exists(),
                422,
                'This top-up has already been voided.'
            );

            $depositTransaction = DB::table('transactions')->where('id', $transaction)->first();
            $originalAmount = round(abs((float) $depositTransaction->amount) / 100, 2);
            $currentBalance = (float) ($locked->wallet?->balanceFloatNum ?? 0.0);

            $voidedAmount = round(min($originalAmount, $currentBalance), 2);
            $shortfallAmount = round($originalAmount - $voidedAmount, 2);

            $refundTransactionId = null;
            if ($voidedAmount > 0) {
                $refundTransaction = $locked->withdraw((int) round($voidedAmount * 100), [
                    'source' => 'topup_void_refund',
                    'note' => $reason,
                    'performed_by' => $request->user()->id,
                    'original_wallet_transaction_id' => $transaction,
                ]);
                $refundTransactionId = $refundTransaction->id;
            }

            $creditTransactionId = null;
            if ($shortfallAmount > 0) {
                $creditEntry = $this->creditLedger->charge(
                    $locked,
                    $shortfallAmount,
                    "voided top-up #{$transaction}",
                    $request->user(),
                );
                $creditTransactionId = $creditEntry->id;
            }

            $void = WalletTopupVoid::create([
                'student_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'wallet_transaction_id' => $transaction,
                'refund_wallet_transaction_id' => $refundTransactionId,
                'credit_transaction_id' => $creditTransactionId,
                'original_amount' => $originalAmount,
                'voided_amount' => $voidedAmount,
                'shortfall_amount' => $shortfallAmount,
                'void_reason' => $reason,
                'voided_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            DB::table('transactions')->where('id', $transaction)->update([
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
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'original_amount' => (float) $void->original_amount,
                'voided_amount' => (float) $void->voided_amount,
                'shortfall_amount' => (float) $void->shortfall_amount,
                'void_reason' => $void->void_reason,
                'original_performer_id' => $originalPerformerId,
                'voided_by' => $request->user()->id,
            ])
            ->log('wallet.topup_voided');

        return response()->json([
            'message' => 'Top-up voided successfully.',
            'voided_amount' => (float) $void->voided_amount,
            'shortfall_amount' => (float) $void->shortfall_amount,
            'new_wallet_balance' => (float) ($student->wallet?->balanceFloatNum ?? 0.0),
            'new_credit_balance' => (float) $student->credit_balance,
        ]);
    }
}
