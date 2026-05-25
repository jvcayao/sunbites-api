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

        $walletSummary = DB::table('transactions')
            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
            ->where('wallets.holder_type', Student::class)
            ->whereIn('wallets.holder_id', Student::where('branch_id', $branchId)->select('id'))
            ->whereBetween('transactions.created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw("
                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) / 100.0 as total_credits,
                SUM(CASE WHEN type = 'withdraw' THEN amount ELSE 0 END) / 100.0 as total_debits
            ")
            ->first();

        $totalCredits = (float) ($walletSummary?->total_credits ?? 0);
        $totalDebits = (float) ($walletSummary?->total_debits ?? 0);

        $studentsBelowHundred = Student::where('branch_id', $branchId)
            ->whereHas('wallet', fn ($q) => $q->whereRaw('(balance / 100.0) < 100'))
            ->count();

        $studentsQuery = Student::where('branch_id', $branchId)
            ->with('wallet')
            ->withSum(
                ['creditTransactions as total_credit_raw' => fn ($q) => $q->where('type', 'Charged')],
                'amount',
            )
            ->orderBy('last_name')
            ->orderBy('first_name');

        $students = $studentsQuery->paginate($perPage);

        $studentData = collect($students->items())->map(function ($student) use ($dateFrom, $dateTo) {
            $lastTx = DB::table('transactions')
                ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
                ->where('wallets.holder_type', Student::class)
                ->where('wallets.holder_id', $student->id)
                ->whereBetween('transactions.created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
                ->latest('transactions.created_at')
                ->value('transactions.created_at');

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'grade_level' => $student->grade_level,
                'wallet_balance' => (float) ($student->wallet?->balanceFloat ?? 0),
                'credit_balance' => (float) $student->credit_balance,
                'total_spent' => (float) $student->total_spent,
                'last_transaction_date' => $lastTx,
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
