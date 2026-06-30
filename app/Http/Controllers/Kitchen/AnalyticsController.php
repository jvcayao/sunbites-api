<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_month' => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'from_year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'to_month' => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'to_year' => ['required', 'integer', 'min:2020', 'max:2099'],
        ]);

        $branchId = app('active_branch')->id;
        $start = Carbon::create($validated['from_year'], SchoolMonth::from($validated['from_month'])->toMonthNumber(), 1)->startOfMonth();
        $end = Carbon::create($validated['to_year'], SchoolMonth::from($validated['to_month'])->toMonthNumber(), 1)->endOfMonth();
        $periods = $this->monthPeriods($start, $end);

        return response()->json([
            'period' => $this->buildPeriodMeta($validated, $periods),
            'sales' => $this->buildSales($branchId, $start, $end, $periods),
            'students' => $this->buildStudents($branchId, $start, $end, $periods),
            'billing' => $this->buildBilling($branchId, $periods),
            'wallet' => $this->buildWallet($branchId, $start, $end, $periods),
            'credits' => $this->buildCredits($branchId),
            'inventory' => $this->buildInventory($branchId, $start, $end, $periods),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function yearMonthExpr(string $column): string
    {
        return $this->isSqlite()
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function hourExpr(string $column): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }

    private function jsonExtract(string $column, string $path): string
    {
        // SQLite's json_extract returns unquoted values natively; MySQL needs JSON_UNQUOTE
        return $this->isSqlite()
            ? "json_extract({$column}, '{$path}')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }

    /** @return array<int, array{month: string, year: int, key: string, label: string}> */
    private function monthPeriods(Carbon $start, Carbon $end): array
    {
        $periods = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $month = SchoolMonth::fromMonthNumber($cursor->month);
            if ($month !== null) {
                $periods[] = [
                    'month' => $month->value,
                    'year' => (int) $cursor->year,
                    'key' => \sprintf('%04d-%02d', $cursor->year, $cursor->month),
                    'label' => $month->label().' '.$cursor->year,
                ];
            }
            $cursor->addMonth();
        }

        return $periods;
    }

    private function buildPeriodMeta(array $v, array $periods): array
    {
        return [
            'from_month' => $v['from_month'],
            'from_year' => (int) $v['from_year'],
            'to_month' => $v['to_month'],
            'to_year' => (int) $v['to_year'],
            'months' => array_column($periods, 'label'),
        ];
    }

    private function buildSales(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        $kpis = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(total), 0)           AS total_revenue,
                COUNT(*)                           AS total_orders,
                COALESCE(AVG(total), 0)            AS avg_order_value,
                COALESCE(SUM(discount_amount), 0)  AS total_discounts
            ')
            ->first();

        $totalRevenue = round((float) ($kpis->total_revenue ?? 0), 2);
        $totalDiscounts = round((float) ($kpis->total_discounts ?? 0), 2);

        $ym = $this->yearMonthExpr('created_at');

        $trendRaw = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("{$ym} AS month_key, SUM(total) AS revenue, COUNT(*) AS cnt")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        $revenueTrend = array_map(fn ($p) => [
            'label' => $p['label'],
            'revenue' => round((float) ($trendRaw->get($p['key'])?->revenue ?? 0), 2),
            'orders' => (int) ($trendRaw->get($p['key'])?->cnt ?? 0),
        ], $periods);

        $methods = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('payment_method AS method, COUNT(*) AS cnt, SUM(total) AS amount')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->cnt, 'amount' => round((float) $r->amount, 2)])
            ->values()
            ->toArray();

        $topItems = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$start, $end])
            ->selectRaw('order_items.name, SUM(order_items.quantity) AS quantity')
            ->groupBy('order_items.name')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (int) $r->quantity])
            ->toArray();

        $dayCount = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) AS days')
            ->value('days') ?: 1;

        $hr = $this->hourExpr('created_at');

        $hourlyRaw = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->whereRaw("{$hr} BETWEEN 6 AND 12")
            ->selectRaw("{$hr} AS hr, COUNT(*) AS cnt")
            ->groupBy('hr')
            ->pluck('cnt', 'hr')
            ->toArray();

        $hourLabels = [6 => '6am', 7 => '7am', 8 => '8am', 9 => '9am', 10 => '10am', 11 => '11am', 12 => '12pm'];
        $peakHours = array_map(
            fn ($h, $lbl) => ['hour' => $lbl, 'avg_orders' => round((int) ($hourlyRaw[$h] ?? 0) / $dayCount, 1)],
            array_keys($hourLabels),
            $hourLabels,
        );

        return [
            'kpis' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => (int) ($kpis->total_orders ?? 0),
                'avg_order_value' => round((float) ($kpis->avg_order_value ?? 0), 2),
                'total_discounts' => $totalDiscounts,
                'net_revenue' => round($totalRevenue - $totalDiscounts, 2),
            ],
            'revenue_trend' => $revenueTrend,
            'payment_methods' => $methods,
            'top_items' => $topItems,
            'peak_hours' => $peakHours,
        ];
    }

    private function buildStudents(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        $counts = Student::withoutBranch()
            ->where('branch_id', $branchId)
            ->selectRaw(
                "COUNT(*) AS total,
                SUM(CASE WHEN enrollment_status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled,
                SUM(CASE WHEN student_type = 'subscription' THEN 1 ELSE 0 END) AS subscription_count,
                SUM(CASE WHEN student_type = 'non_subscription' THEN 1 ELSE 0 END) AS non_subscription_count,
                SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_enrollments",
                [$start, $end]
            )
            ->first();

        $studentIds = Student::withoutBranch()->where('branch_id', $branchId)->pluck('id');

        $oldType = $this->jsonExtract('attribute_changes', '$.old.student_type');
        $newType = $this->jsonExtract('attribute_changes', '$.attributes.student_type');
        $ym = $this->yearMonthExpr('created_at');

        $switchRows = DB::table('activity_log')
            ->where('subject_type', Student::class)
            ->whereIn('subject_id', $studentIds)
            ->whereRaw("{$oldType} IS NOT NULL")
            ->whereRaw("{$newType} IS NOT NULL")
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                {$ym} AS month_key,
                SUM(CASE WHEN {$oldType} = 'non_subscription' AND {$newType} = 'subscription'   THEN 1 ELSE 0 END) AS upgrades,
                SUM(CASE WHEN {$oldType} = 'subscription'     AND {$newType} = 'non_subscription' THEN 1 ELSE 0 END) AS downgrades
            ")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        $totalUpgrades = $switchRows->sum('upgrades');
        $totalDowngrades = $switchRows->sum('downgrades');

        $switchTrend = array_map(fn ($p) => [
            'label' => $p['label'],
            'upgrades' => (int) ($switchRows->get($p['key'])?->upgrades ?? 0),
            'downgrades' => (int) ($switchRows->get($p['key'])?->downgrades ?? 0),
        ], $periods);

        $byGrade = Student::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('enrollment_status', 'enrolled')
            ->selectRaw('grade_level, COUNT(*) AS enrolled')
            ->groupBy('grade_level')
            ->orderBy('grade_level')
            ->get()
            ->map(fn ($r) => ['grade_level' => $r->grade_level, 'enrolled' => (int) $r->enrolled])
            ->toArray();

        return [
            'kpis' => [
                'total_students' => (int) ($counts->total ?? 0),
                'enrolled' => (int) ($counts->enrolled ?? 0),
                'subscription_count' => (int) ($counts->subscription_count ?? 0),
                'non_subscription_count' => (int) ($counts->non_subscription_count ?? 0),
                'new_enrollments' => (int) ($counts->new_enrollments ?? 0),
                'subscription_upgrades' => (int) $totalUpgrades,
                'subscription_downgrades' => (int) $totalDowngrades,
            ],
            'by_grade' => $byGrade,
            'switch_trend' => $switchTrend,
        ];
    }

    private function buildBilling(int $branchId, array $periods): array
    {
        $studentIds = Student::withoutBranch()->where('branch_id', $branchId)->pluck('id')->toArray();

        $periodConditions = function ($q) use ($periods) {
            foreach ($periods as $p) {
                $q->orWhere(fn ($sub) => $sub->where('school_month', $p['month'])->where('year', $p['year']));
            }
        };

        $base = DB::table('student_monthly_payments')
            ->whereIn('student_id', $studentIds)
            ->where($periodConditions);

        $kpiRaw = (clone $base)
            ->selectRaw("
                SUM(CASE WHEN status = 'paid'   THEN amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) AS unpaid_amount,
                SUM(CASE WHEN status = 'voided' THEN amount ELSE 0 END) AS void_amount,
                COUNT(DISTINCT student_id)                               AS total_subscribers
            ")
            ->first();

        $paidAmount = (float) ($kpiRaw->paid_amount ?? 0);
        $unpaidAmount = (float) ($kpiRaw->unpaid_amount ?? 0);
        $voidAmount = (float) ($kpiRaw->void_amount ?? 0);
        $denominator = $paidAmount + $unpaidAmount;
        $collectionRate = $denominator > 0 ? round($paidAmount / $denominator * 100, 1) : 0.0;

        $discrepancyCount = (clone $base)
            ->where('status', 'unpaid')
            ->distinct()
            ->count('student_id');

        $fullyPaidSubquery = (clone $base)
            ->select('student_id')
            ->groupBy('student_id')
            ->havingRaw("SUM(CASE WHEN status != 'paid' THEN 1 ELSE 0 END) = 0");

        $fullyPaidCount = DB::query()->fromSub($fullyPaidSubquery, 'fully_paid')->count();

        $trendRaw = (clone $base)
            ->selectRaw("
                school_month, year,
                SUM(CASE WHEN status = 'paid'   THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) AS void_count,
                SUM(CASE WHEN status = 'paid'   THEN amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) AS unpaid_amount,
                SUM(CASE WHEN status = 'voided' THEN amount ELSE 0 END) AS void_amount
            ")
            ->groupBy('school_month', 'year')
            ->get()
            ->keyBy(fn ($r) => $r->school_month.'_'.$r->year);

        $monthlyTrend = array_map(function ($p) use ($trendRaw) {
            $key = $p['month'].'_'.$p['year'];
            $r = $trendRaw->get($key);
            $pa = (float) ($r?->paid_amount ?? 0);
            $ua = (float) ($r?->unpaid_amount ?? 0);
            $den = $pa + $ua;

            return [
                'label' => $p['label'],
                'paid_count' => (int) ($r?->paid_count ?? 0),
                'unpaid_count' => (int) ($r?->unpaid_count ?? 0),
                'void_count' => (int) ($r?->void_count ?? 0),
                'paid_amount' => round($pa, 2),
                'unpaid_amount' => round($ua, 2),
                'void_amount' => round((float) ($r?->void_amount ?? 0), 2),
                'collection_rate' => $den > 0 ? round($pa / $den * 100, 1) : 0.0,
            ];
        }, $periods);

        $byGradeRaw = (clone $base)
            ->join('students', 'students.id', '=', 'student_monthly_payments.student_id')
            ->selectRaw("
                students.grade_level,
                SUM(CASE WHEN student_monthly_payments.status = 'paid'   THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN student_monthly_payments.status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid,
                SUM(CASE WHEN student_monthly_payments.status = 'voided' THEN 1 ELSE 0 END) AS void
            ")
            ->groupBy('students.grade_level')
            ->orderBy('students.grade_level')
            ->get()
            ->map(fn ($r) => [
                'grade_level' => $r->grade_level,
                'paid' => (int) $r->paid,
                'unpaid' => (int) $r->unpaid,
                'void' => (int) $r->void,
            ])
            ->toArray();

        return [
            'kpis' => [
                'total_collected' => round($paidAmount, 2),
                'total_outstanding' => round($unpaidAmount, 2),
                'total_void' => round($voidAmount, 2),
                'total_subscribers' => (int) ($kpiRaw->total_subscribers ?? 0),
                'collection_rate' => $collectionRate,
                'discrepancy_count' => $discrepancyCount,
                'fully_paid_count' => $fullyPaidCount,
            ],
            'monthly_trend' => $monthlyTrend,
            'by_grade' => $byGradeRaw,
        ];
    }

    private function buildWallet(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        $studentIds = Student::withoutBranch()->where('branch_id', $branchId)->pluck('id');

        $walletIds = DB::table('wallets')
            ->where('holder_type', Student::class)
            ->whereIn('holder_id', $studentIds)
            ->pluck('id');

        $kpiRaw = DB::table('transactions')
            ->whereIn('wallet_id', $walletIds)
            ->where('confirmed', 1)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                SUM(CASE WHEN type = 'deposit'  THEN ABS(amount) ELSE 0 END) / 100.0 AS total_credits,
                SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS total_debits
            ")
            ->first();

        $totalCredits = round((float) ($kpiRaw->total_credits ?? 0), 2);
        $totalDebits = round((float) ($kpiRaw->total_debits ?? 0), 2);

        $lowBalanceCount = DB::table('wallets')
            ->where('holder_type', Student::class)
            ->whereIn('holder_id', $studentIds)
            ->whereNull('deleted_at')
            ->whereRaw('(balance / 100.0) < 100')
            ->count();

        $ym = $this->yearMonthExpr('created_at');

        $trendRaw = DB::table('transactions')
            ->whereIn('wallet_id', $walletIds)
            ->where('confirmed', 1)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                {$ym} AS month_key,
                SUM(CASE WHEN type = 'deposit'  THEN ABS(amount) ELSE 0 END) / 100.0 AS credits,
                SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS debits
            ")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        $monthlyTrend = array_map(function ($p) use ($trendRaw) {
            $r = $trendRaw->get($p['key']);
            $credits = round((float) ($r?->credits ?? 0), 2);
            $debits = round((float) ($r?->debits ?? 0), 2);

            return ['label' => $p['label'], 'credits' => $credits, 'debits' => $debits, 'net' => round($credits - $debits, 2)];
        }, $periods);

        return [
            'kpis' => [
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'net_flow' => round($totalCredits - $totalDebits, 2),
                'low_balance_count' => $lowBalanceCount,
            ],
            'monthly_trend' => $monthlyTrend,
        ];
    }

    private function buildCredits(int $branchId): array
    {
        $base = Student::withoutBranch()->where('branch_id', $branchId)->where('credit_balance', '>', 0);

        $studentsOnCredit = (clone $base)->count();
        $totalCreditBalance = round((float) (clone $base)->sum('credit_balance'), 2);
        $avgCredit = $studentsOnCredit > 0 ? round($totalCreditBalance / $studentsOnCredit, 2) : 0.0;
        $creditLimit = (float) config('sunbites.credit_limit', 300);
        $nearLimitCount = (clone $base)->where('credit_balance', '>=', $creditLimit - 50)->count();

        $distribution = [
            ['range' => '₱1–₱100',   'count' => (clone $base)->where('credit_balance', '<=', 100)->count()],
            ['range' => '₱101–₱200', 'count' => (clone $base)->whereBetween('credit_balance', [100.01, 200])->count()],
            ['range' => '₱201–₱300', 'count' => (clone $base)->where('credit_balance', '>', 200)->count()],
        ];

        return [
            'kpis' => [
                'total_credit_balance' => $totalCreditBalance,
                'students_on_credit' => $studentsOnCredit,
                'avg_credit_per_student' => $avgCredit,
                'near_limit_count' => $nearLimitCount,
            ],
            'distribution' => $distribution,
        ];
    }

    private function buildInventory(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        $items = DB::table('inventory_items')->where('branch_id', $branchId)->where('is_archived', 0);

        $totalItems = (clone $items)->count();
        $lowStockCount = (clone $items)->whereRaw('quantity > 0 AND quantity <= restock_threshold')->count();
        $outOfStockCount = (clone $items)->where('quantity', '<=', 0)->count();

        $mostRestocked = DB::table('inventory_logs')
            ->where('branch_id', $branchId)
            ->where('type', 'restock')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('item_name_snapshot, COUNT(*) AS restock_count')
            ->groupBy('item_name_snapshot')
            ->orderByDesc('restock_count')
            ->first();

        $topConsumed = DB::table('inventory_logs')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_logs.inventory_item_id')
            ->where('inventory_logs.branch_id', $branchId)
            ->where('inventory_logs.type', 'sale')
            ->whereBetween('inventory_logs.created_at', [$start, $end])
            ->selectRaw('inventory_items.name, inventory_items.unit, SUM(ABS(inventory_logs.quantity_change)) AS quantity')
            ->groupBy('inventory_items.id', 'inventory_items.name', 'inventory_items.unit')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->quantity, 2), 'unit' => $r->unit])
            ->toArray();

        $ym = $this->yearMonthExpr('inventory_logs.created_at');

        $lowEventsRaw = DB::table('inventory_logs')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_logs.inventory_item_id')
            ->where('inventory_logs.branch_id', $branchId)
            ->whereBetween('inventory_logs.created_at', [$start, $end])
            ->whereRaw('inventory_logs.stock_after > 0 AND inventory_logs.stock_after <= inventory_items.restock_threshold')
            ->selectRaw("{$ym} AS month_key, COUNT(DISTINCT inventory_logs.inventory_item_id) AS low_events")
            ->groupBy('month_key')
            ->pluck('low_events', 'month_key')
            ->toArray();

        $outEventsRaw = DB::table('inventory_logs')
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where('stock_after', '<=', 0)
            ->selectRaw("{$ym} AS month_key, COUNT(DISTINCT inventory_item_id) AS out_events")
            ->groupBy('month_key')
            ->pluck('out_events', 'month_key')
            ->toArray();

        $stockEvents = array_map(fn ($p) => [
            'label' => $p['label'],
            'low_events' => (int) ($lowEventsRaw[$p['key']] ?? 0),
            'out_events' => (int) ($outEventsRaw[$p['key']] ?? 0),
        ], $periods);

        return [
            'kpis' => [
                'total_items' => $totalItems,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'most_restocked_item' => $mostRestocked?->item_name_snapshot,
                'most_restocked_count' => (int) ($mostRestocked?->restock_count ?? 0),
            ],
            'top_consumed' => $topConsumed,
            'stock_events' => $stockEvents,
        ];
    }
}
