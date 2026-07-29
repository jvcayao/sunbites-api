<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettleCreditRequest;
use App\Http\Requests\WaiveCreditRequest;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Services\CreditLedgerService;
use Illuminate\Http\JsonResponse;

class CreditController extends Controller
{
    public function __construct(private CreditLedgerService $creditLedger) {}

    public function settle(SettleCreditRequest $request, Student $student): JsonResponse
    {
        $method = $request->settlementMethod();
        $amount = (float) $request->validated('amount');
        $note = $request->validated('note');

        $entry = $method === CreditSettlementMethod::Wallet
            ? $this->creditLedger->settleFromWallet($student, $amount, $note, $request->user())
            : $this->creditLedger->settleWithPayment(
                $student,
                $amount,
                $method,
                $request->validated('reference_number'),
                $note,
                $request->user(),
            );

        $student->refresh()->load('wallet');

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount_settled' => (float) $entry->amount,
                'payment_method' => $method->value,
                'settled_by' => $request->user()->id,
                'credit_balance_after' => (float) $student->credit_balance,
            ])
            ->log('wallet.credit_settled');

        return response()->json([
            'message' => 'Credit settled.',
            'amount_settled' => (float) $entry->amount,
            'credit_balance' => (float) $student->credit_balance,
            'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'transaction' => $this->entryPayload($entry),
        ]);
    }

    public function waive(WaiveCreditRequest $request, Student $student): JsonResponse
    {
        $entry = $this->creditLedger->waive(
            $student,
            (float) $request->validated('amount'),
            $request->validated('reason'),
            $request->user(),
        );

        $student->refresh()->load('wallet');

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount_waived' => (float) $entry->amount,
                'reason' => $request->validated('reason'),
                'waived_by' => $request->user()->id,
                'credit_balance_after' => (float) $student->credit_balance,
            ])
            ->log('wallet.credit_waived');

        return response()->json([
            'message' => 'Credit waived.',
            'amount_waived' => (float) $entry->amount,
            'credit_balance' => (float) $student->credit_balance,
            'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'transaction' => $this->entryPayload($entry),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(CreditTransaction $entry): array
    {
        return [
            'id' => "credit-{$entry->id}",
            'date' => $entry->created_at?->toIso8601String(),
            'type' => $entry->type?->value,
            'amount' => (float) $entry->amount,
            'payment_method' => $entry->payment_method?->value,
            'reference_number' => $entry->reference_number,
            'note' => $entry->notes,
        ];
    }
}
