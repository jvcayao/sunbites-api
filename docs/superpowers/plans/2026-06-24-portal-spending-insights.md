# Portal Spending Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Spending Insights section to the parent portal dashboard showing per-student monthly spending charts, top items, payment method split, and subscription payment history.

**Architecture:** One new Laravel controller aggregates spending data per student. Seven focused React components in `app/(portal)/dashboard/_components/` compose the insights card. The dashboard passes its already-fetched `students[]` down — no extra list call. Subscription payment history reuses the existing endpoint.

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit 12, Next.js (React 19), TanStack Query, Recharts, Tailwind v4, shadcn/ui, Lucide React, Jest 30 + RTL + MSW 2.

## Global Constraints

- All Laravel commands run via `vendor/bin/sail artisan` — never bare `php artisan`
- Format PHP after every change: `vendor/bin/sail bin pint --dirty --format agent`
- Run only the affected test file during development; run full suite before final commit
- No `any` in TypeScript — use explicit types or `unknown`
- No default exports for React components — named exports only
- `"use client"` only when the component needs hooks, browser APIs, or event handlers
- Recharts installed in `~/sunbites-portal` — never in `~/sunbites-api`
- All API calls go through `lib/api/portal.ts` — never raw `fetch` in components
- TanStack Query key for spending summary: `["spending-summary", studentId]`

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `app/Http/Controllers/Portal/SpendingSummaryController.php` | Create | Aggregate monthly totals, top items, payment split, YTD |
| `routes/portal-api.php` | Modify | Register `GET students/{student}/spending-summary` |
| `tests/Feature/Portal/SpendingSummaryControllerTest.php` | Create | PHPUnit tests for the new endpoint |
| `~/sunbites-portal/package.json` | Modify | Add recharts dependency |
| `~/sunbites-portal/types/portal.ts` | Modify | Add `SpendingSummary`, `MonthlySpending`, `TopItem`, `PaymentHistoryEntry` |
| `~/sunbites-portal/lib/api/portal.ts` | Modify | Add `spendingSummary()` to `studentsApi` |
| `app/(portal)/dashboard/_components/student-switcher.tsx` | Create | Color-coded pill tabs per student |
| `app/(portal)/dashboard/_components/spending-stat-cells.tsx` | Create | This Month / YTD / Top Item stat row |
| `app/(portal)/dashboard/_components/monthly-trend-chart.tsx` | Create | Recharts 6-month bar chart |
| `app/(portal)/dashboard/_components/top-items-list.tsx` | Create | Ranked top-5 items with fill bars |
| `app/(portal)/dashboard/_components/payment-method-split.tsx` | Create | Wallet / Cash / Plan / Credit horizontal bars |
| `app/(portal)/dashboard/_components/payment-history-timeline.tsx` | Create | Subscription-only 5-month payment cards |
| `app/(portal)/dashboard/_components/spending-insights.tsx` | Create | Orchestrator — owns active student state, fetches summary |
| `app/(portal)/dashboard/page.tsx` | Modify | Import and render `<SpendingInsights students={data.students} />` |
| `__tests__/mocks/handlers.ts` | Modify | Add MSW handlers for spending-summary and payment-history |

---

### Task 1: Backend — `SpendingSummaryController` (TDD)

**Files:**
- Create: `app/Http/Controllers/Portal/SpendingSummaryController.php`
- Modify: `routes/portal-api.php`
- Create: `tests/Feature/Portal/SpendingSummaryControllerTest.php`

**Interfaces:**
- Produces: `GET /api/v1/portal/students/{student}/spending-summary` → `SpendingSummaryResponse`

---

- [ ] **Step 1: Create the test file**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan make:test --phpunit Portal/SpendingSummaryControllerTest
```

- [ ] **Step 2: Write all tests**

Replace the generated file at `tests/Feature/Portal/SpendingSummaryControllerTest.php`:

```php
<?php

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpendingSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    private ParentUser $parent;
    private Student $student;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch  = Branch::factory()->create();
        $this->parent  = ParentUser::factory()->create();
        $this->student = Student::factory()->for($this->branch)->create();
        $this->parent->students()->attach($this->student->id);
    }

    private function get(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->parent, 'parents')
            ->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson("/api/v1/portal/students/{$this->student->id}/spending-summary?" . http_build_query($params));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson("/api/v1/portal/students/{$this->student->id}/spending-summary")
            ->assertUnauthorized();
    }

    public function test_non_linked_student_returns_403(): void
    {
        $other = Student::factory()->for($this->branch)->create();

        $this->actingAs($this->parent, 'parents')
            ->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson("/api/v1/portal/students/{$other->id}/spending-summary")
            ->assertForbidden();
    }

    public function test_response_has_correct_structure(): void
    {
        $this->get()
            ->assertOk()
            ->assertJsonStructure([
                'monthly'              => [['month', 'label', 'total']],
                'top_items',
                'payment_method_split' => ['wallet', 'cash', 'subscription', 'credit'],
                'ytd_total',
                'this_month_total',
                'last_month_total',
            ]);
    }

    public function test_student_with_no_orders_returns_zeros(): void
    {
        $response = $this->get()->assertOk()->json();

        $this->assertEquals(0, $response['ytd_total']);
        $this->assertEquals(0, $response['this_month_total']);
        $this->assertEquals(0, $response['last_month_total']);
        $this->assertEmpty($response['top_items']);
        $this->assertEquals(['wallet' => 0, 'cash' => 0, 'subscription' => 0, 'credit' => 0], $response['payment_method_split']);
    }

    public function test_this_month_total_sums_current_month_orders(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 500.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Cash,
            'status'         => OrderStatus::Completed,
            'total'          => 250.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        $this->get()
            ->assertOk()
            ->assertJsonPath('this_month_total', 750.0);
    }

    public function test_voided_orders_are_excluded(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 300.00,
            'voided_at'      => now(),  // voided — must be excluded
            'created_at'     => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 200.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        $this->get()
            ->assertOk()
            ->assertJsonPath('this_month_total', 200.0);
    }

    public function test_last_month_total_covers_previous_calendar_month(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 400.00,
            'voided_at'      => null,
            'created_at'     => now()->subMonth(),
        ]);

        $this->get()
            ->assertOk()
            ->assertJsonPath('last_month_total', 400.0);
    }

    public function test_ytd_total_covers_from_school_year_start(): void
    {
        // Order from June of the current school year (always in YTD)
        $schoolYearStart = now()->month >= 6
            ? now()->year . '-06-15'
            : (now()->year - 1) . '-06-15';

        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 600.00,
            'voided_at'      => null,
            'created_at'     => $schoolYearStart,
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 200.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        $this->get()
            ->assertOk()
            ->assertJsonPath('ytd_total', 800.0);
    }

    public function test_monthly_array_always_has_exactly_6_entries(): void
    {
        $monthly = $this->get()->assertOk()->json('monthly');

        $this->assertCount(6, $monthly);
    }

    public function test_missing_months_are_filled_with_zero(): void
    {
        // Only create an order for this month; the other 5 months should be 0
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 100.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        $monthly = $this->get()->assertOk()->json('monthly');

        $zeroMonths = array_filter($monthly, fn ($m) => $m['total'] === 0.0);
        $this->assertCount(5, $zeroMonths);
    }

    public function test_top_items_are_limited_to_5_ordered_by_count(): void
    {
        $order = Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 500.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        // Create 6 distinct items with different counts
        $items = [
            ['name' => 'Spaghetti',       'count' => 5],
            ['name' => 'Rice Meal',        'count' => 4],
            ['name' => 'Orange Juice',     'count' => 3],
            ['name' => 'Burger',           'count' => 2],
            ['name' => 'Pancit',           'count' => 1],
            ['name' => 'Hotdog',           'count' => 1],
        ];

        foreach ($items as $item) {
            OrderItem::factory()->count($item['count'])->create([
                'order_id' => $order->id,
                'name'     => $item['name'],
                'price'    => 50.00,
                'quantity' => 1,
            ]);
        }

        $topItems = $this->get()->assertOk()->json('top_items');

        $this->assertCount(5, $topItems);
        $this->assertEquals('Spaghetti', $topItems[0]['name']);
        $this->assertEquals(5, $topItems[0]['count']);
    }

    public function test_payment_method_split_includes_all_four_keys(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status'         => OrderStatus::Completed,
            'total'          => 100.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id'      => $this->branch->id,
            'payment_method' => PaymentMethod::Cash,
            'status'         => OrderStatus::Completed,
            'total'          => 100.00,
            'voided_at'      => null,
            'created_at'     => now(),
        ]);

        $split = $this->get()->assertOk()->json('payment_method_split');

        $this->assertArrayHasKey('wallet', $split);
        $this->assertArrayHasKey('cash', $split);
        $this->assertArrayHasKey('subscription', $split);
        $this->assertArrayHasKey('credit', $split);
        $this->assertEquals(100, array_sum($split));
    }
}
```

- [ ] **Step 3: Run the tests and confirm they fail (controller not yet created)**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Portal/SpendingSummaryControllerTest.php
```

