# Portal Spending Insights — Design Spec

**Date:** 2026-06-24
**Scope:** `sunbites-portal` (frontend) + `sunbites-api` (backend)

---

## Overview

Add a **Spending Insights** section to the parent portal dashboard (`/dashboard`). It sits below the existing student cards and shows per-student canteen spending data: a 6-month bar chart, top purchased items, payment method split, and — for subscription students — a 5-month payment history timeline.

Parents with multiple students switch between children using color-coded pill tabs. Each student has a distinct accent color that flows through all chart elements when selected.

---

## What Changes

### Dashboard Page

A new `<SpendingInsights>` section is appended below the existing student cards in `app/(portal)/dashboard/page.tsx`. The existing student card grid is unchanged.

`dashboard/page.tsx` is already a `"use client"` component. `<SpendingInsights>` is imported directly — no Server/Client boundary concern. The dashboard already calls `dashboardApi.get()` which returns `students[]`. Pass this array as a prop to `<SpendingInsights>` — do **not** make a separate student list call inside the component.

---

## File Structure

```
app/(portal)/dashboard/
  page.tsx                         ← pass students prop to SpendingInsights
  _components/
    spending-insights.tsx          ← "use client" — owns active student state
    student-switcher.tsx           ← color-coded pill tabs
    spending-stat-cells.tsx        ← 3 summary stat cells
    monthly-trend-chart.tsx        ← Recharts bar chart (6 months)
    top-items-list.tsx             ← ranked item bars
    payment-method-split.tsx       ← payment method horizontal bars
    payment-history-timeline.tsx   ← subscription-only 5-month cards

lib/api/portal.ts                  ← add spendingSummary() call
types/portal.ts                    ← add SpendingSummary type
```

---

## Component Breakdown

### `spending-insights.tsx` (Client Component)

**Props:** `students: StudentSummary[]`

Owns `activeStudentIndex` state (default `0`). Fetches spending summary for the active student:

```typescript
const activeStudent = students[activeStudentIndex];

const { data, isLoading, error } = useQuery({
  queryKey: ["spending-summary", activeStudent.id],
  queryFn: () => studentsApi.spendingSummary(activeStudent.id),
  enabled: !!activeStudent,
});
```

Renders the card shell and passes `data` and `color` down to sub-components.

```
[ student-switcher ]
[ spending-stat-cells ]
[ monthly-trend-chart | top-items-list ]
[ payment-method-split ]
[ payment-history-timeline ]   ← subscription students only
```

### `student-switcher.tsx`

Renders one pill button per student. Active pill has full background in the student's color. Each student maps to a fixed color by index:

| Index | Color |
|---|---|
| 0 | `#F97316` (orange) |
| 1 | `#8B5CF6` (violet) |
| 2 | `#0EA5E9` (sky) |
| 3 | `#10B981` (emerald) |

Color is passed as a prop to all sibling components so the accent shifts together when the active student changes.

```typescript
const STUDENT_COLORS = ["#F97316", "#8B5CF6", "#0EA5E9", "#10B981"];
```

### `spending-stat-cells.tsx`

Three cells separated by borders:

| Cell | Content |
|---|---|
| This Month | `this_month_total` + % delta vs `last_month_total` (red ↑ = more spending, green ↓ = less) |
| Year to Date | `ytd_total` (school year to date, independent of chart window — see backend notes) |
| Top Item | `top_items[0].name` + `top_items[0].count`× ordered |

### `monthly-trend-chart.tsx`

Recharts `<ResponsiveContainer>` + `<BarChart>`. Six months of data ending at the current month (e.g. Jan–Jun when viewed in June).

- Past months: bar fill at 16% opacity of student color
- Current month: full gradient fill, value label above bar
- Average line: `<ReferenceLine>` dashed, at 33% opacity of student color
- X-axis: month abbreviations; current month label in student color + dot indicator
- Y-axis: peso amounts (`₱1k`, `₱2k`)
- Tooltip: shows exact peso amount on hover

### `top-items-list.tsx`

Ranked list of the top 5 ordered items this month. Each row: rank number, item name, horizontal fill bar (width proportional to order count relative to rank 1), order count. Bar fill uses the student's accent color.

