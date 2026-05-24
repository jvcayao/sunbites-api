<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Student;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parent = $request->user();

        $students = $parent->students()->with(['branch:id,name', 'wallet'])->get();

        $studentIds = $students->pluck('id');

        $todayOrders = Order::whereIn('student_id', $studentIds)
            ->whereDate('created_at', today())
            ->whereNull('voided_at')
            ->with('student:id,first_name,last_name')
            ->get();

        $recentTransactions = Transaction::whereHasMorph('payable', [Student::class], fn ($q) => $q->whereIn('id', $studentIds))
            ->with('payable:id,first_name,last_name')
            ->latest()
            ->take(10)
            ->get();

        $summary = $students->map(fn ($student) => [
            'student_id' => $student->id,
            'full_name' => $student->full_name,
            'branch_name' => $student->branch->name,
            'wallet_balance' => $student->wallet?->balanceFloat ?? 0,
            'total_spent' => (float) $student->total_spent,
            'today_orders_count' => $todayOrders->where('student_id', $student->id)->count(),
            'today_total' => (float) $todayOrders->where('student_id', $student->id)->sum('total'),
        ]);

        return response()->json([
            'summary' => $summary,
            'recent_transactions' => $recentTransactions->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amountFloat,
                'student_name' => $tx->payable?->full_name,
                'meta' => $tx->meta,
                'created_at' => $tx->created_at,
            ]),
        ]);
    }
}
