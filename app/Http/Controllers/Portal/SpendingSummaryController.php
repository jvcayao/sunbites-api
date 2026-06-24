<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Student;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SpendingSummaryController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $months = (int) ($validated['months'] ?? 6);

        $base = fn () => $student->orders()
            ->whereNull('voided_at')
            ->where('status', OrderStatus::Completed);

        // Monthly totals for chart (last N calendar months)
        $chartFrom = now()->subMonths($months - 1)->startOfMonth();

        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at) as month"
            : "DATE_FORMAT(created_at, '%Y-%m') as month";

        $rawMonthly = $base()
            ->where('created_at', '>=', $chartFrom)
            ->selectRaw("{$monthExpr}, SUM(total) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $monthly = collect();
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');
            $monthly->push([
                'month' => $key,
                'label' => $date->format('M'),
                'total' => (float) ($rawMonthly[$key] ?? 0),
            ]);
        }

        // YTD: from school year start (June 1), independent of chart window
        $schoolYearStart = now()->month >= 6
            ? Carbon::create(now()->year, 6, 1)->startOfDay()
            : Carbon::create(now()->year - 1, 6, 1)->startOfDay();

        $ytdTotal = $base()
            ->where('created_at', '>=', $schoolYearStart)
            ->sum('total');

        // This month and last month totals
        $thisMonthTotal = $base()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $lastMonthTotal = $base()
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('total');

        // Top 5 items by order count for the current calendar month
        $topItems = OrderItem::whereHas('order', function ($q) use ($student) {
            $q->where('student_id', $student->id)
                ->whereNull('voided_at')
                ->where('status', OrderStatus::Completed)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year);
        })
            ->selectRaw('name, COUNT(*) as count')
            ->groupBy('name')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Payment method split by order count for the current calendar month
        $methodCounts = $base()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('payment_method, COUNT(*) as count')
            ->groupBy('payment_method')
            ->pluck('count', 'payment_method')
            ->toArray();

        $totalOrders = array_sum($methodCounts);
        $split = ['wallet' => 0, 'cash' => 0, 'subscription' => 0, 'gcash' => 0];

        if ($totalOrders > 0) {
            foreach (array_keys($split) as $key) {
                $split[$key] = (int) round((($methodCounts[$key] ?? 0) / $totalOrders) * 100);
            }
        }

        return response()->json([
            'monthly' => $monthly->values(),
            'top_items' => $topItems,
            'payment_method_split' => $split,
            'ytd_total' => (float) $ytdTotal,
            'this_month_total' => (float) $thisMonthTotal,
            'last_month_total' => (float) $lastMonthTotal,
        ]);
    }
}