Expected: All tests fail with 404 (route not registered yet).

- [ ] **Step 4: Create the controller**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan make:class App/Http/Controllers/Portal/SpendingSummaryController
```

Replace the generated file at `app/Http/Controllers/Portal/SpendingSummaryController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SpendingSummaryController extends Controller
{
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
        $rawMonthly = $base()
            ->where('created_at', '>=', $chartFrom)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(total) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $monthly = collect();
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key  = $date->format('Y-m');
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
        ->get(['name', 'count']);

        // Payment method split by order count for the current calendar month
        $methodCounts = $base()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('payment_method, COUNT(*) as count')
            ->groupBy('payment_method')
            ->pluck('count', 'payment_method')
            ->toArray();

        $totalOrders = array_sum($methodCounts);
        $split       = ['wallet' => 0, 'cash' => 0, 'subscription' => 0, 'credit' => 0];

        if ($totalOrders > 0) {
            foreach (array_keys($split) as $key) {
                $split[$key] = (int) round((($methodCounts[$key] ?? 0) / $totalOrders) * 100);
            }
        }

        return response()->json([
            'monthly'              => $monthly->values(),
            'top_items'            => $topItems,
            'payment_method_split' => $split,
            'ytd_total'            => (float) $ytdTotal,
            'this_month_total'     => (float) $thisMonthTotal,
            'last_month_total'     => (float) $lastMonthTotal,
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

Open `routes/portal-api.php` and add the new route alongside the existing student routes:

```php
Route::get('students/{student}/spending-summary', [\App\Http\Controllers\Portal\SpendingSummaryController::class, 'show']);
```

- [ ] **Step 6: Run the tests and confirm they pass**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Portal/SpendingSummaryControllerTest.php
```

Expected: All tests pass.

- [ ] **Step 7: Format and commit**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/SpendingSummaryController.php routes/portal-api.php tests/Feature/Portal/SpendingSummaryControllerTest.php
git commit -m "feat(portal): add SpendingSummaryController with aggregated spending data"
```

---

### Task 2: Frontend — Dependencies, Types & API Layer

**Files:**
- Modify: `~/sunbites-portal/package.json` (recharts install)
- Modify: `~/sunbites-portal/types/portal.ts`
- Modify: `~/sunbites-portal/lib/api/portal.ts`

**Interfaces:**
- Produces: `SpendingSummary`, `MonthlySpending`, `TopItem`, `PaymentHistoryEntry` types
- Produces: `studentsApi.spendingSummary(id, params?)` method

---

- [ ] **Step 1: Install recharts**

```bash
cd ~/sunbites-portal && npm install recharts
```

Expected: `recharts` appears in `package.json` dependencies.

- [ ] **Step 2: Add types to `types/portal.ts`**

Append to the end of `~/sunbites-portal/types/portal.ts`:

```typescript
export interface MonthlySpending {
  month: string; // "2026-01"
  label: string; // "Jan"
  total: number;
}

export interface TopItem {
  name: string;
  count: number;
}

export interface SpendingSummary {
  monthly: MonthlySpending[];
  top_items: TopItem[];
  payment_method_split: {
    wallet: number;
    cash: number;
    subscription: number;
    credit: number;
  };
  ytd_total: number;
  this_month_total: number;
  last_month_total: number;
}

export interface PaymentHistoryEntry {
  id: number;
  school_month: string; // "june" | "july" | etc.
  year: number;
  amount: number;
  status: string; // "paid" | "pending"
  paid_at: string | null;
}

export interface PaymentHistoryResponse {
  data: PaymentHistoryEntry[];
}
```

- [ ] **Step 3: Add `spendingSummary` to `studentsApi` in `lib/api/portal.ts`**

Inside the `studentsApi` object, add after the `paymentHistory` entry:

```typescript
spendingSummary: (id: number, params?: { months?: number }) =>
  apiClient.get<SpendingSummary>(`/portal/students/${id}/spending-summary`, { params }),
```

Also update the `paymentHistory` method return type if it currently lacks one — it should return `Promise<PaymentHistoryResponse>`. Check the existing signature and update only if the return type is missing or `any`.

- [ ] **Step 4: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit
```

Expected: No type errors.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add package.json package-lock.json types/portal.ts lib/api/portal.ts
git commit -m "feat(portal): add recharts, SpendingSummary types, and spendingSummary API method"
```

---

### Task 3: Frontend — `StudentSwitcher` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/student-switcher.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/student-switcher.test.tsx`

**Interfaces:**
- Consumes: `students: Array<{ id: number; full_name: string }>`, `activeIndex: number`, `onSelect: (index: number) => void`
- Produces: `StudentSwitcher` component, `STUDENT_COLORS` constant

---

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/student-switcher.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { StudentSwitcher, STUDENT_COLORS } from "./student-switcher";

const students = [
  { id: 1, full_name: "Juan Cayao" },
  { id: 2, full_name: "Maria Cayao" },
];

describe("StudentSwitcher", () => {
  it("renders one button per student using first name only", () => {
    render(
      <StudentSwitcher students={students} activeIndex={0} onSelect={() => {}} />
    );
    expect(screen.getByRole("button", { name: /juan/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /maria/i })).toBeInTheDocument();
  });

  it("calls onSelect with the correct index when clicked", async () => {
    const onSelect = jest.fn();
    render(
      <StudentSwitcher students={students} activeIndex={0} onSelect={onSelect} />
    );
    await userEvent.click(screen.getByRole("button", { name: /maria/i }));
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it("applies student color as background on the active button", () => {
    render(
      <StudentSwitcher students={students} activeIndex={0} onSelect={() => {}} />
    );
    const activeBtn = screen.getByRole("button", { name: /juan/i });
    expect(activeBtn).toHaveStyle({ backgroundColor: STUDENT_COLORS[0] });
  });

  it("does not apply background color to inactive buttons", () => {
    render(
      <StudentSwitcher students={students} activeIndex={0} onSelect={() => {}} />
    );
    const inactiveBtn = screen.getByRole("button", { name: /maria/i });
    expect(inactiveBtn).not.toHaveStyle({ backgroundColor: STUDENT_COLORS[1] });
  });
});
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest student-switcher.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 3: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/student-switcher.tsx`:

```typescript
"use client";

import { cn } from "@/lib/utils";

export const STUDENT_COLORS = ["#F97316", "#8B5CF6", "#0EA5E9", "#10B981"];

interface Props {
  students: Array<{ id: number; full_name: string }>;
  activeIndex: number;
  onSelect: (index: number) => void;
}

export function StudentSwitcher({ students, activeIndex, onSelect }: Props) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {students.map((student, i) => {
        const color = STUDENT_COLORS[i] ?? "#6B7280";
        const isActive = i === activeIndex;

        return (
          <button
            key={student.id}
            type="button"
            onClick={() => onSelect(i)}
            className={cn(
              "flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-[12.5px] font-medium transition-all",
              isActive
                ? "border-transparent text-white"
                : "border-border text-muted-foreground hover:border-muted hover:bg-muted/30 hover:text-foreground"
            )}
            style={isActive ? { backgroundColor: color } : undefined}
          >
            <span
              className="inline-block h-2 w-2 flex-shrink-0 rounded-full"
              style={{
                backgroundColor: isActive ? "rgba(255,255,255,0.65)" : color,
              }}
            />
            {student.full_name.split(" ")[0]}
          </button>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 4: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest student-switcher.test --no-coverage
```

Expected: All 4 tests pass.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/student-switcher.tsx \
        app/\(portal\)/dashboard/_components/student-switcher.test.tsx
git commit -m "feat(portal): add StudentSwitcher component with per-student color identity"
```

---

### Task 4: Frontend — `SpendingStatCells` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/spending-stat-cells.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/spending-stat-cells.test.tsx`

**Interfaces:**
- Consumes: `data: SpendingSummary` from `@/types/portal`
- Produces: `SpendingStatCells` component

---

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/spending-stat-cells.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import { SpendingStatCells } from "./spending-stat-cells";
import type { SpendingSummary } from "@/types/portal";

const base: SpendingSummary = {
  monthly: [],
  top_items: [{ name: "Spaghetti", count: 18 }],
  payment_method_split: { wallet: 65, cash: 35, subscription: 0, credit: 0 },
  ytd_total: 5950,
  this_month_total: 1250,
  last_month_total: 1050,
};

describe("SpendingStatCells", () => {
  it("shows this month total amount", () => {
    render(<SpendingStatCells data={base} />);
    expect(screen.getByText(/1,250/)).toBeInTheDocument();
  });

  it("shows upward delta when this month exceeds last month", () => {
    render(<SpendingStatCells data={base} />);
    // 1250 vs 1050 = 19% increase
    expect(screen.getByText(/19%/)).toBeInTheDocument();
    expect(screen.getByText(/↑/)).toBeInTheDocument();
  });

  it("shows downward delta when this month is less than last month", () => {
    render(
      <SpendingStatCells
        data={{ ...base, this_month_total: 900, last_month_total: 1050 }}
      />
    );
    expect(screen.getByText(/↓/)).toBeInTheDocument();
  });

  it("shows YTD total", () => {
    render(<SpendingStatCells data={base} />);
    expect(screen.getByText(/5,950/)).toBeInTheDocument();
  });

  it("shows top item name and order count", () => {
    render(<SpendingStatCells data={base} />);
    expect(screen.getByText("Spaghetti")).toBeInTheDocument();
    expect(screen.getByText(/18/)).toBeInTheDocument();
  });

  it("shows empty fallback when no top items", () => {
    render(<SpendingStatCells data={{ ...base, top_items: [] }} />);
    expect(screen.getByText(/no orders yet/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest spending-stat-cells.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 3: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/spending-stat-cells.tsx`:

```typescript
import { cn } from "@/lib/utils";
import type { SpendingSummary } from "@/types/portal";

interface Props {
  data: SpendingSummary;
}

function formatAmount(n: number) {
  return `₱${Math.round(n).toLocaleString("en-PH")}`;
}

export function SpendingStatCells({ data }: Props) {
  const delta =
    data.last_month_total > 0
      ? Math.round(
          (Math.abs(data.this_month_total - data.last_month_total) /
            data.last_month_total) *
            100
        )
      : null;
  const isUp = data.this_month_total > data.last_month_total;
  const isDown = data.this_month_total < data.last_month_total;
  const topItem = data.top_items[0];

  return (
    <div className="grid grid-cols-3 divide-x divide-border border-y border-border">
      <div className="px-6 py-4">
        <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.8px] text-muted-foreground">
          This Month
        </p>
        <p className="text-2xl font-extrabold leading-none tracking-tight">
          {formatAmount(data.this_month_total)}
        </p>
        {delta !== null && (
          <p
            className={cn(
              "mt-1.5 flex items-center gap-0.5 text-[11px] font-medium",
              isUp ? "text-red-500" : isDown ? "text-emerald-500" : "text-muted-foreground"
            )}
          >
            {isUp ? "↑" : isDown ? "↓" : ""} {delta}% vs last month
          </p>
        )}
      </div>

      <div className="px-6 py-4">
        <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.8px] text-muted-foreground">
          Year to Date
        </p>
        <p className="text-2xl font-extrabold leading-none tracking-tight">
          {formatAmount(data.ytd_total)}
        </p>
        <p className="mt-1.5 text-[11px] text-muted-foreground">School year total</p>
      </div>

      <div className="px-6 py-4">
        <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.8px] text-muted-foreground">
          Top Item
        </p>
        {topItem ? (
          <>
            <p className="mt-1 text-[15px] font-bold leading-tight">{topItem.name}</p>
            <p className="mt-1.5 text-[11px] text-muted-foreground">
              {topItem.count}× ordered this month
            </p>
          </>
        ) : (
          <p className="mt-1 text-sm text-muted-foreground">No orders yet</p>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest spending-stat-cells.test --no-coverage
```

Expected: All 6 tests pass.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/spending-stat-cells.tsx \
        app/\(portal\)/dashboard/_components/spending-stat-cells.test.tsx
git commit -m "feat(portal): add SpendingStatCells with this-month delta and top item"
```

---

### Task 5: Frontend — `MonthlyTrendChart` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/monthly-trend-chart.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/monthly-trend-chart.test.tsx`

**Interfaces:**
- Consumes: `data: MonthlySpending[]`, `color: string`
- Produces: `MonthlyTrendChart` component

---

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/monthly-trend-chart.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import { MonthlyTrendChart } from "./monthly-trend-chart";
import type { MonthlySpending } from "@/types/portal";

jest.mock("recharts", () => ({
  ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="responsive-container">{children}</div>
  ),
  BarChart: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="bar-chart">{children}</div>
  ),
  Bar: () => <div data-testid="bar" />,
  XAxis: () => null,
  YAxis: () => null,
  Tooltip: () => null,
  ReferenceLine: () => null,
  Cell: () => null,
}));

const mockData: MonthlySpending[] = [
  { month: "2026-01", label: "Jan", total: 850 },
  { month: "2026-02", label: "Feb", total: 920 },
  { month: "2026-06", label: "Jun", total: 1250 },
];

describe("MonthlyTrendChart", () => {
  it("renders bar chart when data is provided", () => {
    render(<MonthlyTrendChart data={mockData} color="#F97316" />);
    expect(screen.getByTestId("bar-chart")).toBeInTheDocument();
  });

  it("renders empty state message when data array is empty", () => {
    render(<MonthlyTrendChart data={[]} color="#F97316" />);
    expect(screen.getByText(/no spending data/i)).toBeInTheDocument();
    expect(screen.queryByTestId("bar-chart")).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest monthly-trend-chart.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 3: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/monthly-trend-chart.tsx`:

```typescript
"use client";

import {
  Bar,
  BarChart,
  Cell,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { MonthlySpending } from "@/types/portal";

interface Props {
  data: MonthlySpending[];
  color: string;
}

function formatYAxis(value: number) {
  if (value >= 1000) {
    return `₱${(value / 1000).toFixed(value % 1000 === 0 ? 0 : 1)}k`;
  }
  return `₱${value}`;
}

function CustomTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ value: number }>;
  label?: string;
}) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg border border-border bg-card px-3 py-2 text-sm shadow-sm">
      <p className="font-medium text-foreground">{label}</p>
      <p className="text-muted-foreground">
        ₱{payload[0].value.toLocaleString("en-PH")}
      </p>
    </div>
  );
}

export function MonthlyTrendChart({ data, color }: Props) {
  if (!data.length) {
    return (
      <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
        No spending data available.
      </div>
    );
  }

  const nonZero = data.filter((d) => d.total > 0);
  const avg =
    nonZero.length > 0
      ? nonZero.reduce((s, d) => s + d.total, 0) / nonZero.length
      : 0;

  const lastLabel = data[data.length - 1]?.label;

  return (
    <ResponsiveContainer width="100%" height={200}>
      <BarChart data={data} margin={{ top: 24, right: 8, bottom: 0, left: 0 }}>
        <XAxis
          dataKey="label"
          axisLine={false}
          tickLine={false}
          tick={({ x, y, payload }: { x: number; y: number; payload: { value: string } }) => (
            <text
              x={x}
              y={y + 12}
              textAnchor="middle"
              fill={payload.value === lastLabel ? color : "#9CA3AF"}
              fontSize={payload.value === lastLabel ? 11 : 10}
              fontWeight={payload.value === lastLabel ? 700 : 400}
            >
              {payload.value}
            </text>
          )}
        />
        <YAxis
          axisLine={false}
          tickLine={false}
          tickFormatter={formatYAxis}
          tick={{ fill: "#B0B8C4", fontSize: 10 }}
          width={44}
        />
        <Tooltip content={<CustomTooltip />} cursor={{ fill: "transparent" }} />
        {avg > 0 && (
          <ReferenceLine
            y={avg}
            stroke={color}
            strokeDasharray="3 4"
            strokeOpacity={0.35}
            strokeWidth={1.5}
            label={{
              value: "avg",
              position: "insideTopLeft",
              fill: color,
              fillOpacity: 0.6,
              fontSize: 9,
            }}
          />
        )}
        <Bar dataKey="total" radius={[5, 5, 0, 0]}>
          {data.map((_, index) => (
            <Cell
              key={`cell-${index}`}
              fill={index === data.length - 1 ? color : `${color}28`}
            />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
```

- [ ] **Step 4: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest monthly-trend-chart.test --no-coverage
```

Expected: Both tests pass.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/monthly-trend-chart.tsx \
        app/\(portal\)/dashboard/_components/monthly-trend-chart.test.tsx
git commit -m "feat(portal): add MonthlyTrendChart with Recharts bar chart"
```

---

### Task 6: Frontend — `TopItemsList` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/top-items-list.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/top-items-list.test.tsx`

**Interfaces:**
- Consumes: `items: TopItem[]`, `color: string`
- Produces: `TopItemsList` component

---

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/top-items-list.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import { TopItemsList } from "./top-items-list";
import type { TopItem } from "@/types/portal";

const items: TopItem[] = [
  { name: "Spaghetti", count: 18 },
  { name: "Rice w/ Chicken", count: 14 },
  { name: "Orange Juice", count: 12 },
];

describe("TopItemsList", () => {
  it("renders all item names", () => {
    render(<TopItemsList items={items} color="#F97316" />);
    expect(screen.getByText("Spaghetti")).toBeInTheDocument();
    expect(screen.getByText("Rice w/ Chicken")).toBeInTheDocument();
    expect(screen.getByText("Orange Juice")).toBeInTheDocument();
  });

  it("renders order counts with × symbol", () => {
    render(<TopItemsList items={items} color="#F97316" />);
    expect(screen.getByText("18×")).toBeInTheDocument();
    expect(screen.getByText("14×")).toBeInTheDocument();
  });

  it("renders rank numbers starting at 1", () => {
    render(<TopItemsList items={items} color="#F97316" />);
    expect(screen.getByText("1")).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
  });

  it("shows empty state when items array is empty", () => {
    render(<TopItemsList items={[]} color="#F97316" />);
    expect(screen.getByText(/no orders this month/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest top-items-list.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 3: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/top-items-list.tsx`:

```typescript
import type { TopItem } from "@/types/portal";

interface Props {
  items: TopItem[];
  color: string;
}

export function TopItemsList({ items, color }: Props) {
  if (!items.length) {
    return (
      <p className="text-sm text-muted-foreground">No orders this month.</p>
    );
  }

  const max = items[0].count;

  return (
    <ul className="flex flex-col gap-3">
      {items.map((item, i) => (
        <li key={item.name} className="flex items-center gap-2.5">
          <span className="w-3.5 flex-shrink-0 text-center text-[10.5px] font-bold text-muted-foreground">
            {i + 1}
          </span>
          <div className="min-w-0 flex-1">
            <p className="mb-1.5 truncate text-[13px] font-medium text-foreground">
              {item.name}
            </p>
            <div className="h-[5px] overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full transition-all duration-500"
                style={{
                  width: `${Math.round((item.count / max) * 100)}%`,
                  backgroundColor: color,
                }}
              />
            </div>
          </div>
          <span className="min-w-[28px] flex-shrink-0 text-right text-[12px] font-semibold text-muted-foreground">
            {item.count}×
          </span>
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 4: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest top-items-list.test --no-coverage
```

Expected: All 4 tests pass.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/top-items-list.tsx \
        app/\(portal\)/dashboard/_components/top-items-list.test.tsx
git commit -m "feat(portal): add TopItemsList with ranked fill bars"
```

---

### Task 7: Frontend — `PaymentMethodSplit` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/payment-method-split.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/payment-method-split.test.tsx`

**Interfaces:**
- Consumes: `split: SpendingSummary["payment_method_split"]`, `color: string`
- Produces: `PaymentMethodSplit` component

---

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/payment-method-split.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import { PaymentMethodSplit } from "./payment-method-split";

describe("PaymentMethodSplit", () => {
  it("renders bars for non-zero methods only", () => {
    render(
      <PaymentMethodSplit
        split={{ wallet: 65, cash: 35, subscription: 0, credit: 0 }}
        color="#F97316"
      />
    );
    expect(screen.getByText("Wallet")).toBeInTheDocument();
    expect(screen.getByText("Cash")).toBeInTheDocument();
    expect(screen.queryByText("Plan")).not.toBeInTheDocument();
    expect(screen.queryByText("Credit")).not.toBeInTheDocument();
  });

  it("renders Plan bar when subscription orders exist", () => {
    render(
      <PaymentMethodSplit
        split={{ wallet: 40, cash: 20, subscription: 40, credit: 0 }}
        color="#8B5CF6"
      />
    );
    expect(screen.getByText("Plan")).toBeInTheDocument();
  });

  it("shows percentages next to each bar", () => {
    render(
      <PaymentMethodSplit
        split={{ wallet: 65, cash: 35, subscription: 0, credit: 0 }}
        color="#F97316"
      />
    );
    expect(screen.getByText("65%")).toBeInTheDocument();
    expect(screen.getByText("35%")).toBeInTheDocument();
  });

  it("shows empty state when all percentages are zero", () => {
    render(
      <PaymentMethodSplit
        split={{ wallet: 0, cash: 0, subscription: 0, credit: 0 }}
        color="#F97316"
      />
    );
    expect(screen.getByText(/no orders this month/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest payment-method-split.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 3: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/payment-method-split.tsx`:

```typescript
import type { SpendingSummary } from "@/types/portal";

interface Props {
  split: SpendingSummary["payment_method_split"];
  color: string;
}

type MethodKey = keyof SpendingSummary["payment_method_split"];

const METHOD_CONFIG: Record<MethodKey, { label: string; bg: (color: string) => string }> = {
  wallet:       { label: "Wallet", bg: (c) => c },
  subscription: { label: "Plan",   bg: () => "#34D399" },
  cash:         { label: "Cash",   bg: () => "#CBD5E1" },
  credit:       { label: "Credit", bg: () => "#FCA5A5" },
};

export function PaymentMethodSplit({ split, color }: Props) {
  const activeKeys = (Object.keys(METHOD_CONFIG) as MethodKey[]).filter(
    (k) => split[k] > 0
  );

  if (!activeKeys.length) {
    return <p className="text-sm text-muted-foreground">No orders this month.</p>;
  }

  return (
    <div className="flex flex-col gap-2">
      {activeKeys.map((key) => (
        <div key={key} className="flex items-center gap-2.5">
          <span className="w-[42px] flex-shrink-0 text-[12px] text-muted-foreground">
            {METHOD_CONFIG[key].label}
          </span>
          <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
            <div
              className="h-full rounded-full transition-all duration-500"
              style={{
                width: `${split[key]}%`,
                backgroundColor: METHOD_CONFIG[key].bg(color),
              }}
            />
          </div>
          <span className="w-8 flex-shrink-0 text-right text-[12px] font-bold text-foreground">
            {split[key]}%
          </span>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest payment-method-split.test --no-coverage
```

Expected: All 4 tests pass.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/payment-method-split.tsx \
        app/\(portal\)/dashboard/_components/payment-method-split.test.tsx
git commit -m "feat(portal): add PaymentMethodSplit showing wallet/cash/subscription/credit bars"
```

---

### Task 8: Frontend — `PaymentHistoryTimeline` component

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.test.tsx`
- Modify: `~/sunbites-portal/__tests__/mocks/handlers.ts`

**Interfaces:**
- Consumes: `student: StudentSummary`, `color: string`
- Consumes (API): `studentsApi.paymentHistory(id)` → `PaymentHistoryResponse`
- Produces: `PaymentHistoryTimeline` component

---

- [ ] **Step 1: Add MSW handler for payment-history**

Open `~/sunbites-portal/__tests__/mocks/handlers.ts` and add to the handlers array:

```typescript
http.get("*/portal/students/:id/payment-history", () =>
  HttpResponse.json({
    data: [
      { id: 1, school_month: "february", year: 2026, amount: 2500, status: "paid",    paid_at: "2026-02-06T10:00:00Z" },
      { id: 2, school_month: "march",    year: 2026, amount: 2500, status: "paid",    paid_at: "2026-03-04T10:00:00Z" },
      { id: 3, school_month: "april",    year: 2026, amount: 2500, status: "pending", paid_at: null },
      { id: 4, school_month: "may",      year: 2026, amount: 2500, status: "paid",    paid_at: "2026-05-03T10:00:00Z" },
      { id: 5, school_month: "june",     year: 2026, amount: 2500, status: "paid",    paid_at: "2026-06-05T10:00:00Z" },
    ],
  })
),
```

- [ ] **Step 2: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import { server } from "@/__tests__/mocks/server";
import { http, HttpResponse } from "msw";
import { PaymentHistoryTimeline } from "./payment-history-timeline";
import type { StudentSummary } from "@/types/portal";

const student = {
  id: 1,
  full_name: "Juan Cayao",
  student_type: "subscription",
} as StudentSummary;

describe("PaymentHistoryTimeline", () => {
  it("shows month abbreviation for each payment entry", async () => {
    render(<PaymentHistoryTimeline student={student} color="#F97316" />);
    expect(await screen.findByText("Feb")).toBeInTheDocument();
    expect(await screen.findByText("Apr")).toBeInTheDocument();
  });

  it("shows 'Unpaid' label for pending entries", async () => {
    render(<PaymentHistoryTimeline student={student} color="#F97316" />);
    expect(await screen.findByText("Unpaid")).toBeInTheDocument();
  });

  it("shows Overdue badge when current month is pending", async () => {
    server.use(
      http.get("*/portal/students/:id/payment-history", () =>
        HttpResponse.json({
          data: [
            { id: 1, school_month: "june", year: new Date().getFullYear(), amount: 2500, status: "pending", paid_at: null },
          ],
        })
      )
    );
    render(<PaymentHistoryTimeline student={student} color="#F97316" />);
    expect(await screen.findByText("Overdue")).toBeInTheDocument();
  });

  it("renders at most 5 payment cards even when more records exist", async () => {
    server.use(
      http.get("*/portal/students/:id/payment-history", () =>
        HttpResponse.json({
          data: [
            { id: 1, school_month: "june",      year: 2025, amount: 2500, status: "paid", paid_at: "2025-06-01T00:00:00Z" },
            { id: 2, school_month: "july",      year: 2025, amount: 2500, status: "paid", paid_at: "2025-07-01T00:00:00Z" },
            { id: 3, school_month: "august",    year: 2025, amount: 2500, status: "paid", paid_at: "2025-08-01T00:00:00Z" },
            { id: 4, school_month: "september", year: 2025, amount: 2500, status: "paid", paid_at: "2025-09-01T00:00:00Z" },
            { id: 5, school_month: "october",   year: 2025, amount: 2500, status: "paid", paid_at: "2025-10-01T00:00:00Z" },
            { id: 6, school_month: "november",  year: 2025, amount: 2500, status: "paid", paid_at: "2025-11-01T00:00:00Z" },
            { id: 7, school_month: "december",  year: 2025, amount: 2500, status: "paid", paid_at: "2025-12-01T00:00:00Z" },
          ],
        })
      )
    );
    render(<PaymentHistoryTimeline student={student} color="#F97316" />);
    await screen.findByText("Aug"); // wait for data to load
    // Only 5 cards: aug, sep, oct, nov, dec (last 5)
    expect(screen.queryByText("Jun")).not.toBeInTheDocument();
    expect(screen.queryByText("Jul")).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest payment-history-timeline.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 4: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.tsx`:

```typescript
"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckIcon, XIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { studentsApi } from "@/lib/api/portal";
import type { StudentSummary } from "@/types/portal";

const MONTH_LABELS: Record<string, string> = {
  june: "Jun", july: "Jul", august: "Aug", september: "Sep",
  october: "Oct", november: "Nov", december: "Dec",
  january: "Jan", february: "Feb", march: "Mar",
};

function isCurrentSchoolMonth(schoolMonth: string, year: number): boolean {
  const now = new Date();
  const currentMonthName = now
    .toLocaleString("en-US", { month: "long" })
    .toLowerCase();
  return schoolMonth === currentMonthName && year === now.getFullYear();
}

interface Props {
  student: StudentSummary;
  color: string;
}

export function PaymentHistoryTimeline({ student, color }: Props) {
  const { data, isLoading } = useQuery({
    queryKey: ["payment-history", student.id],
    queryFn: () => studentsApi.paymentHistory(student.id),
  });

  if (isLoading) {
    return (
      <div className="border-t border-border px-6 py-4">
        <div className="h-24 animate-pulse rounded-lg bg-muted" />
      </div>
    );
  }

  const entries = data?.data ?? [];
  if (!entries.length) return null;

  const recent = entries.slice(-5);
  const currentEntry = recent.find((p) =>
    isCurrentSchoolMonth(p.school_month, p.year)
  );
  const isOverdue = currentEntry?.status !== "paid";

  return (
    <div className="border-t border-border px-6 py-4">
      <div className="mb-4 flex items-center justify-between">
        <p className="text-[10.5px] font-bold uppercase tracking-[0.8px] text-muted-foreground">
          Subscription Payments · {new Date().getFullYear()}
        </p>
        <span
          className={cn(
            "flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold",
            isOverdue
              ? "bg-red-50 text-red-600"
              : "bg-emerald-50 text-emerald-600"
          )}
        >
          <span
            className={cn(
              "inline-block h-1.5 w-1.5 rounded-full",
              isOverdue ? "bg-red-500" : "bg-emerald-500"
            )}
          />
          {isOverdue ? "Overdue" : "Current"}
        </span>
      </div>

      <div className="grid grid-cols-5 gap-2">
        {recent.map((payment) => {
          const isCurrent = isCurrentSchoolMonth(payment.school_month, payment.year);
          const isPaid = payment.status === "paid";

          return (
            <div
              key={`${payment.school_month}-${payment.year}`}
              className={cn(
                "flex flex-col items-center gap-1.5 rounded-xl border px-1.5 py-2.5",
                isCurrent ? "border-transparent" : "border-border"
              )}
              style={
                isCurrent
                  ? { backgroundColor: `${color}12`, borderColor: `${color}40` }
                  : undefined
              }
            >
              <span
                className="text-[11px] font-bold uppercase tracking-[0.4px]"
                style={{ color: isCurrent ? color : undefined }}
              >
                {MONTH_LABELS[payment.school_month] ?? payment.school_month}
              </span>
              <span className="-mt-1 text-[10px] text-muted-foreground">
                {payment.year}
              </span>
              <div
                className={cn(
                  "flex h-7 w-7 items-center justify-center rounded-full",
                  isPaid ? "bg-emerald-50" : "bg-red-50"
                )}
              >
                {isPaid ? (
                  <CheckIcon className="h-3.5 w-3.5 text-emerald-600" strokeWidth={2.5} />
                ) : (
                  <XIcon className="h-3.5 w-3.5 text-red-600" strokeWidth={2.5} />
                )}
              </div>
              <span
                className="text-[11px] font-bold"
                style={{ color: isCurrent ? color : undefined }}
              >
                ₱{payment.amount.toLocaleString("en-PH")}
              </span>
              <span
                className={cn(
                  "text-center text-[10px]",
                  isPaid ? "text-muted-foreground" : "font-semibold text-red-500"
                )}
              >
                {isPaid
                  ? payment.paid_at
                    ? new Date(payment.paid_at).toLocaleDateString("en-US", {
                        month: "short",
                        day: "numeric",
                      })
                    : "—"
                  : "Unpaid"}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest payment-history-timeline.test --no-coverage
```

Expected: All 4 tests pass.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/payment-history-timeline.tsx \
        app/\(portal\)/dashboard/_components/payment-history-timeline.test.tsx \
        __tests__/mocks/handlers.ts
git commit -m "feat(portal): add PaymentHistoryTimeline with 5-month subscription payment cards"
```

---

### Task 9: Frontend — `SpendingInsights` orchestrator

**Files:**
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/spending-insights.tsx`
- Create: `~/sunbites-portal/app/(portal)/dashboard/_components/spending-insights.test.tsx`
- Modify: `~/sunbites-portal/__tests__/mocks/handlers.ts`

**Interfaces:**
- Consumes: `students: StudentSummary[]` from `@/types/portal`
- Consumes (from Tasks 3–8): `StudentSwitcher`, `STUDENT_COLORS`, `SpendingStatCells`, `MonthlyTrendChart`, `TopItemsList`, `PaymentMethodSplit`, `PaymentHistoryTimeline`
- Produces: `SpendingInsights` component

---

- [ ] **Step 1: Add MSW handler for spending-summary**

Open `~/sunbites-portal/__tests__/mocks/handlers.ts` and add:

```typescript
http.get("*/portal/students/:id/spending-summary", () =>
  HttpResponse.json({
    monthly: [
      { month: "2026-01", label: "Jan", total: 850 },
      { month: "2026-02", label: "Feb", total: 920 },
      { month: "2026-03", label: "Mar", total: 780 },
      { month: "2026-04", label: "Apr", total: 1100 },
      { month: "2026-05", label: "May", total: 1050 },
      { month: "2026-06", label: "Jun", total: 1250 },
    ],
    top_items: [
      { name: "Spaghetti", count: 18 },
      { name: "Rice w/ Chicken", count: 14 },
    ],
    payment_method_split: { wallet: 65, cash: 35, subscription: 0, credit: 0 },
    ytd_total: 5950,
    this_month_total: 1250,
    last_month_total: 1050,
  })
),
```

- [ ] **Step 2: Write the failing test**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/spending-insights.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { server } from "@/__tests__/mocks/server";
import { http, HttpResponse } from "msw";
import { SpendingInsights } from "./spending-insights";
import type { StudentSummary } from "@/types/portal";

jest.mock("recharts", () => ({
  ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
    <div>{children}</div>
  ),
  BarChart: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  Bar: () => null,
  XAxis: () => null,
  YAxis: () => null,
  Tooltip: () => null,
  ReferenceLine: () => null,
  Cell: () => null,
}));

const students: StudentSummary[] = [
  { id: 1, full_name: "Juan Cayao",  student_type: "subscription" } as StudentSummary,
  { id: 2, full_name: "Maria Cayao", student_type: "non_subscription" } as StudentSummary,
];

describe("SpendingInsights", () => {
  it("renders a switcher button per student", async () => {
    render(<SpendingInsights students={students} />);
    expect(screen.getByRole("button", { name: /juan/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /maria/i })).toBeInTheDocument();
  });

  it("shows stat cells after data loads", async () => {
    render(<SpendingInsights students={students} />);
    expect(await screen.findByText(/1,250/)).toBeInTheDocument(); // this_month_total
    expect(await screen.findByText(/5,950/)).toBeInTheDocument(); // ytd_total
    expect(await screen.findByText("Spaghetti")).toBeInTheDocument(); // top item
  });

  it("shows subscription payment section for subscription students", async () => {
    render(<SpendingInsights students={students} />);
    // Payment history section heading only appears for subscription students
    expect(
      await screen.findByText(/subscription payments/i)
    ).toBeInTheDocument();
  });

  it("hides subscription section when switching to a non-subscription student", async () => {
    render(<SpendingInsights students={students} />);
    await screen.findByText("Spaghetti"); // wait for initial data

    await userEvent.click(screen.getByRole("button", { name: /maria/i }));
    // After switching, subscription section should not appear
    expect(
      screen.queryByText(/subscription payments/i)
    ).not.toBeInTheDocument();
  });

  it("shows error message when API fails", async () => {
    server.use(
      http.get("*/portal/students/:id/spending-summary", () =>
        HttpResponse.json({ message: "Server error" }, { status: 500 })
      )
    );
    render(<SpendingInsights students={students} />);
    expect(
      await screen.findByText(/failed to load spending data/i)
    ).toBeInTheDocument();
  });

  it("returns null when students array is empty", () => {
    const { container } = render(<SpendingInsights students={[]} />);
    expect(container.firstChild).toBeNull();
  });
});
```

- [ ] **Step 3: Run to confirm failure**

```bash
cd ~/sunbites-portal && npx jest spending-insights.test --no-coverage
```

Expected: Fails — module not found.

- [ ] **Step 4: Implement the component**

Create `~/sunbites-portal/app/(portal)/dashboard/_components/spending-insights.tsx`:

```typescript
"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { studentsApi } from "@/lib/api/portal";
import type { StudentSummary } from "@/types/portal";
import { StudentSwitcher, STUDENT_COLORS } from "./student-switcher";
import { SpendingStatCells } from "./spending-stat-cells";
import { MonthlyTrendChart } from "./monthly-trend-chart";
import { TopItemsList } from "./top-items-list";
import { PaymentMethodSplit } from "./payment-method-split";
import { PaymentHistoryTimeline } from "./payment-history-timeline";

interface Props {
  students: StudentSummary[];
}

export function SpendingInsights({ students }: Props) {
  const [activeIndex, setActiveIndex] = useState(0);

  if (!students.length) return null;

  const activeStudent = students[activeIndex];
  const color = STUDENT_COLORS[activeIndex] ?? "#6B7280";

  const { data, isLoading, error } = useQuery({
    queryKey: ["spending-summary", activeStudent.id],
    queryFn: () => studentsApi.spendingSummary(activeStudent.id),
  });

  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-card">
      {/* Header */}
      <div className="mb-4 px-6 pt-5">
        <h2 className="text-[15px] font-bold tracking-tight">
          Monthly Spending Overview
        </h2>
        <p className="mt-0.5 text-[12px] text-muted-foreground">
          Canteen activity per student
        </p>
      </div>

      {/* Student switcher */}
      <div className="mb-5 px-6">
        <StudentSwitcher
          students={students}
          activeIndex={activeIndex}
          onSelect={setActiveIndex}
        />
      </div>

      {/* Loading skeleton */}
      {isLoading && (
        <div className="border-y border-border">
          <div className="grid grid-cols-3 divide-x divide-border">
            {[1, 2, 3].map((i) => (
              <div key={i} className="px-6 py-4">
                <div className="mb-3 h-3 w-20 animate-pulse rounded bg-muted" />
                <div className="h-6 w-28 animate-pulse rounded bg-muted" />
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Error state */}
      {error && (
        <p className="px-6 py-4 text-sm text-destructive">
          Failed to load spending data.
        </p>
      )}

      {/* Data */}
      {data && (
        <>
          <SpendingStatCells data={data} />

          <div className="grid grid-cols-[1fr_290px] divide-x divide-border border-b border-border">
            <div className="p-6 pt-5">
              <p className="mb-3.5 text-[11px] font-semibold uppercase tracking-[0.7px] text-muted-foreground">
                Monthly Trend
              </p>
              <MonthlyTrendChart data={data.monthly} color={color} />
            </div>
            <div className="p-6 pt-5">
              <p className="mb-3.5 text-[11px] font-semibold uppercase tracking-[0.7px] text-muted-foreground">
                Top Items This Month
              </p>
              <TopItemsList items={data.top_items} color={color} />
            </div>
          </div>

          <div className="flex items-center gap-5 px-6 py-4">
            <p className="whitespace-nowrap text-[10.5px] font-bold uppercase tracking-[0.8px] text-muted-foreground">
              Payment
              <br />
              Method
            </p>
            <div className="flex-1">
              <PaymentMethodSplit split={data.payment_method_split} color={color} />
            </div>
          </div>

          {activeStudent.student_type === "subscription" && (
            <PaymentHistoryTimeline student={activeStudent} color={color} />
          )}
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 5: Run tests and confirm they pass**

```bash
cd ~/sunbites-portal && npx jest spending-insights.test --no-coverage
```

Expected: All 6 tests pass.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/_components/spending-insights.tsx \
        app/\(portal\)/dashboard/_components/spending-insights.test.tsx \
        __tests__/mocks/handlers.ts
git commit -m "feat(portal): add SpendingInsights orchestrator with student switcher and full data view"
```

---

### Task 10: Frontend — Wire up the dashboard

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/dashboard/page.tsx`

**Interfaces:**
- Consumes: `SpendingInsights` from `./_components/spending-insights`
- Consumes: `DashboardData.students` (already fetched by the dashboard query)

---

- [ ] **Step 1: Add the import**

Open `~/sunbites-portal/app/(portal)/dashboard/page.tsx` and add the import at the top alongside the existing component imports:

```typescript
import { SpendingInsights } from "./_components/spending-insights";
```

- [ ] **Step 2: Render SpendingInsights after the student cards grid**

Inside the JSX, after the student cards grid (look for the `grid` div that maps over `data.students`), add:

```tsx
{data?.students && data.students.length > 0 && (
  <SpendingInsights students={data.students} />
)}
```

The component handles its own loading, error, and empty states — no extra wrapper needed.

- [ ] **Step 3: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit
```

Expected: No type errors.

- [ ] **Step 4: Run the full test suite**

```bash
cd ~/sunbites-portal && npx jest --no-coverage
```

Expected: All tests pass. Investigate and fix any failures before proceeding.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/page.tsx
git commit -m "feat(portal): wire SpendingInsights into dashboard below student cards"
```

- [ ] **Step 6: Run the full backend test suite to confirm nothing regressed**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact
```

Expected: All tests pass.
