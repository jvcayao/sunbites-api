<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\WalletReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $students = Student::where('branch_id', $branchId)
            ->where(function ($q) {
                $q->whereHas('wallet', fn ($w) => $w->where('balance', '>', 0))
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('transactions')
                            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
                            ->whereColumn('wallets.holder_id', 'students.id')
                            ->where('wallets.holder_type', Student::class);
                    });
            })
            ->with('wallet')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);

        // Single aggregation query replacing the old N+1 loop
        $studentIds = collect($students->items())->pluck('id')->all();

        $txStats = DB::table('transactions')
            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
            ->where('wallets.holder_type', Student::class)
            ->whereIn('wallets.holder_id', $studentIds)
            ->whereBetween('transactions.created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw("
                wallets.holder_id AS student_id,
                SUM(CASE WHEN type = 'deposit' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_credited,
                SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_debited,
                MAX(transactions.created_at) AS last_transaction
            ")
            ->groupBy('wallets.holder_id')
            ->get()
            ->keyBy('student_id');

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

        $students = Student::where('branch_id', $branchId)
            ->with('wallet')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function ($student) {
                $lastTx = DB::table('transactions')
                    ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
                    ->where('wallets.holder_type', Student::class)
                    ->where('wallets.holder_id', $student->id)
                    ->latest('transactions.created_at')
                    ->value('transactions.created_at');

                $student->last_transaction_date = $lastTx;

                return $student;
            });

        $filename = "wallet-report-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new WalletReportExport($students), $filename);
    }
}