### `payment-method-split.tsx`

Horizontal bars showing the breakdown of order payment methods for the current month. Only renders bars for methods with a non-zero percentage.

**Order payment methods and their display labels:**

| API value | Display label |
|---|---|
| `wallet` | Wallet |
| `cash` | Cash |
| `subscription` | Plan |
| `credit` | Credit |

Wallet bar fills with the student's accent color. Cash fills `#CBD5E1`. Plan fills `#34D399`. Credit fills `#FCA5A5`.

For non-subscription students, `subscription` will always be `0` and its bar is not rendered.

### `payment-history-timeline.tsx`

Visible only when `activeStudent.student_type === "subscription"`. Fetches from the existing `GET /portal/students/{student}/payment-history` endpoint and displays the **last 5 entries** from the sorted array (most recent school months — the API sorts in school year order, so `slice(-5)` gives the 5 most recent).

Each card shows:
- Month abbreviation derived from `school_month` string enum using this map:
  ```typescript
  const MONTH_LABELS: Record<string, string> = {
    june: "Jun", july: "Jul", august: "Aug", september: "Sep",
    october: "Oct", november: "Nov", december: "Dec",
    january: "Jan", february: "Feb", march: "Mar",
  };
  ```
- Year from `year` field
- Check icon (green) for `status === "paid"` / X icon (red) for `status === "pending"`
- Amount (e.g. ₱2,500)
- Formatted `paid_at` date (e.g. "Jun 5") or "Unpaid" label

Current month card: match by comparing `school_month` and `year` to today's date. Tinted background in the student's color.

Header badge: "Current" (green) if current month is paid, "Overdue" (red) if pending.

---

## Backend Changes (`sunbites-api`)

### 1. New endpoint — `Portal/SpendingSummaryController.php`

```
GET /portal/students/{student}/spending-summary
```

Returns aggregated spending data. Single response, no pagination.

**Authorization:** `$this->authorize('view', $student)` via `ParentStudentPolicy::view()`.

**Query params:**

| Param | Type | Default | Notes |
|---|---|---|---|
| `months` | integer | `6` | How many months back to include in the bar chart |

**Response shape:**

```json
{
  "monthly": [
    { "month": "2026-01", "label": "Jan", "total": 850 },
    { "month": "2026-06", "label": "Jun", "total": 1250 }
  ],
  "top_items": [
    { "name": "Spaghetti", "count": 18 }
  ],
  "payment_method_split": {
    "wallet": 65,
    "cash": 20,
    "subscription": 15,
    "credit": 0
  },
  "ytd_total": 5950,
  "this_month_total": 1250,
  "last_month_total": 1050
}
```

**Implementation notes:**

**Base query (apply to all sub-queries):**
```php
$orders = $student->orders()
    ->whereNull('voided_at')           // exclude voided orders — matches ActivityController
    ->where('status', OrderStatus::Completed); // completed orders only
```

**`monthly`:** Group by `DATE_FORMAT(created_at, '%Y-%m')`, sum `total`, filtered to the last `$months` calendar months ending today. Return in chronological order (oldest first).
```php
$from = now()->subMonths($months - 1)->startOfMonth();
$monthly = (clone $orders)
    ->where('created_at', '>=', $from)
    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(total) as total")
    ->groupBy('month')
    ->orderBy('month')
    ->get();
```
Fill in missing months with `total: 0` so the chart always has exactly `$months` data points.

**`ytd_total`:** Calculated independently from the school year start — **not** derived from `monthly`. School year starts June 1. If current month is before June, school year started June 1 of the previous year.
```php
$schoolYearStart = now()->month >= 6
    ? now()->year . '-06-01'
    : (now()->year - 1) . '-06-01';

$ytd = (clone $orders)
    ->where('created_at', '>=', $schoolYearStart)
    ->sum('total');
```

**`this_month_total`:** Sum of orders in the current calendar month.

**`last_month_total`:** Sum of orders in the previous calendar month.

