<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ListPortalWalletRequest;
use App\Http\Requests\Portal\SetWalletAlertRequest;
use App\Models\Student;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class WalletController extends Controller
{
    #[Authorize('view', 'student')]
    public function index(ListPortalWalletRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 20;

        $transactions = Transaction::where('payable_type', Student::class)
            ->where('payable_id', $student->id)
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->when(isset($validated['from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['to']))
            ->latest()
            ->paginate($perPage);

        $pivot = $request->user()->students()->find($student->id)?->pivot;

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'credit_balance' => (float) $student->credit_balance,
            'wallet_alert_threshold' => (float) ($pivot?->wallet_alert_threshold ?? 0),
            'data' => $transactions->getCollection()->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amountFloat,
                'meta' => $tx->meta,
                'created_at' => $tx->created_at,
            ]),
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    #[Authorize('view', 'student')]
    public function setAlert(SetWalletAlertRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();

        $request->user()->students()->updateExistingPivot($student->id, [
            'wallet_alert_threshold' => $validated['threshold'],
        ]);

        return response()->json(['message' => 'Alert threshold updated.', 'threshold' => (float) $validated['threshold']]);
    }
}
