# Analytics Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/reports/analytics` page to the POS app backed by a new `GET /api/v1/reports/analytics` endpoint, giving admin/manager users a scrollable, chart-rich overview of sales, students, billing, wallet, credits, and inventory across a school-month date range.

**Architecture:** A single Laravel controller (`AnalyticsController`) computes all six data sections in one request and returns them as a unified JSON payload. The frontend uses one TanStack Query hook to fetch the payload; all six section components read from the same cached result. Charts use Recharts.

**Tech Stack:** Laravel 13, PHPUnit 12, Next.js 16, React 19, TanStack Query v5, Recharts, Tailwind v4, Zod 4, TypeScript strict.

## Global Constraints

- All PHP runs through `vendor/bin/sail`; format with `vendor/bin/sail bin pint --dirty --format agent` after every PHP change.
- All `student_monthly_payments.status` values are `'paid'`, `'unpaid'`, `'voided'` — never `'void'`.
- Wallet amounts in `transactions.amount` are integer cents; apply `ABS(amount) / 100.0` before dividing.
- `orders.status` filter: `'completed'` (OrderStatus::Completed enum value).
- `inventory_logs.quantity_change` is negative for sales; use `ABS(quantity_change)` when summing consumption.
- The `SchoolMonth` enum values are lowercase: `june`, `july`, …, `march`.
- Recharts must be installed (`npm install recharts`) before writing any chart code.
- Route goes under the existing `role:admin|manager` middleware group inside `Route::prefix('reports')`.
- Tests live in `tests/Feature/Reports/AnalyticsReportTest.php`; use `LazilyRefreshDatabase`.
- Frontend tests live co-located with components (`*.test.tsx`) or in the analytics page directory.

---

### Task 1: Backend — Controller scaffold, route, and auth tests

**Files:**
- Create: `app/Http/Controllers/Kitchen/AnalyticsController.php`
- Modify: `routes/kitchen-api.php` (add one route in the existing admin|manager reports group)
- Create: `tests/Feature/Reports/AnalyticsReportTest.php`

**Interfaces:**
- Produces: `GET /api/v1/reports/analytics?from_month=june&from_year=2025&to_month=july&to_year=2025` → 200 JSON with keys `period, sales, students, billing, wallet, credits, inventory`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);
        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function defaultParams(): array
    {
        return [
            'from_month' => 'june',
            'from_year'  => 2025,
            'to_month'   => 'july',
            'to_year'    => 2025,
        ];
    }

    public function test_manager_can_fetch_analytics(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

        $response->assertOk();
        $response->assertJsonStructure([
            'period'    => ['from_month', 'from_year', 'to_month', 'to_year', 'months'],
            'sales'     => ['kpis', 'revenue_trend', 'payment_methods', 'top_items', 'peak_hours'],
            'students'  => ['kpis', 'by_grade', 'switch_trend'],
            'billing'   => ['kpis', 'monthly_trend', 'by_grade'],
            'wallet'    => ['kpis', 'monthly_trend'],
            'credits'   => ['kpis', 'distribution'],
            'inventory' => ['kpis', 'top_consumed', 'stock_events'],
        ]);
        $this->assertSame(['June 2025', 'July 2025'], $response->json('period.months'));
    }

    public function test_supervisor_cannot_access_analytics(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

        $response->assertForbidden();
    }

    public function test_missing_from_month_returns_422(): void
    {
        $params = $this->defaultParams();
        unset($params['from_month']);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($params));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from_month']);
    }

    public function test_invalid_school_month_returns_422(): void
    {
        $params = array_merge($this->defaultParams(), ['from_month' => 'april']);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($params));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from_month']);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: FAIL — route not found (404).

- [ ] **Step 3: Create the controller with stub sections**

```php
<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from_month' => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'from_year'  => ['required', 'integer', 'min:2020', 'max:2099'],
            'to_month'   => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'to_year'    => ['required', 'integer', 'min:2020', 'max:2099'],
        ]);

        $branchId = app('active_branch')->id;
        $start = Carbon::create($v['from_year'], SchoolMonth::from($v['from_month'])->toMonthNumber(), 1)->startOfMonth();
        $end   = Carbon::create($v['to_year'],   SchoolMonth::from($v['to_month'])->toMonthNumber(), 1)->endOfMonth();
        $periods = $this->monthPeriods($start, $end);

        return response()->json([
            'period'    => $this->buildPeriodMeta($v, $periods),
            'sales'     => $this->buildSales($branchId, $start, $end, $periods),
            'students'  => $this->buildStudents($branchId, $start, $end, $periods),
            'billing'   => $this->buildBilling($branchId, $periods),
            'wallet'    => $this->buildWallet($branchId, $start, $end, $periods),
            'credits'   => $this->buildCredits($branchId),
            'inventory' => $this->buildInventory($branchId, $start, $end, $periods),
        ]);
    }

    /** @return array<int, array{month: string, year: int, label: string}> */
    private function monthPeriods(Carbon $start, Carbon $end): array
    {
        $periods = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $month = SchoolMonth::fromMonthNumber($cursor->month);
            if ($month !== null) {
                $periods[] = ['month' => $month->value, 'year' => (int) $cursor->year, 'label' => $month->label().' '.$cursor->year];
            }
            $cursor->addMonth();
        }

        return $periods;
    }

    private function buildPeriodMeta(array $v, array $periods): array
    {
        return [
            'from_month' => $v['from_month'],
            'from_year'  => (int) $v['from_year'],
            'to_month'   => $v['to_month'],
            'to_year'    => (int) $v['to_year'],
            'months'     => array_column($periods, 'label'),
        ];
    }

    private function buildSales(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        return ['kpis' => [], 'revenue_trend' => [], 'payment_methods' => [], 'top_items' => [], 'peak_hours' => []];
    }

    private function buildStudents(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        return ['kpis' => [], 'by_grade' => [], 'switch_trend' => []];
    }

    private function buildBilling(int $branchId, array $periods): array
    {
        return ['kpis' => [], 'monthly_trend' => [], 'by_grade' => []];
    }

    private function buildWallet(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        return ['kpis' => [], 'monthly_trend' => []];
    }

    private function buildCredits(int $branchId): array
    {
        return ['kpis' => [], 'distribution' => []];
    }

    private function buildInventory(int $branchId, Carbon $start, Carbon $end, array $periods): array
    {
        return ['kpis' => [], 'top_consumed' => [], 'stock_events' => []];
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/kitchen-api.php`, inside the existing `Route::middleware('role:admin|manager')->group` block within `Route::prefix('reports')`:

```php
// add after the existing Route::get('/credits', ...) line:
Route::get('/analytics', [AnalyticsController::class, 'index']);
```

Also add the use statement at the top of the file:
```php
use App\Http\Controllers\Kitchen\AnalyticsController;
```

- [ ] **Step 5: Run tests — verify they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: All 4 tests PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php routes/kitchen-api.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: scaffold AnalyticsController with route and auth tests"
```

---

### Task 2: Backend — Sales section

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AnalyticsController.php` (`buildSales` method)
- Modify: `tests/Feature/Reports/AnalyticsReportTest.php` (add sales tests)

- [ ] **Step 1: Add failing sales tests**

Append to `AnalyticsReportTest`:

```php
public function test_sales_kpis_reflect_completed_orders_in_range(): void
{
    // Create completed order in range
    $order = Order::factory()->for($this->branch)->create([
        'status'          => 'completed',
        'total'           => 100.00,
        'discount_amount' => 10.00,
        'created_at'      => '2025-06-15 10:00:00',
    ]);
    OrderItem::factory()->for($order)->create(['name' => 'Rice Bowl', 'quantity' => 2]);

    // Create completed order outside range — must not appear
    Order::factory()->for($this->branch)->create([
        'status'          => 'completed',
        'total'           => 500.00,
        'discount_amount' => 0,
        'created_at'      => '2025-08-01 10:00:00',
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $response->assertOk();
    $this->assertSame(100.00, $response->json('sales.kpis.total_revenue'));
    $this->assertSame(1,      $response->json('sales.kpis.total_orders'));
    $this->assertSame(10.00,  $response->json('sales.kpis.total_discounts'));
    $this->assertSame(90.00,  $response->json('sales.kpis.net_revenue'));
}

public function test_sales_revenue_trend_has_entry_per_period(): void
{
    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $trend = $response->json('sales.revenue_trend');
    $this->assertCount(2, $trend);
    $this->assertSame('June 2025', $trend[0]['label']);
    $this->assertSame('July 2025', $trend[1]['label']);
}

public function test_sales_top_items_sorted_by_quantity(): void
{
    $order = Order::factory()->for($this->branch)->create(['status' => 'completed', 'created_at' => '2025-06-10 10:00:00']);
    OrderItem::factory()->for($order)->create(['name' => 'Chicken', 'quantity' => 5]);
    OrderItem::factory()->for($order)->create(['name' => 'Rice',    'quantity' => 10]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $items = $response->json('sales.top_items');
    $this->assertSame('Rice', $items[0]['name']);
    $this->assertSame(10, $items[0]['quantity']);
}
```

