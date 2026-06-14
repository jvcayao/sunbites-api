<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $transactions = Transaction::where('payable_type', Student::class)
            ->where('payable_id', $student->id)
            ->latest()
            ->paginate($perPage);

        $pivot = $request->user()->students()->wherePivot('student_id', $student->id)->first()?->pivot;

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'wallet_alert_threshold' => $pivot ? (float) $pivot->wallet_alert_threshold : 0.0,
            'data' => collect($transactions->items())->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amountFloat,
                'meta' => $tx->meta,
                'created_at' => $tx->created_at,
            ]),
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    public function setAlert(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'threshold' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $request->user()->students()->updateExistingPivot($student->id, [
            'wallet_alert_threshold' => $validated['threshold'],
        ]);

        return response()->json(['message' => 'Alert threshold updated.', 'threshold' => (float) $validated['threshold']]);
    }
}
