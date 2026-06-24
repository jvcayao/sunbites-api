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

        $months = $validated['months'] ?? 6;

        $base = fn () => $student->orders()
            ->whereNull('voided_at')
            ->where('status', OrderStatus::Completed);

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

        $monthly = collect(range($months - 1, 0))->map(function (int $i) use ($rawMonthly) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');

            return [
                'month' => $key,
                'label' => $date->format('M'),
                'total' => (float) ($rawMonthly[$key] ?? 0),
            ];
        });

        $schoolYearStart = now()->month >= 6
            ? Carbon::create(now()->year, 6, 1)->startOfDay()
            : Carbon::create(now()->year - 1, 6, 1)->startOfDay();

        $lastMonth = now()->subMonth();

        $ytdTotal = $base()->where('created_at', '>=', $schoolYearStart)->sum('total');
        $thisMonthTotal = $base()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('total');
        $lastMonthTotal = $base()->whereMonth('created_at', $lastMonth->month)->whereYear('created_at', $lastMonth->year)->sum('total');

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

        $methodCounts = $base()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('payment_method, COUNT(*) as count')
            ->groupBy('payment_method')
            ->pluck('count', 'payment_method');

        $totalOrders = $methodCounts->sum();
        $methods = ['wallet', 'cash', 'subscription', 'gcash'];

        $split = collect($methods)->mapWithKeys(fn (string $method) => [
            $method => $totalOrders > 0
                ? (int) round(($methodCounts->get($method, 0) / $totalOrders) * 100)
                : 0,
        ])->all();

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