Add use statements at the top of the test file:
```php
use App\Models\Order;
use App\Models\OrderItem;
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_sales tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: FAIL — kpis array is empty.

- [ ] **Step 3: Implement `buildSales()`**

Replace the stub `buildSales` method in `AnalyticsController`:

```php
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

    $totalRevenue   = round((float) ($kpis->total_revenue ?? 0), 2);
    $totalDiscounts = round((float) ($kpis->total_discounts ?? 0), 2);
    $totalOrders    = (int) ($kpis->total_orders ?? 0);

    // Revenue trend — one entry per period label
    $trendRaw = DB::table('orders')
        ->where('branch_id', $branchId)
        ->where('status', 'completed')
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month_key, SUM(total) AS revenue, COUNT(*) AS orders")
        ->groupBy('month_key')
        ->pluck('revenue', 'month_key')
        ->toArray();

    $ordersRaw = DB::table('orders')
        ->where('branch_id', $branchId)
        ->where('status', 'completed')
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS cnt")
        ->groupBy('month_key')
        ->pluck('cnt', 'month_key')
        ->toArray();

    $revenueTrend = array_map(function ($p) use ($trendRaw, $ordersRaw) {
        $key = sprintf('%04d-%02d', $p['year'], SchoolMonth::from($p['month'])->toMonthNumber());

        return [
            'label'   => $p['label'],
            'revenue' => round((float) ($trendRaw[$key] ?? 0), 2),
            'orders'  => (int) ($ordersRaw[$key] ?? 0),
        ];
    }, $periods);

    // Payment method breakdown
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

    // Top 10 items
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

    // Peak hours (6am–12pm window, avg per day)
    $dayCount = DB::table('orders')
        ->where('branch_id', $branchId)
        ->where('status', 'completed')
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw('COUNT(DISTINCT DATE(created_at)) AS days')
        ->value('days') ?: 1;

    $hourlyRaw = DB::table('orders')
        ->where('branch_id', $branchId)
        ->where('status', 'completed')
        ->whereBetween('created_at', [$start, $end])
        ->whereRaw('HOUR(created_at) BETWEEN 6 AND 12')
        ->selectRaw('HOUR(created_at) AS hr, COUNT(*) AS cnt')
        ->groupBy('hr')
        ->pluck('cnt', 'hr')
        ->toArray();

    $hourLabels = [6 => '6am', 7 => '7am', 8 => '8am', 9 => '9am', 10 => '10am', 11 => '11am', 12 => '12pm'];
    $peakHours  = array_map(fn ($h, $lbl) => ['hour' => $lbl, 'avg_orders' => round((int) ($hourlyRaw[$h] ?? 0) / $dayCount, 1)], array_keys($hourLabels), $hourLabels);

    return [
        'kpis' => [
            'total_revenue'   => $totalRevenue,
            'total_orders'    => $totalOrders,
            'avg_order_value' => round((float) ($kpis->avg_order_value ?? 0), 2),
            'total_discounts' => $totalDiscounts,
            'net_revenue'     => round($totalRevenue - $totalDiscounts, 2),
        ],
        'revenue_trend'   => $revenueTrend,
        'payment_methods' => $methods,
        'top_items'       => $topItems,
        'peak_hours'      => $peakHours,
    ];
}
```

Add `use Illuminate\Support\Facades\DB;` to the controller imports.

- [ ] **Step 4: Run tests — verify pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: All PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: implement sales section in AnalyticsController"
```

---

### Task 3: Backend — Students section

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AnalyticsController.php` (`buildStudents`)
- Modify: `tests/Feature/Reports/AnalyticsReportTest.php`

- [ ] **Step 1: Add failing students tests**

```php
public function test_students_kpis_count_branch_students(): void
{
    Student::factory()->subscription()->enrolled()->for($this->branch)->count(3)->create();
    Student::factory()->nonSubscription()->enrolled()->for($this->branch)->count(2)->create();

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $response->assertOk();
    $this->assertSame(5, $response->json('students.kpis.total_students'));
    $this->assertSame(3, $response->json('students.kpis.subscription_count'));
    $this->assertSame(2, $response->json('students.kpis.non_subscription_count'));
}

public function test_students_new_enrollments_counts_created_in_range(): void
{
    Student::factory()->for($this->branch)->create(['created_at' => '2025-06-15']);
    Student::factory()->for($this->branch)->create(['created_at' => '2025-08-01']); // outside range

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $this->assertSame(1, $response->json('students.kpis.new_enrollments'));
}

