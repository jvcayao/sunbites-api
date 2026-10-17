<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\Wallet\VoidWalletTopUpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\TopUpWalletRequest;
use App\Http\Requests\VoidWalletTopUpRequest;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function topUp(TopUpWalletRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();

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

    public function voidTopUp(VoidWalletTopUpRequest $request, Student $student, int|string $transaction, VoidWalletTopUpAction $voidTopUp): JsonResponse
    {
        // The route constrains this segment to digits only (whereNumber), but a numeric
        // string longer than PHP's native int range would still throw a TypeError under
        // implicit coercion against a plain `int` parameter. Accepting int|string and
        // casting explicitly clamps instead of throwing, so an absurdly long digit string
        // resolves to a clean 404 (no matching transaction) rather than a 500.
        $transaction = (int) $transaction;

        $void = $voidTopUp->execute($student, $transaction, $request->user(), $request->validated('reason'));

        $student->refresh()->load('wallet');

        return response()->json([
            'message' => 'Top-up voided successfully.',
            'voided_amount' => (float) $void->voided_amount,
            'shortfall_amount' => (float) $void->shortfall_amount,
            'new_wallet_balance' => (float) ($student->wallet?->balanceFloatNum ?? 0.0),
            'new_credit_balance' => (float) $student->credit_balance,
        ]);
    }
}
