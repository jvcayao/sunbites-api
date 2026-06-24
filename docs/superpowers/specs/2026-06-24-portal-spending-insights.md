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

---

## File Structure

```
app/(portal)/dashboard/
  page.tsx                         ← add SpendingInsights below student cards
  _components/
    spending-insights.tsx          ← "use client" — owns active student state
    student-switcher.tsx           ← color-coded pill tabs
    spending-stat-cells.tsx        ← 3 summary stat cells
    monthly-trend-chart.tsx        ← Recharts bar chart (6 months)
    top-items-list.tsx             ← ranked item bars
    payment-method-split.tsx       ← wallet vs cash horizontal bars
    payment-history-timeline.tsx   ← subscription-only 5-month cards

lib/api/portal.ts                  ← add spendingSummary() call
types/portal.ts                    ← add SpendingSummary type
```

---

## Component Breakdown

### `spending-insights.tsx` (Client Component)

Owns `activeStudentIndex` state. Fetches spending summary for the active student via `useQuery`. Renders the card shell and passes data down to sub-components.

```
[ student-switcher ]
[ spending-stat-cells ]
[ monthly-trend-chart | top-items-list ]
[ payment-method-split ]
[ payment-history-timeline ]   ← subscription students only
```

### `student-switcher.tsx`

Renders one pill button per student. Active pill has full background in the student's color. Each student maps to a fixed color:

| Index | Color |
|---|---|
| 0 | `#F97316` (orange) |
| 1 | `#8B5CF6` (violet) |
| 2 | `#0EA5E9` (sky) |
| 3 | `#10B981` (emerald) |

Color is passed as a prop to all sibling components so the accent shifts together when the active student changes.

### `spending-stat-cells.tsx`

Three cells separated by borders:

| Cell | Content |
|---|---|
| This Month | `spending_total` for current month + % delta vs previous month (red ↑ / green ↓) |
| Year to Date | Sum of all monthly totals |
| Top Item | Name of most-ordered item + order count |

### `monthly-trend-chart.tsx`

Recharts `<ResponsiveContainer>` + `<BarChart>`. Six months of data ending at the current month (e.g. Jan–Jun when viewed in June).

- Past months: bar fill at 16% opacity of student color
- Current month: full gradient fill, value label above bar
- Average line: `<ReferenceLine>` dashed, at 33% opacity of student color
- X-axis: month abbreviations; current month label in student color + dot indicator
- Y-axis: peso amounts (`₱1k`, `₱2k`)
- Tooltip: shows exact amount on hover

### `top-items-list.tsx`

Ranked list of the top 5 ordered items this month. Each row: rank number, item name, horizontal fill bar (width proportional to order count relative to rank 1), order count. Bar fill uses the student's accent color.

### `payment-method-split.tsx`

Two horizontal bars: Wallet and Cash. Bar width = percentage of total orders. Wallet bar fills with student color; cash bar fills with `#CBD5E1`.

### `payment-history-timeline.tsx`

Visible only for subscription students. Renders 5 monthly payment cards in a row (most recent 5 months from `payment-history` endpoint).

Each card shows:
- Month abbreviation + year
- Check icon (green) for paid / X icon (red) for unpaid
- Amount (e.g. ₱2,500)
- Paid date or "Unpaid" label

Current month card has a tinted background in the student's color. An "Overdue" badge appears in the section header when the current month is unpaid; "Current" when paid.

---

## Backend Changes (`sunbites-api`)

### 1. New endpoint — `Portal/SpendingSummaryController.php`

```
GET /portal/students/{student}/spending-summary
```

Returns aggregated spending data for the chart. No pagination — single response.

**Authorization:** `$this->authorize('view', $student)` via `ParentStudentPolicy::view()`.

**Query params:**

| Param | Type | Default | Notes |
|---|---|---|---|
| `months` | integer | `6` | How many months back to include |

**Response shape:**

```json
{
  "monthly": [
    { "month": "2026-01", "label": "Jan", "total": 850 },
    { "month": "2026-02", "label": "Feb", "total": 920 }
  ],
  "top_items": [
    { "name": "Spaghetti", "count": 18 }
  ],
  "payment_method_split": {
    "wallet": 65,
    "cash": 35
  },
  "ytd_total": 5950,
  "this_month_total": 1250,
  "last_month_total": 1050
}
```

**Implementation notes:**

- `monthly`: query `orders` grouped by `DATE_FORMAT(created_at, '%Y-%m')`, summing `total`, filtered to the last N months ending today. Results returned in chronological order (oldest first). `BranchScope` applies automatically via the `HasBranch` trait.
- `top_items`: join `order_items` through `orders` for the current calendar month, group by `name`, order by count desc, limit 5.
- `payment_method_split`: count orders by `payment_method` for the current month, return wallet and cash as percentages.
- `ytd_total`: sum of `monthly` totals.
- `last_month_total`: used by the frontend to compute the delta percentage.

### 2. Register route in `routes/portal-api.php`

```php
Route::get('students/{student}/spending-summary', [SpendingSummaryController::class, 'show']);
```

### 3. Payment history — no backend change needed

The existing `GET /portal/students/{student}/payment-history` endpoint already returns monthly payment records. The frontend reads the most recent 5 entries.

---

## Frontend Type & API Updates (`sunbites-portal`)

### `types/portal.ts` — add `SpendingSummary`

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
  payment_method_split: { wallet: number; cash: number };
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

## Student Color Mapping

Defined once in `spending-insights.tsx` and passed down as a prop. Maps student index (0–3) to a fixed hex color. Never derives color from student data — purely positional so it stays stable across re-renders.

```typescript
const STUDENT_COLORS = ["#F97316", "#8B5CF6", "#0EA5E9", "#10B981"];
```

---

## Tests

### Backend (PHPUnit)

**`SpendingSummaryControllerTest`:**
- Returns correct monthly totals for the last 6 months
- `this_month_total` matches sum of current month's orders
- `last_month_total` matches previous month
- `top_items` limited to 5, ordered by count descending
- `payment_method_split` percentages sum to 100
- Non-linked student returns 403
- Unauthenticated returns 401
- Student with no orders returns zeros, empty `monthly` and `top_items` arrays

### Frontend (Jest + RTL)

**`spending-insights.test.tsx`:**
- Renders all 3 stat cells with correct values from mock API response
- Switching student tab updates displayed data
- Delta shows red ↑ when this month > last month; green ↓ when less
- Subscription section visible for subscription students; hidden for non-subscription
- Overdue badge appears when current month payment status is unpaid

**`monthly-trend-chart.test.tsx`:**
- Renders without crashing with valid `monthly` data
- Renders empty state message when `monthly` is empty

**`payment-history-timeline.test.tsx`:**
- Paid card shows check icon and paid date
- Unpaid card shows X icon and "Unpaid" label
- Current month card has distinct visual treatment (tested via aria-label or data attribute)