public function test_students_switch_trend_counts_upgrades_and_downgrades(): void
{
    $student = Student::factory()->for($this->branch)->create();

    // Simulate an upgrade activity log entry
    DB::table('activity_log')->insert([
        'subject_type'      => Student::class,
        'subject_id'        => $student->id,
        'description'       => 'students.updated',
        'attribute_changes' => json_encode([
            'attributes' => ['student_type' => 'subscription'],
            'old'        => ['student_type' => 'non_subscription'],
        ]),
        'created_at' => '2025-06-20',
        'updated_at' => '2025-06-20',
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $trend = $response->json('students.switch_trend');
    $june  = collect($trend)->firstWhere('label', 'June 2025');
    $this->assertSame(1, $june['upgrades']);
    $this->assertSame(0, $june['downgrades']);
}
```

Add `use App\Models\Student;` to test file imports.

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_students tests/Feature/Reports/AnalyticsReportTest.php
```

- [ ] **Step 3: Implement `buildStudents()`**

```php
private function buildStudents(int $branchId, Carbon $start, Carbon $end, array $periods): array
{
    $base = Student::withoutBranch()->where('branch_id', $branchId);

    $total            = (clone $base)->count();
    $enrolled         = (clone $base)->where('enrollment_status', 'enrolled')->count();
    $subscriptionCount    = (clone $base)->where('student_type', 'subscription')->count();
    $nonSubscriptionCount = (clone $base)->where('student_type', 'non_subscription')->count();
    $newEnrollments   = (clone $base)->whereBetween('created_at', [$start, $end])->count();

    // Subscription type changes from activity_log
    $studentIds = (clone $base)->pluck('id');

    $switchRows = DB::table('activity_log')
        ->where('subject_type', Student::class)
        ->whereIn('subject_id', $studentIds)
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.old.student_type')) IS NOT NULL")
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.attributes.student_type')) IS NOT NULL")
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw("
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            SUM(CASE WHEN
                JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.old.student_type')) = 'non_subscription'
                AND JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.attributes.student_type')) = 'subscription'
            THEN 1 ELSE 0 END) AS upgrades,
            SUM(CASE WHEN
                JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.old.student_type')) = 'subscription'
                AND JSON_UNQUOTE(JSON_EXTRACT(attribute_changes, '$.attributes.student_type')) = 'non_subscription'
            THEN 1 ELSE 0 END) AS downgrades
        ")
        ->groupBy('month_key')
        ->get()
        ->keyBy('month_key');

    $totalUpgrades   = $switchRows->sum('upgrades');
    $totalDowngrades = $switchRows->sum('downgrades');

    $switchTrend = array_map(function ($p) use ($switchRows) {
        $key = sprintf('%04d-%02d', $p['year'], SchoolMonth::from($p['month'])->toMonthNumber());
        $row = $switchRows->get($key);

        return ['label' => $p['label'], 'upgrades' => (int) ($row?->upgrades ?? 0), 'downgrades' => (int) ($row?->downgrades ?? 0)];
    }, $periods);

    // By grade (enrolled students)
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
            'total_students'           => $total,
            'enrolled'                 => $enrolled,
            'subscription_count'       => $subscriptionCount,
            'non_subscription_count'   => $nonSubscriptionCount,
            'new_enrollments'          => $newEnrollments,
            'subscription_upgrades'    => (int) $totalUpgrades,
            'subscription_downgrades'  => (int) $totalDowngrades,
        ],
        'by_grade'     => $byGrade,
        'switch_trend' => $switchTrend,
    ];
}
```

Add `use App\Models\Student;` to controller imports.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: All PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: implement students section in AnalyticsController"
```

---

### Task 4: Backend — Billing section

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AnalyticsController.php` (`buildBilling`)
- Modify: `tests/Feature/Reports/AnalyticsReportTest.php`

- [ ] **Step 1: Add failing billing tests**

```php
public function test_billing_monthly_trend_splits_paid_unpaid_void(): void
{
    $student = Student::factory()->subscription()->for($this->branch)->create();

    StudentMonthlyPayment::factory()->for($student)->paid()->create([
        'school_month' => 'june',
        'year'         => 2025,
        'amount'       => 2970,
    ]);
    StudentMonthlyPayment::factory()->for($student)->unpaid()->create([
        'school_month' => 'june',
        'year'         => 2025,
        'amount'       => 2970,
    ]);
    StudentMonthlyPayment::factory()->for($student)->state(['status' => 'voided'])->create([
        'school_month' => 'june',
        'year'         => 2025,
        'amount'       => 2970,
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $trend = $response->json('billing.monthly_trend');
    $june  = collect($trend)->firstWhere('label', 'June 2025');

    $this->assertSame(1,       $june['paid_count']);
    $this->assertSame(1,       $june['unpaid_count']);
    $this->assertSame(1,       $june['void_count']);
    $this->assertSame(2970.00, $june['paid_amount']);
    $this->assertSame(2970.00, $june['unpaid_amount']);
    $this->assertSame(2970.00, $june['void_amount']);
}

public function test_billing_collection_rate_excludes_voided_payments(): void
{
    $student = Student::factory()->subscription()->for($this->branch)->create();

    StudentMonthlyPayment::factory()->for($student)->paid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);
    StudentMonthlyPayment::factory()->for($student)->unpaid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);
    // voided — excluded from rate calculation
    StudentMonthlyPayment::factory()->for($student)->state(['status' => 'voided'])->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    // paid/(paid+unpaid)*100 = 1000/2000*100 = 50.0
    $this->assertSame(50.0, $response->json('billing.kpis.collection_rate'));
}

public function test_billing_by_grade_counts_payment_records(): void
{
    $student = Student::factory()->subscription()->for($this->branch)->create(['grade_level' => 'Grade 1']);

    StudentMonthlyPayment::factory()->for($student)->paid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 2970]);
    StudentMonthlyPayment::factory()->for($student)->paid()->create(['school_month' => 'july', 'year' => 2025, 'amount' => 2970]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $gradeRow = collect($response->json('billing.by_grade'))->firstWhere('grade_level', 'Grade 1');
    $this->assertSame(2, $gradeRow['paid']);
}
```

Add `use App\Models\StudentMonthlyPayment;` to test file imports.

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_billing tests/Feature/Reports/AnalyticsReportTest.php
```

- [ ] **Step 3: Implement `buildBilling()`**

```php
private function buildBilling(int $branchId, array $periods): array
{
    $studentIds = Student::withoutBranch()->where('branch_id', $branchId)->pluck('id');

    $paymentsQuery = function () use ($studentIds, $periods) {
        return StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where(function ($q) use ($periods) {
                foreach ($periods as $p) {
                    $q->orWhere(fn ($inner) => $inner->where('school_month', $p['month'])->where('year', $p['year']));
                }
            });
    };

    $kpiRaw = $paymentsQuery()
        ->selectRaw("
            SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) AS paid_amount,
            SUM(CASE WHEN status = 'unpaid'  THEN amount ELSE 0 END) AS unpaid_amount,
            SUM(CASE WHEN status = 'voided'  THEN amount ELSE 0 END) AS void_amount,
            COUNT(DISTINCT student_id)                                AS total_subscribers,
            SUM(CASE WHEN status != 'voided' THEN 1 ELSE 0 END)      AS active_records
        ")
        ->first();

    $paidAmount    = (float) ($kpiRaw->paid_amount ?? 0);
    $unpaidAmount  = (float) ($kpiRaw->unpaid_amount ?? 0);
    $voidAmount    = (float) ($kpiRaw->void_amount ?? 0);
    $denominator   = $paidAmount + $unpaidAmount;
    $collectionRate = $denominator > 0 ? round($paidAmount / $denominator * 100, 1) : 0.0;

    $discrepancyCount = $paymentsQuery()->where('status', 'unpaid')->distinct('student_id')->count('student_id');

    $fullyPaidCount = $paymentsQuery()
        ->select('student_id')
        ->groupBy('student_id')
        ->havingRaw("SUM(status != 'paid') = 0")
        ->get()
        ->count();

    // Monthly trend
    $trendRaw = $paymentsQuery()
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
        $r   = $trendRaw->get($key);
        $pa  = (float) ($r?->paid_amount ?? 0);
        $ua  = (float) ($r?->unpaid_amount ?? 0);
        $den = $pa + $ua;

        return [
            'label'           => $p['label'],
            'paid_count'      => (int) ($r?->paid_count ?? 0),
            'unpaid_count'    => (int) ($r?->unpaid_count ?? 0),
            'void_count'      => (int) ($r?->void_count ?? 0),
            'paid_amount'     => round($pa, 2),
            'unpaid_amount'   => round($ua, 2),
            'void_amount'     => round((float) ($r?->void_amount ?? 0), 2),
            'collection_rate' => $den > 0 ? round($pa / $den * 100, 1) : 0.0,
        ];
    }, $periods);

    // By grade
    $byGradeRaw = $paymentsQuery()
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
            'paid'        => (int) $r->paid,
            'unpaid'      => (int) $r->unpaid,
            'void'        => (int) $r->void,
        ])
        ->toArray();

    return [
        'kpis' => [
            'total_collected'    => round($paidAmount, 2),
            'total_outstanding'  => round($unpaidAmount, 2),
            'total_void'         => round($voidAmount, 2),
            'total_subscribers'  => (int) ($kpiRaw->total_subscribers ?? 0),
            'collection_rate'    => $collectionRate,
            'discrepancy_count'  => $discrepancyCount,
            'fully_paid_count'   => $fullyPaidCount,
        ],
        'monthly_trend' => $monthlyTrend,
        'by_grade'      => $byGradeRaw,
    ];
}
```

Add `use App\Models\StudentMonthlyPayment;` to controller imports.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: All PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: implement billing section in AnalyticsController"
```

---

