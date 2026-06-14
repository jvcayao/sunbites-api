<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\WalletReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WalletReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $perPage = $validated['per_page'] ?? 25;

        // Branch-level summary (ABS fixes negative withdrawal amounts stored by bavix)
        $walletSummary = DB::table('transactions')
            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
            ->where('wallets.holder_type', Student::class)
            ->whereIn('wallets.holder_id', Student::where('branch_id', $branchId)->select('id'))
            ->whereBetween('transactions.created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw("
                SUM(CASE WHEN type = 'deposit' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_credits,
                SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_debits
            ")
            ->first();

        $totalCredits = (float) ($walletSummary?->total_credits ?? 0);
        $totalDebits = (float) ($walletSummary?->total_debits ?? 0);

        $studentsBelowHundred = Student::where('branch_id', $branchId)
            ->whereHas('wallet', fn ($q) => $q->whereRaw('(balance / 100.0) < 100'))
            ->count();

        // Only include students who have real wallet activity (wallet exists with balance > 0 OR has transactions)
        $students = $this->walletActivityStudents($branchId)
            ->with('wallet')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);

        // Single aggregation query replacing the old N+1 loop
        $studentIds = collect($students->items())->pluck('id')->all();

        $txStats = $this->buildTxStats($studentIds, $dateFrom, $dateTo);

        $studentData = collect($students->items())->map(function ($student) use ($txStats) {
            $stats = $txStats->get($student->id);

            return [
                'id' => $student->id,
                'student_name' => $student->full_name,
                'grade_level' => $student->grade_level,
                'current_balance' => (float) ($student->wallet?->balanceFloat ?? 0),
                'outstanding_credit' => (float) $student->credit_balance,
                'total_credited' => (float) ($stats?->total_credited ?? 0),
                'total_debited' => (float) ($stats?->total_debited ?? 0),
                'last_transaction' => $stats?->last_transaction,
            ];
        });

        return response()->json([
            'data' => $studentData,
            'meta' => $this->paginationMeta($students),
            'summary' => [
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'net_movement' => round($totalCredits - $totalDebits, 2),
                'students_below_100' => $studentsBelowHundred,
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $branch = app('active_branch');
        $branchId = $branch->id;

        // Only include students who have real wallet activity (same filter as index())
        $students = $this->walletActivityStudents($branchId)
            ->with('wallet')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // Compute all-time credited/debited totals per student in one aggregation query (no N+1)
        $studentIds = $students->pluck('id')->all();

        $txStats = $this->buildTxStats($studentIds);

        $students->each(function ($student) use ($txStats) {
            $stats = $txStats->get($student->id);
            $student->total_credited = (float) ($stats?->total_credited ?? 0);
            $student->total_debited = (float) ($stats?->total_debited ?? 0);
            $student->last_transaction_date = $stats?->last_transaction;
        });

        $filename = "wallet-report-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new WalletReportExport($students), $filename);
    }

    private function walletActivityStudents(int $branchId): Builder
    {
        return Student::where('branch_id', $branchId)
            ->whereHas('wallet', fn ($q) => $q->where('balance', '>', 0)->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('transactions')
                    ->join('wallets as wt2', 'wt2.id', '=', 'transactions.wallet_id')
                    ->whereColumn('wt2.holder_id', 'wallets.holder_id')
                    ->where('wt2.holder_type', Student::class);
            }));
    }

    private function buildTxStats(array $studentIds, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        $query = DB::table('transactions')
            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
            ->where('wallets.holder_type', Student::class)
            ->whereIn('wallets.holder_id', $studentIds)
            ->selectRaw("
                wallets.holder_id AS student_id,
                SUM(CASE WHEN type = 'deposit' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_credited,
                SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_debited,
                MAX(transactions.created_at) AS last_transaction
            ")
            ->groupBy('wallets.holder_id');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('transactions.created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"]);
        }

        return $query->get()->keyBy('student_id');
    }
}
