<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Student;
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
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $perPage = $validated['per_page'] ?? 25;

        $studentIds = Student::where('branch_id', $branchId)->pluck('id');

        $query = CreditTransaction::with(['student', 'performer'])
            ->whereIn('student_id', $studentIds)
            ->when(isset($validated['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['date_to']))
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->when(isset($validated['search']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where(
                DB::raw("CONCAT(first_name, ' ', last_name)"),
                'like',
                "%{$validated['search']}%",
            )->orWhere('student_number', 'like', "%{$validated['search']}%")))
            ->latest('created_at');

        $summaryBase = CreditTransaction::whereIn('student_id', $studentIds)
            ->when(isset($validated['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['date_to']))
            ->when(isset($validated['search']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where(
                DB::raw("CONCAT(first_name, ' ', last_name)"),
                'like',
                "%{$validated['search']}%",
            )->orWhere('student_number', 'like', "%{$validated['search']}%")));

        $totalCharged = (float) (clone $summaryBase)->where('type', CreditTransactionType::Charged)->sum('amount');
        $totalSettled = (float) (clone $summaryBase)->where('type', CreditTransactionType::Settled)->sum('amount');
        $totalVoided = (float) (clone $summaryBase)->where('type', CreditTransactionType::Voided)->sum('amount');

        $transactions = $query->paginate($perPage);

        return response()->json([
            'data' => collect($transactions->items())->map(fn ($tx) => [
                'id' => $tx->id,
                'created_at' => $tx->created_at->toDateTimeString(),
                'student' => [
                    'id' => $tx->student?->id,
                    'full_name' => $tx->student?->full_name,
                    'student_number' => $tx->student?->student_number,
                ],
                'type' => $tx->type?->value,
                'amount' => (float) $tx->amount,
                'notes' => $tx->notes,
                'performed_by' => $tx->performer?->full_name ?? '—',
            ]),
            'meta' => $this->paginationMeta($transactions),
            'summary' => [
                'total_charged' => $totalCharged,
                'total_settled' => $totalSettled,
                'total_voided' => $totalVoided,
                'net_outstanding' => round($totalCharged - $totalSettled - $totalVoided, 2),
            ],
        ]);
    }
}