### Task 5: Backend — Wallet and Credits sections

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AnalyticsController.php`
- Modify: `tests/Feature/Reports/AnalyticsReportTest.php`

- [ ] **Step 1: Add failing wallet and credits tests**

```php
public function test_wallet_kpis_sum_branch_student_transactions(): void
{
    $student = Student::factory()->for($this->branch)->create();
    $student->deposit(5000); // +₱50.00

    $wallet = $student->wallet;

    // Manually insert a withdraw transaction in-range
    DB::table('transactions')->insert([
        'payable_type' => Student::class,
        'payable_id'   => $student->id,
        'wallet_id'    => $wallet->id,
        'type'         => 'withdraw',
        'amount'       => -3000, // -₱30.00
        'confirmed'    => 1,
        'uuid'         => \Illuminate\Support\Str::uuid(),
        'created_at'   => '2025-06-15',
        'updated_at'   => '2025-06-15',
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    // Deposits in range; ₱50 credit, ₱30 debit
    $wallet  = $response->json('wallet.kpis');
    $this->assertEqualsWithDelta(50.0, $wallet['total_credits'], 0.01);
    $this->assertEqualsWithDelta(30.0, $wallet['total_debits'],  0.01);
    $this->assertEqualsWithDelta(20.0, $wallet['net_flow'],      0.01);
}

public function test_credits_kpis_reflect_live_credit_balances(): void
{
    Student::factory()->for($this->branch)->create(['credit_balance' => 150.00]);
    Student::factory()->for($this->branch)->create(['credit_balance' => 0.00]); // excluded

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $credits = $response->json('credits.kpis');
    $this->assertSame(1,      $credits['students_on_credit']);
    $this->assertSame(150.00, $credits['total_credit_balance']);
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact --filter="test_wallet|test_credits" tests/Feature/Reports/AnalyticsReportTest.php
```

- [ ] **Step 3: Implement `buildWallet()` and `buildCredits()`**

```php
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
    $totalDebits  = round((float) ($kpiRaw->total_debits ?? 0), 2);

    $lowBalanceCount = DB::table('wallets')
        ->where('holder_type', Student::class)
        ->whereIn('holder_id', $studentIds)
        ->whereNull('deleted_at')
        ->whereRaw('(balance / 100.0) < 100')
        ->count();

    // Monthly trend
    $trendRaw = DB::table('transactions')
        ->whereIn('wallet_id', $walletIds)
        ->where('confirmed', 1)
        ->whereNull('deleted_at')
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw("
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            SUM(CASE WHEN type = 'deposit'  THEN ABS(amount) ELSE 0 END) / 100.0 AS credits,
            SUM(CASE WHEN type = 'withdraw' THEN ABS(amount) ELSE 0 END) / 100.0 AS debits
        ")
        ->groupBy('month_key')
        ->get()
        ->keyBy('month_key');

    $monthlyTrend = array_map(function ($p) use ($trendRaw) {
        $key     = sprintf('%04d-%02d', $p['year'], SchoolMonth::from($p['month'])->toMonthNumber());
        $r       = $trendRaw->get($key);
        $credits = round((float) ($r?->credits ?? 0), 2);
        $debits  = round((float) ($r?->debits ?? 0), 2);

        return ['label' => $p['label'], 'credits' => $credits, 'debits' => $debits, 'net' => round($credits - $debits, 2)];
    }, $periods);

    return [
        'kpis' => [
            'total_credits'     => $totalCredits,
            'total_debits'      => $totalDebits,
            'net_flow'          => round($totalCredits - $totalDebits, 2),
            'low_balance_count' => $lowBalanceCount,
        ],
        'monthly_trend' => $monthlyTrend,
    ];
}

private function buildCredits(int $branchId): array
{
    $base = Student::withoutBranch()->where('branch_id', $branchId)->where('credit_balance', '>', 0);

    $studentsOnCredit    = (clone $base)->count();
    $totalCreditBalance  = round((float) (clone $base)->sum('credit_balance'), 2);
    $avgCredit           = $studentsOnCredit > 0 ? round($totalCreditBalance / $studentsOnCredit, 2) : 0.0;
    $creditLimit         = (float) config('sunbites.credit_limit', 300);
    $nearLimitCount      = (clone $base)->where('credit_balance', '>=', $creditLimit - 50)->count();

    $distribution = [
        ['range' => '₱1–₱100',   'count' => (clone $base)->where('credit_balance', '<=', 100)->count()],
        ['range' => '₱101–₱200', 'count' => (clone $base)->whereBetween('credit_balance', [100.01, 200])->count()],
        ['range' => '₱201–₱300', 'count' => (clone $base)->where('credit_balance', '>', 200)->count()],
    ];

    return [
        'kpis' => [
            'total_credit_balance'   => $totalCreditBalance,
            'students_on_credit'     => $studentsOnCredit,
            'avg_credit_per_student' => $avgCredit,
            'near_limit_count'       => $nearLimitCount,
        ],
        'distribution' => $distribution,
    ];
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: implement wallet and credits sections in AnalyticsController"
```

---

### Task 6: Backend — Inventory section + branch scope test

**Files:**
- Modify: `app/Http/Controllers/Kitchen/AnalyticsController.php`
- Modify: `tests/Feature/Reports/AnalyticsReportTest.php`

- [ ] **Step 1: Add failing inventory and branch-scope tests**

```php
public function test_inventory_top_consumed_sums_sale_logs(): void
{
    $item = InventoryItem::factory()->for($this->branch)->create(['name' => 'Rice', 'unit' => 'kg']);
    $staff = User::factory()->create();

    DB::table('inventory_logs')->insert([
        ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'sale', 'quantity_change' => -5, 'stock_after' => 95, 'reason' => 'sale', 'item_name_snapshot' => 'Rice', 'created_at' => '2025-06-10'],
        ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'sale', 'quantity_change' => -3, 'stock_after' => 92, 'reason' => 'sale', 'item_name_snapshot' => 'Rice', 'created_at' => '2025-07-05'],
        // restock — must not be counted as consumption
        ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'restock', 'quantity_change' => 20, 'stock_after' => 112, 'reason' => 'restock', 'item_name_snapshot' => 'Rice', 'created_at' => '2025-06-01'],
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $top = $response->json('inventory.top_consumed');
    $this->assertSame('Rice', $top[0]['name']);
    $this->assertSame(8.0,   (float) $top[0]['quantity']);
    $this->assertSame('kg',  $top[0]['unit']);
}

public function test_branch_scoping_excludes_other_branch_data(): void
{
    $otherBranch = Branch::factory()->create(['is_active' => true]);
    Order::factory()->for($otherBranch)->create(['status' => 'completed', 'total' => 9999, 'created_at' => '2025-06-10']);

    $response = $this->asManager()->getJson('/api/v1/reports/analytics?' . http_build_query($this->defaultParams()));

    $this->assertSame(0,    $response->json('sales.kpis.total_orders'));
    $this->assertSame(0.00, $response->json('sales.kpis.total_revenue'));
}
```

Add `use App\Models\InventoryItem;` and `use App\Models\Branch;` to test file imports.

- [ ] **Step 2: Run to confirm failure**

```bash
vendor/bin/sail artisan test --compact --filter="test_inventory|test_branch" tests/Feature/Reports/AnalyticsReportTest.php
```

- [ ] **Step 3: Implement `buildInventory()`**

```php
private function buildInventory(int $branchId, Carbon $start, Carbon $end, array $periods): array
{
    $items = DB::table('inventory_items')
        ->where('branch_id', $branchId)
        ->where('is_archived', 0);

    $totalItems     = (clone $items)->count();
    $lowStockCount  = (clone $items)->whereRaw('quantity > 0 AND quantity <= restock_threshold')->count();
    $outOfStockCount = (clone $items)->where('quantity', '<=', 0)->count();

    // Most restocked item in range
    $mostRestocked = DB::table('inventory_logs')
        ->where('branch_id', $branchId)
        ->where('type', 'restock')
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw('item_name_snapshot, COUNT(*) AS restock_count')
        ->groupBy('item_name_snapshot')
        ->orderByDesc('restock_count')
        ->first();

    // Top 10 consumed items
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

    // Stock events per month
    $lowEventsRaw = DB::table('inventory_logs')
        ->join('inventory_items', 'inventory_items.id', '=', 'inventory_logs.inventory_item_id')
        ->where('inventory_logs.branch_id', $branchId)
        ->whereBetween('inventory_logs.created_at', [$start, $end])
        ->whereRaw('inventory_logs.stock_after > 0 AND inventory_logs.stock_after <= inventory_items.restock_threshold')
        ->selectRaw("DATE_FORMAT(inventory_logs.created_at, '%Y-%m') AS month_key, COUNT(DISTINCT inventory_logs.inventory_item_id) AS low_events")
        ->groupBy('month_key')
        ->pluck('low_events', 'month_key')
        ->toArray();

    $outEventsRaw = DB::table('inventory_logs')
        ->where('branch_id', $branchId)
        ->whereBetween('created_at', [$start, $end])
        ->where('stock_after', '<=', 0)
        ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(DISTINCT inventory_item_id) AS out_events")
        ->groupBy('month_key')
        ->pluck('out_events', 'month_key')
        ->toArray();

    $stockEvents = array_map(function ($p) use ($lowEventsRaw, $outEventsRaw) {
        $key = sprintf('%04d-%02d', $p['year'], SchoolMonth::from($p['month'])->toMonthNumber());

        return ['label' => $p['label'], 'low_events' => (int) ($lowEventsRaw[$key] ?? 0), 'out_events' => (int) ($outEventsRaw[$key] ?? 0)];
    }, $periods);

    return [
        'kpis' => [
            'total_items'          => $totalItems,
            'low_stock_count'      => $lowStockCount,
            'out_of_stock_count'   => $outOfStockCount,
            'most_restocked_item'  => $mostRestocked?->item_name_snapshot,
            'most_restocked_count' => (int) ($mostRestocked?->restock_count ?? 0),
        ],
        'top_consumed' => $topConsumed,
        'stock_events' => $stockEvents,
    ];
}
```

Add `use App\Models\InventoryItem;` to controller (not needed directly — DB queries use table names, but keep for clarity if referenced elsewhere).

- [ ] **Step 4: Run full test suite for the file**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/AnalyticsReportTest.php
```
Expected: All PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/AnalyticsController.php tests/Feature/Reports/AnalyticsReportTest.php
git commit -m "feat: implement inventory section and branch scope in AnalyticsController"
```

---

### Task 7: Frontend — recharts + types + API service + hook

**Files:**
- Create: `~/sunbites-pos/types/analytics.ts`
- Create: `~/sunbites-pos/lib/api/analytics.ts`
- Create: `~/sunbites-pos/hooks/use-analytics.ts`

- [ ] **Step 1: Install recharts**

```bash
cd ~/sunbites-pos && npm install recharts
```

- [ ] **Step 2: Create `types/analytics.ts`**

```typescript
export type SchoolMonthValue = 'june' | 'july' | 'august' | 'september' | 'october' | 'november' | 'december' | 'january' | 'february' | 'march';

export interface AnalyticsParams {
  from_month: SchoolMonthValue;
  from_year: number;
  to_month: SchoolMonthValue;
  to_year: number;
}

export interface AnalyticsPeriod {
  from_month: SchoolMonthValue;
  from_year: number;
  to_month: SchoolMonthValue;
  to_year: number;
  months: string[];
}

export interface SalesKpis {
  total_revenue: number;
  total_orders: number;
  avg_order_value: number;
  total_discounts: number;
  net_revenue: number;
}

export interface RevenueTrendEntry { label: string; revenue: number; orders: number; }
export interface PaymentMethodEntry { method: string; count: number; amount: number; }
export interface TopItemEntry { name: string; quantity: number; }
export interface PeakHourEntry { hour: string; avg_orders: number; }

export interface SalesSection {
  kpis: SalesKpis;
  revenue_trend: RevenueTrendEntry[];
  payment_methods: PaymentMethodEntry[];
  top_items: TopItemEntry[];
  peak_hours: PeakHourEntry[];
}

export interface StudentsKpis {
  total_students: number;
  enrolled: number;
  subscription_count: number;
  non_subscription_count: number;
  new_enrollments: number;
  subscription_upgrades: number;
  subscription_downgrades: number;
}

export interface GradeEnrollmentEntry { grade_level: string; enrolled: number; }
export interface SwitchTrendEntry { label: string; upgrades: number; downgrades: number; }

export interface StudentsSection {
  kpis: StudentsKpis;
  by_grade: GradeEnrollmentEntry[];
  switch_trend: SwitchTrendEntry[];
}

export interface BillingKpis {
  total_collected: number;
  total_outstanding: number;
  total_void: number;
  total_subscribers: number;
  collection_rate: number;
  discrepancy_count: number;
  fully_paid_count: number;
}

export interface BillingMonthEntry {
  label: string;
  paid_count: number;
  unpaid_count: number;
  void_count: number;
  paid_amount: number;
  unpaid_amount: number;
  void_amount: number;
  collection_rate: number;
}

export interface BillingGradeEntry { grade_level: string; paid: number; unpaid: number; void: number; }

export interface BillingSection {
  kpis: BillingKpis;
  monthly_trend: BillingMonthEntry[];
  by_grade: BillingGradeEntry[];
}

export interface WalletKpis { total_credits: number; total_debits: number; net_flow: number; low_balance_count: number; }
export interface WalletTrendEntry { label: string; credits: number; debits: number; net: number; }
export interface WalletSection { kpis: WalletKpis; monthly_trend: WalletTrendEntry[]; }

export interface CreditsKpis { total_credit_balance: number; students_on_credit: number; avg_credit_per_student: number; near_limit_count: number; }
export interface CreditDistributionEntry { range: string; count: number; }
export interface CreditsSection { kpis: CreditsKpis; distribution: CreditDistributionEntry[]; }

export interface InventoryKpis { total_items: number; low_stock_count: number; out_of_stock_count: number; most_restocked_item: string | null; most_restocked_count: number; }
export interface TopConsumedEntry { name: string; quantity: number; unit: string; }
export interface StockEventEntry { label: string; low_events: number; out_events: number; }
export interface InventorySection { kpis: InventoryKpis; top_consumed: TopConsumedEntry[]; stock_events: StockEventEntry[]; }

export interface AnalyticsData {
  period: AnalyticsPeriod;
  sales: SalesSection;
  students: StudentsSection;
  billing: BillingSection;
  wallet: WalletSection;
  credits: CreditsSection;
  inventory: InventorySection;
}
```

- [ ] **Step 3: Create `lib/api/analytics.ts`**

```typescript
import { apiClient } from "./client";

import type { AnalyticsData, AnalyticsParams } from "@/types/analytics";

export const analyticsApi = {
  index: (params: AnalyticsParams) =>
    apiClient.get<AnalyticsData>("/reports/analytics", { params: params as unknown as Record<string, string | number | boolean | undefined> }),
};
```

- [ ] **Step 4: Create `hooks/use-analytics.ts`**

```typescript
import { useQuery } from "@tanstack/react-query";

import { analyticsApi } from "@/lib/api/analytics";

import type { AnalyticsParams } from "@/types/analytics";

export function useAnalytics(params: AnalyticsParams) {
  return useQuery({
    queryKey: ["analytics", params],
    queryFn: () => analyticsApi.index(params),
    staleTime: 5 * 60 * 1000,
  });
}
```

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-pos
git add types/analytics.ts lib/api/analytics.ts hooks/use-analytics.ts package.json package-lock.json
git commit -m "feat: add recharts, analytics types, API service, and query hook"
```

---

### Task 8: Frontend — Shared components + navigation update

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/kpi-card.tsx`
- Create: `~/sunbites-pos/components/reports/analytics/section-wrapper.tsx`
- Modify: `~/sunbites-pos/lib/navigation.ts`

- [ ] **Step 1: Create `kpi-card.tsx`**

```typescript
import { cn } from "@/lib/utils";

interface Props {
  label: string;
  value: string;
  className?: string;
}

export function KpiCard({ label, value, className }: Props) {
  return (
    <div className={cn("rounded-lg border border-border bg-card p-4", className)}>
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">{value}</p>
    </div>
  );
}
```

- [ ] **Step 2: Create `section-wrapper.tsx`**

```typescript
import { cn } from "@/lib/utils";

interface Props {
  title: string;
  children: React.ReactNode;
  className?: string;
}

export function SectionWrapper({ title, children, className }: Props) {
  return (
    <section className={cn("space-y-6", className)}>
      <div className="flex items-center gap-3">
        <h2 className="text-lg font-semibold text-foreground">{title}</h2>
        <div className="h-px flex-1 bg-border" />
      </div>
      {children}
    </section>
  );
}
```

- [ ] **Step 3: Add Analytics to `lib/navigation.ts`**

In `lib/navigation.ts`, add `BarChart2` (already imported) and update `reportsNav` to prepend Analytics:

```typescript
// Add import for LineChart icon (or reuse BarChart2 with a different name)
import { TrendingUp } from "lucide-react";
```

Then prepend to `reportsNav`:
```typescript
export const reportsNav: NavItem[] = [
  { label: "Analytics", href: "/reports/analytics", icon: TrendingUp },
  { label: "Sales", href: "/reports/sales", icon: BarChart2 },
  // ... rest unchanged
];
```

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/kpi-card.tsx components/reports/analytics/section-wrapper.tsx lib/navigation.ts
git commit -m "feat: add KpiCard, SectionWrapper components and Analytics nav entry"
```

---

### Task 9: Frontend — Analytics filter bar

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/analytics-filter-bar.tsx`

- [ ] **Step 1: Create the filter bar**

```typescript
"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";

import type { AnalyticsParams, SchoolMonthValue } from "@/types/analytics";

const SCHOOL_MONTHS: { value: SchoolMonthValue; label: string }[] = [
  { value: "june",      label: "June" },
  { value: "july",      label: "July" },
  { value: "august",    label: "August" },
  { value: "september", label: "September" },
  { value: "october",   label: "October" },
  { value: "november",  label: "November" },
  { value: "december",  label: "December" },
  { value: "january",   label: "January" },
  { value: "february",  label: "February" },
  { value: "march",     label: "March" },
];

type Preset = "this_year" | "last_year" | "custom";

function currentSchoolYear(): { fromMonth: SchoolMonthValue; fromYear: number; toMonth: SchoolMonthValue; toYear: number } {
  const now = new Date();
  const month = now.getMonth() + 1; // 1-indexed
  const year  = now.getFullYear();
  const fromYear = month >= 6 ? year : year - 1;
  const toYear   = fromYear + 1;
  const currentSchoolMonth = SCHOOL_MONTHS.find(m => {
    const idx = SCHOOL_MONTHS.findIndex(sm => sm.value === m.value);
    return idx >= 0;
  });
  // Cap to_month at march or current month (whichever is earlier in the school year)
  const capMonth: SchoolMonthValue = month <= 3 && year === toYear ? (SCHOOL_MONTHS[month - 1 + 6]?.value ?? "march") : "march";

  return { fromMonth: "june", fromYear, toMonth: capMonth, toYear };
}

interface Props {
  onApply: (params: AnalyticsParams) => void;
  className?: string;
}

export function AnalyticsFilterBar({ onApply, className }: Props) {
  const [preset, setPreset]       = useState<Preset>("this_year");
  const [fromMonth, setFromMonth] = useState<SchoolMonthValue>("june");
  const [fromYear,  setFromYear]  = useState(new Date().getFullYear());
  const [toMonth,   setToMonth]   = useState<SchoolMonthValue>("march");
  const [toYear,    setToYear]    = useState(new Date().getFullYear() + 1);

  // Derive initial from preset
  useEffect(() => {
    const sy = currentSchoolYear();
    setFromMonth(sy.fromMonth);
    setFromYear(sy.fromYear);
    setToMonth(sy.toMonth);
    setToYear(sy.toYear);
    onApply({ from_month: sy.fromMonth, from_year: sy.fromYear, to_month: sy.toMonth, to_year: sy.toYear });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handlePreset(p: Preset) {
    setPreset(p);
    if (p === "this_year") {
      const sy = currentSchoolYear();
      setFromMonth(sy.fromMonth); setFromYear(sy.fromYear);
      setToMonth(sy.toMonth);     setToYear(sy.toYear);
    } else if (p === "last_year") {
      const sy = currentSchoolYear();
      setFromMonth("june"); setFromYear(sy.fromYear - 1);
      setToMonth("march");  setToYear(sy.fromYear);
    }
  }

  function handleApply() {
    onApply({ from_month: fromMonth, from_year: fromYear, to_month: toMonth, to_year: toYear });
  }

  const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - 2 + i);
  const isCustom = preset === "custom";

  return (
    <div className={cn("sticky top-0 z-10 border-b border-border bg-background/95 px-4 py-3 backdrop-blur", className)}>
      <div className="flex flex-wrap items-center gap-3">
        <span className="text-sm font-medium text-muted-foreground">Period:</span>

        {(["this_year", "last_year", "custom"] as Preset[]).map((p) => (
          <button
            key={p}
            onClick={() => handlePreset(p)}
            className={cn(
              "rounded-full px-3 py-1 text-xs font-medium transition-colors",
              preset === p
                ? "bg-primary text-primary-foreground"
                : "bg-muted text-muted-foreground hover:bg-muted/80",
            )}
          >
            {p === "this_year" ? "This School Year" : p === "last_year" ? "Last School Year" : "Custom"}
          </button>
        ))}

        {isCustom && (
          <>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground">From</span>
              <Select value={fromMonth} onValueChange={(v) => setFromMonth(v as SchoolMonthValue)}>
                <SelectTrigger className="h-8 w-32 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SCHOOL_MONTHS.map(m => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}
                </SelectContent>
              </Select>
              <Select value={String(fromYear)} onValueChange={(v) => setFromYear(Number(v))}>
                <SelectTrigger className="h-8 w-24 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {years.map(y => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground">To</span>
              <Select value={toMonth} onValueChange={(v) => setToMonth(v as SchoolMonthValue)}>
                <SelectTrigger className="h-8 w-32 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SCHOOL_MONTHS.map(m => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}
                </SelectContent>
              </Select>
              <Select value={String(toYear)} onValueChange={(v) => setToYear(Number(v))}>
                <SelectTrigger className="h-8 w-24 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {years.map(y => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>

            <Button size="sm" onClick={handleApply} className="h-8">Apply</Button>
          </>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/analytics-filter-bar.tsx
git commit -m "feat: add AnalyticsFilterBar with school-year presets and custom range"
```

---

### Task 10: Frontend — Section Sales

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/section-sales.tsx`

- [ ] **Step 1: Create `section-sales.tsx`**

```typescript
"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { SalesSection } from "@/types/analytics";

const COLORS = {
  revenue: "#0A7160",
  orders:  "#2F5FA8",
  wallet:  "#0A7160",
  cash:    "#2F5FA8",
  other:   "#94A3B8",
};

function formatPeso(n: number) {
  return `₱${n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

interface Props { data: SalesSection; }

export function SectionSales({ data }: Props) {
  const { kpis, revenue_trend, payment_methods, top_items, peak_hours } = data;

  const maxOrders = Math.max(...peak_hours.map(h => h.avg_orders), 1);
  const peakHour  = peak_hours.reduce((a, b) => a.avg_orders > b.avg_orders ? a : b, peak_hours[0]);

  const methodColors: Record<string, string> = { wallet: COLORS.wallet, cash: COLORS.cash };

  return (
    <SectionWrapper title="Sales & Revenue">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <KpiCard label="Total Revenue"   value={formatPeso(kpis.total_revenue)} />
        <KpiCard label="Total Orders"    value={kpis.total_orders.toLocaleString()} />
        <KpiCard label="Avg Order Value" value={formatPeso(kpis.avg_order_value)} />
        <KpiCard label="Total Discounts" value={formatPeso(kpis.total_discounts)} />
        <KpiCard label="Net Revenue"     value={formatPeso(kpis.net_revenue)} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Revenue & Orders trend — dual Y-axis */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Monthly Revenue &amp; Orders</p>
          <ResponsiveContainer width="100%" height={220}>
            <ComposedChart data={revenue_trend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="left"  tickFormatter={(v) => `₱${(v/1000).toFixed(0)}k`} tick={{ fontSize: 11 }} />
              <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
              <Tooltip formatter={(v, name) => name === "revenue" ? formatPeso(Number(v)) : v} />
              <Legend />
              <Area yAxisId="left"  type="monotone" dataKey="revenue" fill={COLORS.revenue} stroke={COLORS.revenue} fillOpacity={0.2} name="Revenue" />
              <Bar  yAxisId="right" dataKey="orders"  fill={COLORS.orders}  name="Orders" />
            </ComposedChart>
          </ResponsiveContainer>
        </div>

        {/* Payment method donut */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Payment Methods</p>
          <ResponsiveContainer width="100%" height={220}>
            <PieChart>
              <Pie data={payment_methods} dataKey="count" nameKey="method" innerRadius={60} outerRadius={90} label={({ method, percent }) => `${method} ${(percent * 100).toFixed(0)}%`}>
                {payment_methods.map((entry, i) => (
                  <Cell key={entry.method} fill={methodColors[entry.method] ?? COLORS.other} />
                ))}
              </Pie>
              <Tooltip formatter={(v, name) => [v, name]} />
            </PieChart>
          </ResponsiveContainer>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Top 10 items */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Top 10 Selling Items</p>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={top_items} layout="vertical">
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis type="number" tick={{ fontSize: 11 }} />
              <YAxis type="category" dataKey="name" width={100} tick={{ fontSize: 11 }} />
              <Tooltip />
              <Bar dataKey="quantity" fill={COLORS.revenue} name="Qty" />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* Peak ordering hours */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Peak Ordering Hours (avg/day)</p>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={peak_hours}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="hour" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip />
              <Bar dataKey="avg_orders" name="Avg Orders">
                {peak_hours.map((entry) => (
                  <Cell key={entry.hour} fill={entry.hour === peakHour?.hour ? "#C84B12" : COLORS.revenue} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/section-sales.tsx
git commit -m "feat: add SectionSales with revenue trend, payment donut, top items, peak hours"
```

---

### Task 11: Frontend — Section Students

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/section-students.tsx`

- [ ] **Step 1: Create `section-students.tsx`**

```typescript
"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { StudentsSection } from "@/types/analytics";

interface Props { data: StudentsSection; }

export function SectionStudents({ data }: Props) {
  const { kpis, by_grade, switch_trend } = data;

  const typeData = [
    { name: "Subscription",     value: kpis.subscription_count },
    { name: "Non-Subscription", value: kpis.non_subscription_count },
  ];

  const divergingTrend = switch_trend.map(e => ({
    ...e,
    downgrades: -e.downgrades,
  }));

  return (
    <SectionWrapper title="Students & Enrollment">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        <KpiCard label="Total Students"  value={kpis.total_students.toLocaleString()} />
        <KpiCard label="Enrolled"         value={kpis.enrolled.toLocaleString()} />
        <KpiCard label="Subscription"     value={kpis.subscription_count.toLocaleString()} />
        <KpiCard label="Non-Subscription" value={kpis.non_subscription_count.toLocaleString()} />
        <KpiCard label="New Enrollments"  value={kpis.new_enrollments.toLocaleString()} />
        <KpiCard label="Upgrades"         value={kpis.subscription_upgrades.toLocaleString()} />
        <KpiCard label="Downgrades"       value={kpis.subscription_downgrades.toLocaleString()} />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-2">
          <p className="text-sm font-medium">Student Type Split</p>
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie data={typeData} dataKey="value" nameKey="name" innerRadius={55} outerRadius={80} label={({ name, percent }) => `${(percent * 100).toFixed(0)}%`}>
                <Cell fill="#0A7160" />
                <Cell fill="#C84B12" />
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium">Enrolled by Grade Level</p>
          <ResponsiveContainer width="100%" height={200}>
            <BarChart data={by_grade} layout="vertical">
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis type="number" tick={{ fontSize: 10 }} />
              <YAxis type="category" dataKey="grade_level" width={70} tick={{ fontSize: 10 }} />
              <Tooltip />
              <Bar dataKey="enrolled" fill="#0A7160" name="Enrolled" />
            </BarChart>
          </ResponsiveContainer>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium">Subscription Switches</p>
          <ResponsiveContainer width="100%" height={200}>
            <BarChart data={divergingTrend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} />
              <ReferenceLine y={0} stroke="hsl(var(--border))" />
              <Tooltip formatter={(v, name) => [Math.abs(Number(v)), name === "downgrades" ? "Downgrades" : "Upgrades"]} />
              <Bar dataKey="upgrades"   fill="#0A7160" name="Upgrades" />
              <Bar dataKey="downgrades" fill="#C84B12" name="Downgrades" />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/section-students.tsx
git commit -m "feat: add SectionStudents with type donut, grade bar, switch trend"
```

---

### Task 12: Frontend — Section Billing

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/section-billing.tsx`

- [ ] **Step 1: Create `section-billing.tsx`**

```typescript
"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { BillingSection } from "@/types/analytics";

function formatPeso(n: number) {
  return `₱${n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

interface Props { data: BillingSection; }

export function SectionBilling({ data }: Props) {
  const { kpis, monthly_trend, by_grade } = data;

  return (
    <SectionWrapper title="Subscription & Billing">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <KpiCard label="Total Collected"   value={formatPeso(kpis.total_collected)} />
        <KpiCard label="Total Outstanding" value={formatPeso(kpis.total_outstanding)} />
        <KpiCard label="Total Subscribers" value={kpis.total_subscribers.toLocaleString()} />
        <KpiCard label="Discrepancies"     value={kpis.discrepancy_count.toLocaleString()} />
        <KpiCard label="Fully Paid"        value={kpis.fully_paid_count.toLocaleString()} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Stacked paid/unpaid/void per month */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Paid / Unpaid / Void per School Month</p>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={monthly_trend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} />
              <Tooltip />
              <Legend />
              <Bar dataKey="paid_count"   stackId="billing" fill="#0A7160" name="Paid" />
              <Bar dataKey="unpaid_count" stackId="billing" fill="#C84B12" name="Unpaid" />
              <Bar dataKey="void_count"   stackId="billing" fill="#94A3B8" name="Void" />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* Collection rate trend */}
        <div className="space-y-2">
          <p className="text-sm font-medium">Collection Rate Trend</p>
          <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={monthly_trend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} unit="%" />
              <Tooltip formatter={(v) => [`${v}%`, "Collection Rate"]} />
              <ReferenceLine y={95} stroke="#C84B12" strokeDasharray="4 2" label={{ value: "95% target", position: "right", fontSize: 10 }} />
              <Area type="monotone" dataKey="collection_rate" stroke="#0A7160" fill="#0A7160" fillOpacity={0.15} name="Rate" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* By grade grouped bar */}
      <div className="space-y-2">
        <p className="text-sm font-medium">Paid / Unpaid / Void per Grade Level</p>
        <ResponsiveContainer width="100%" height={220}>
          <BarChart data={by_grade}>
            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
            <XAxis dataKey="grade_level" tick={{ fontSize: 10 }} />
            <YAxis tick={{ fontSize: 10 }} />
            <Tooltip />
            <Legend />
            <Bar dataKey="paid"   fill="#0A7160" name="Paid" />
            <Bar dataKey="unpaid" fill="#C84B12" name="Unpaid" />
            <Bar dataKey="void"   fill="#94A3B8" name="Void" />
          </BarChart>
        </ResponsiveContainer>
      </div>

      {/* Monthly breakdown table */}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <th className="pb-2 pr-4">Month</th>
              <th className="pb-2 pr-4 text-right">Paid</th>
              <th className="pb-2 pr-4 text-right">Unpaid</th>
              <th className="pb-2 pr-4 text-right">Void</th>
              <th className="pb-2 pr-4 text-right">Collected</th>
              <th className="pb-2 pr-4 text-right">Outstanding</th>
              <th className="pb-2 text-right">Rate</th>
            </tr>
          </thead>
          <tbody>
            {monthly_trend.map((row) => (
              <tr key={row.label} className="border-b border-border/50 last:border-0">
                <td className="py-2 pr-4 font-medium">{row.label}</td>
                <td className="py-2 pr-4 text-right tabular-nums">{row.paid_count}</td>
                <td className="py-2 pr-4 text-right tabular-nums">{row.unpaid_count}</td>
                <td className="py-2 pr-4 text-right tabular-nums text-muted-foreground">{row.void_count}</td>
                <td className="py-2 pr-4 text-right tabular-nums">{formatPeso(row.paid_amount)}</td>
                <td className="py-2 pr-4 text-right tabular-nums text-destructive">{formatPeso(row.unpaid_amount)}</td>
                <td className="py-2 text-right">
                  <div className="flex items-center justify-end gap-2">
                    <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                      <div className="h-full bg-primary" style={{ width: `${Math.min(row.collection_rate, 100)}%` }} />
                    </div>
                    <span className="tabular-nums text-xs">{row.collection_rate.toFixed(1)}%</span>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/section-billing.tsx
git commit -m "feat: add SectionBilling with stacked bars, collection rate, grade breakdown, table"
```

---

### Task 13: Frontend — Section Wallet and Section Credits

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/section-wallet.tsx`
- Create: `~/sunbites-pos/components/reports/analytics/section-credits.tsx`

- [ ] **Step 1: Create `section-wallet.tsx`**

```typescript
"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { WalletSection } from "@/types/analytics";

function formatPeso(n: number) {
  return `₱${n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

interface Props { data: WalletSection; }

export function SectionWallet({ data }: Props) {
  const { kpis, monthly_trend } = data;

  return (
    <SectionWrapper title="Wallet Activity">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <KpiCard label="Total Credits"    value={formatPeso(kpis.total_credits)} />
        <KpiCard label="Total Debits"     value={formatPeso(kpis.total_debits)} />
        <KpiCard label="Net Flow"         value={formatPeso(kpis.net_flow)} />
        <KpiCard label="Low Balance (<₱100)" value={kpis.low_balance_count.toLocaleString()} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-2">
          <p className="text-sm font-medium">Credits vs Debits per Month</p>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={monthly_trend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} tickFormatter={(v) => `₱${(v/1000).toFixed(0)}k`} />
              <Tooltip formatter={(v) => formatPeso(Number(v))} />
              <Legend />
              <Bar dataKey="credits" stackId="wallet" fill="#7140CC" name="Credits" />
              <Bar dataKey="debits"  stackId="wallet" fill="#C84B12" name="Debits" />
            </BarChart>
          </ResponsiveContainer>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium">Net Wallet Flow</p>
          <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={monthly_trend}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} tickFormatter={(v) => `₱${(v/1000).toFixed(0)}k`} />
              <Tooltip formatter={(v) => [formatPeso(Number(v)), "Net Flow"]} />
              <Area type="monotone" dataKey="net" stroke="#7140CC" fill="#7140CC" fillOpacity={0.2} name="Net" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 2: Create `section-credits.tsx`**

```typescript
"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { CreditsSection } from "@/types/analytics";

function formatPeso(n: number) {
  return `₱${n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

interface Props { data: CreditsSection; }

export function SectionCredits({ data }: Props) {
  const { kpis, distribution } = data;

  return (
    <SectionWrapper title="Credits & Debt">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <KpiCard label="Total Credit Balance"  value={formatPeso(kpis.total_credit_balance)} />
        <KpiCard label="Students on Credit"    value={kpis.students_on_credit.toLocaleString()} />
        <KpiCard label="Avg Credit / Student"  value={formatPeso(kpis.avg_credit_per_student)} />
        <KpiCard label="Near Limit"            value={kpis.near_limit_count.toLocaleString()} />
      </div>

      <div className="max-w-sm space-y-2">
        <p className="text-sm font-medium">Credit Balance Distribution</p>
        <ResponsiveContainer width="100%" height={180}>
          <BarChart data={distribution}>
            <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
            <XAxis dataKey="range" tick={{ fontSize: 11 }} />
            <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
            <Tooltip />
            <Bar dataKey="count" fill="#C84B12" name="Students" />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/section-wallet.tsx components/reports/analytics/section-credits.tsx
git commit -m "feat: add SectionWallet and SectionCredits components"
```

---

### Task 14: Frontend — Section Inventory

**Files:**
- Create: `~/sunbites-pos/components/reports/analytics/section-inventory.tsx`

- [ ] **Step 1: Create `section-inventory.tsx`**

```typescript
"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { KpiCard } from "./kpi-card";
import { SectionWrapper } from "./section-wrapper";

import type { InventorySection } from "@/types/analytics";

interface Props { data: InventorySection; }

export function SectionInventory({ data }: Props) {
  const { kpis, top_consumed, stock_events } = data;

  return (
    <SectionWrapper title="Inventory">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <KpiCard label="Items Tracked"   value={kpis.total_items.toLocaleString()} />
        <KpiCard label="Currently Low"   value={kpis.low_stock_count.toLocaleString()} />
        <KpiCard label="Out of Stock"    value={kpis.out_of_stock_count.toLocaleString()} />
        <KpiCard
          label="Most Restocked"
          value={kpis.most_restocked_item ?? "—"}
          className="col-span-2 sm:col-span-1"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-2">
          <p className="text-sm font-medium">Top 10 Consumed Ingredients</p>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={top_consumed} layout="vertical">
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis type="number" tick={{ fontSize: 11 }} />
              <YAxis type="category" dataKey="name" width={100} tick={{ fontSize: 11 }} />
              <Tooltip formatter={(v, _, props) => [`${v} ${props.payload?.unit ?? ""}`, "Consumed"]} />
              <Bar dataKey="quantity" fill="#0E7490" name="Consumed" />
            </BarChart>
          </ResponsiveContainer>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium">Low &amp; Out-of-Stock Events per Month</p>
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={stock_events}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 10 }} />
              <Tooltip />
              <Legend />
              <Bar dataKey="low_events" stackId="stock" fill="#0E7490" name="Low Stock" />
              <Bar dataKey="out_events" stackId="stock" fill="#C84B12" name="Out of Stock" />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </SectionWrapper>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd ~/sunbites-pos
git add components/reports/analytics/section-inventory.tsx
git commit -m "feat: add SectionInventory with top consumed and stock events charts"
```

---

### Task 15: Frontend — Page assembly + loading skeleton + nav test

**Files:**
- Create: `~/sunbites-pos/app/(kitchen)/reports/analytics/page.tsx`
- Create: `~/sunbites-pos/app/(kitchen)/reports/analytics/loading.tsx`
- Modify: `~/sunbites-pos/components/navigation/app-nav-sheet.test.tsx` (add analytics role-gate test)

- [ ] **Step 1: Create `page.tsx`**

```typescript
"use client";

import { useState } from "react";

import { AlertCircle } from "lucide-react";

import { AnalyticsFilterBar } from "@/components/reports/analytics/analytics-filter-bar";
import { SectionBilling } from "@/components/reports/analytics/section-billing";
import { SectionCredits } from "@/components/reports/analytics/section-credits";
import { SectionInventory } from "@/components/reports/analytics/section-inventory";
import { SectionSales } from "@/components/reports/analytics/section-sales";
import { SectionStudents } from "@/components/reports/analytics/section-students";
import { SectionWallet } from "@/components/reports/analytics/section-wallet";
import { useAnalytics } from "@/hooks/use-analytics";
import { useAuthStore } from "@/lib/store/auth";

import type { AnalyticsParams, SchoolMonthValue } from "@/types/analytics";

function defaultParams(): AnalyticsParams {
  const now      = new Date();
  const month    = now.getMonth() + 1;
  const year     = now.getFullYear();
  const fromYear = month >= 6 ? year : year - 1;
  const toYear   = fromYear + 1;

  const schoolMonthMap: Record<number, SchoolMonthValue> = {
    1: "january", 2: "february", 3: "march",
    6: "june", 7: "july", 8: "august", 9: "september",
    10: "october", 11: "november", 12: "december",
  };

  const toMonth: SchoolMonthValue = (month >= 6 ? schoolMonthMap[month] : "march") ?? "march";

  return {
    from_month: "june",
    from_year:  fromYear,
    to_month:   toMonth,
    to_year:    month >= 6 ? year : toYear,
  };
}

export default function AnalyticsPage() {
  const [params, setParams] = useState<AnalyticsParams>(defaultParams);
  const { data, isLoading, isError } = useAnalytics(params);

  const roles  = useAuthStore((s) => s.user?.roles ?? []);
  const canView = roles.includes("admin") || roles.includes("manager");

  if (!canView) {
    return (
      <div className="flex h-64 items-center justify-center">
        <p className="text-muted-foreground">You don't have permission to view this page.</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-0">
      <AnalyticsFilterBar onApply={setParams} />

      <div className="space-y-10 p-4 pb-16">
        {isLoading && (
          <div className="space-y-10">
            {[...Array(6)].map((_, i) => (
              <div key={i} className="h-64 animate-pulse rounded-lg bg-muted" />
            ))}
          </div>
        )}

        {isError && (
          <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 shrink-0" />
            Failed to load analytics. Please try again.
          </div>
        )}

        {data && (
          <>
            <SectionSales     data={data.sales} />
            <SectionStudents  data={data.students} />
            <SectionBilling   data={data.billing} />
            <SectionWallet    data={data.wallet} />
            <SectionCredits   data={data.credits} />
            <SectionInventory data={data.inventory} />
          </>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Create `loading.tsx`**

```typescript
import { Skeleton } from "@/components/ui/skeleton";

export default function AnalyticsLoading() {
  return (
    <div className="space-y-10 p-4">
      <Skeleton className="h-12 w-full" />
      {[...Array(6)].map((_, i) => (
        <div key={i} className="space-y-4">
          <Skeleton className="h-6 w-48" />
          <div className="grid grid-cols-4 gap-3">
            {[...Array(4)].map((_, j) => <Skeleton key={j} className="h-20" />)}
          </div>
          <Skeleton className="h-56 w-full" />
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 3: Add role-gate test to `app-nav-sheet.test.tsx`**

Append these two tests to the `describe("AppNavSheet")` block in the existing file:

```typescript
it("shows Analytics nav item for admin", () => {
  mockUseAuthStore.mockImplementation((sel: (s: AuthState) => unknown) =>
    sel(makeAuthState(["admin"])),
  );
  render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
  expect(screen.getByText("Analytics")).toBeInTheDocument();
});

it("hides Analytics nav item for supervisor", () => {
  mockUseAuthStore.mockImplementation((sel: (s: AuthState) => unknown) =>
    sel(makeAuthState(["supervisor"])),
  );
  render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
  expect(screen.queryByText("Analytics")).not.toBeInTheDocument();
});
```

- [ ] **Step 4: Run frontend nav tests**

```bash
cd ~/sunbites-pos
npx jest --testPathPattern="app-nav-sheet" --no-coverage
```
Expected: All PASS (including the two new tests).

- [ ] **Step 5: Run backend full suite (confirm no regressions)**

```bash
cd ~/sunbites-api
vendor/bin/sail artisan test --compact
```
Expected: All PASS.

- [ ] **Step 6: Commit all page files**

```bash
cd ~/sunbites-pos
git add app/\(kitchen\)/reports/analytics/page.tsx app/\(kitchen\)/reports/analytics/loading.tsx components/navigation/app-nav-sheet.test.tsx
git commit -m "feat: assemble analytics page with filter bar, all sections, loading skeleton, and nav role-gate tests"
```

---

## Post-Implementation Checklist

- [ ] Run `vendor/bin/sail artisan test --compact` — full backend suite green
- [ ] Run `cd ~/sunbites-pos && npx jest --no-coverage` — frontend suite green
- [ ] Run `vendor/bin/sail bin pint --dirty --format agent` — no formatting issues
- [ ] Verify `GET /api/v1/reports/analytics` is listed in `vendor/bin/sail artisan route:list --path=api/v1/reports/analytics`
- [ ] Invoke `/superpowers:verification-before-completion` to validate implementation against spec
- [ ] Run `laravel-simplifier` agent on `AnalyticsController.php` to review for simplifications
- [ ] Update `task.md` — mark analytics page task as complete