**`top_items`:** Join `order_items` through orders for the current calendar month, group by `name`, count occurrences, limit 5.
```php
$topItems = OrderItem::whereHas('order', function ($q) use ($student) {
    $q->where('student_id', $student->id)
      ->whereNull('voided_at')
      ->whereMonth('created_at', now()->month)
      ->whereYear('created_at', now()->year);
})
->selectRaw('name, COUNT(*) as count')
->groupBy('name')
->orderByDesc('count')
->limit(5)
->get();
```

**`payment_method_split`:** Count orders by `payment_method` for the current month. Return all 4 values as percentages (0–100). If total is 0, return all zeros.
```php
$methods = (clone $orders)
    ->whereMonth('created_at', now()->month)
    ->whereYear('created_at', now()->year)
    ->selectRaw('payment_method, COUNT(*) as count')
    ->groupBy('payment_method')
    ->pluck('count', 'payment_method')
    ->toArray();

$total = array_sum($methods);
$split = ['wallet' => 0, 'cash' => 0, 'subscription' => 0, 'credit' => 0];
if ($total > 0) {
    foreach ($split as $key => $_) {
        $split[$key] = round((($methods[$key] ?? 0) / $total) * 100);
    }
}
```

### 2. Register route in `routes/portal-api.php`

```php
Route::get('students/{student}/spending-summary', [SpendingSummaryController::class, 'show']);
```

### 3. Payment history — no backend change needed

The existing `GET /portal/students/{student}/payment-history` endpoint already returns records sorted in school year order. The frontend takes `slice(-5)` for the 5 most recent months.

---

## Frontend Type & API Updates (`sunbites-portal`)

### `types/portal.ts` — add types

```typescript
interface MonthlySpending {
  month: string;   // "2026-01"
  label: string;   // "Jan"
  total: number;
}

interface TopItem {
  name: string;
  count: number;
}

interface SpendingSummary {
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
```

### `lib/api/portal.ts` — add to `studentsApi`

```typescript
spendingSummary: (id: number, params?: { months?: number }) =>
  apiClient.get<SpendingSummary>(`/portal/students/${id}/spending-summary`, { params }),
```

---

## Dependencies

- `recharts` — install in `~/sunbites-portal` via `npm install recharts`
- All other dependencies (TanStack Query, Tailwind v4, shadcn/ui, Lucide React) already installed

---

## Tests

### Backend (PHPUnit)

**`SpendingSummaryControllerTest`:**
- Returns correct monthly totals for the last 6 months, oldest first
- Voided orders are excluded from all totals
- `this_month_total` matches sum of current month's completed non-voided orders
- `last_month_total` matches previous calendar month
- `ytd_total` covers from June 1 of the current school year, not limited to the chart window
- `ytd_total` correctly spans previous calendar year when current month is before June
- `top_items` limited to 5, ordered by count descending, scoped to current month
- `payment_method_split` all four keys present; percentages sum to 100 when orders exist
- `payment_method_split` all zeros when no orders this month
- Non-linked student returns 403
- Unauthenticated request returns 401
- Student with no orders returns zeros and empty arrays (no 500 error)
- Missing months in chart window are filled with `total: 0`

### Frontend (Jest + RTL)

**`spending-insights.test.tsx`:**
- Renders skeleton while `isLoading` is true
- Renders all 3 stat cells with correct values from MSW mock response
- Switching student tab fires a new query with the new student's id
- Delta shows red ↑ when `this_month_total > last_month_total`; green ↓ when less
- Subscription section visible for subscription students; hidden for non-subscription students

**`monthly-trend-chart.test.tsx`:**
- Renders without crashing with valid `monthly` data
- Renders an empty/zero state when `monthly` is an empty array

**`payment-history-timeline.test.tsx`:**
- Paid card renders check icon and formatted `paid_at` date
- Pending card renders X icon and "Unpaid" label
- Only the last 5 entries are rendered when more than 5 records exist
- Overdue badge appears in header when current month status is `pending`
- "Current" badge appears when current month status is `paid`

**`payment-method-split.test.tsx`:**
- Does not render a bar for methods with 0%
- Renders "Plan" bar for subscription students with subscription orders
