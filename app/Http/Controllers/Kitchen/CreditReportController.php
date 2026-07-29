<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreditReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'type' => ['nullable', 'string', Rule::enum(CreditTransactionType::class)],
            'payment_method' => ['nullable', 'string', Rule::enum(CreditSettlementMethod::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $perPage = $validated['per_page'] ?? 25;

        $query = $this->baseQuery($branchId, $validated)
            ->with(['student', 'performer'])
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->when(isset($validated['payment_method']), fn ($q) => $q->where('payment_method', $validated['payment_method']))
            ->latest('created_at');

        $transactions = $query->paginate($perPage);

        return response()->json([
            'data' => collect($transactions->items())->map(fn (CreditTransaction $tx) => [
                'id' => $tx->id,
                'created_at' => $tx->created_at->toDateTimeString(),
                'student' => [
                    'id' => $tx->student?->id,
                    'full_name' => $tx->student?->full_name,
                    'student_number' => $tx->student?->student_number,
                    'grade_level' => $tx->student?->grade_level,
                ],
                'type' => $tx->type?->value,
                'amount' => (float) $tx->amount,
                'payment_method' => $tx->payment_method?->value,
                'payment_method_label' => $tx->payment_method?->label(),
                'reference_number' => $tx->reference_number,
                'notes' => $tx->notes,
                'performed_by' => $tx->performer?->full_name ?? '—',
            ]),
            'meta' => $this->paginationMeta($transactions),
            'summary' => $this->summary($branchId, $validated),
        ]);
    }

    /**
     * Branch filtering keys off the `branch_id` snapshot on the ledger entry rather than
     * resolving the branch's student ids first. That keeps historical entries attributed to
     * the branch where they happened, even after a student transfers.
     *
     * @param  array<string, mixed>  $validated
     */
    private function baseQuery(int $branchId, array $validated): Builder
    {
        return CreditTransaction::query()
            ->where('branch_id', $branchId)
            ->when(isset($validated['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['date_to']))
            ->when(isset($validated['search']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where(
                DB::raw("CONCAT(first_name, ' ', last_name)"),
                'like',
                "%{$validated['search']}%",
            )->orWhere('student_number', 'like', "%{$validated['search']}%")));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function summary(int $branchId, array $validated): array
    {
        $totals = $this->baseQuery($branchId, $validated)
            ->selectRaw('type, SUM(amount) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $charged = (float) ($totals[CreditTransactionType::Charged->value] ?? 0);
        $settled = (float) ($totals[CreditTransactionType::Settled->value] ?? 0);
        $waived = (float) ($totals[CreditTransactionType::Waived->value] ?? 0);
        $voided = (float) ($totals[CreditTransactionType::Voided->value] ?? 0);

        $byMethod = $this->baseQuery($branchId, $validated)
            ->where('type', CreditTransactionType::Settled->value)
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $settledByMethod = [];
        $cashCollected = 0.0;

        foreach (CreditSettlementMethod::cases() as $method) {
            $amount = round((float) ($byMethod[$method->value] ?? 0), 2);
            $settledByMethod[$method->value] = $amount;

            if ($method->bringsInCash()) {
                $cashCollected += $amount;
            }
        }

        return [
            'total_charged' => round($charged, 2),
            'total_settled' => round($settled, 2),
            'total_waived' => round($waived, 2),
            'total_voided' => round($voided, 2),
            'net_outstanding' => round($charged - $settled - $waived - $voided, 2),
            'settled_by_method' => $settledByMethod,
            'settled_bringing_in_cash' => round($cashCollected, 2),
        ];
    }
}
