# Subscription Meal Tracking — Design Spec

**Date:** 2026-06-14
**Status:** Approved

---

## Overview

Subscription students pay for a fixed number of school days per month (e.g., July = 22 days). Today the system records whether July is paid or unpaid but has no visibility into how many of those 22 meal slots have been used. This feature adds a monthly meal-usage tracker so staff can answer parent and student queries instantly, and so parents can self-serve through the portal.

---

## Goals

- Show per-category meal usage (used / allocated / remaining) for the current school month on all student-facing views.
- Surface this on the POS cart panel during subscription checkout.
- Provide a dedicated subscription report in the POS admin area filterable by month and year.
- Expose the same data to parents in the portal so they can check without calling.

---

## Core Data Model

### What "allocated" means

Monthly allocation per category is derived at runtime — no new database column needed:

```
allocated = school_month_days × daily_limit_for_category
```

- `school_month_days` comes from `config('sunbites.school_months')[$month]['days']`
- `daily_limit_for_category` comes from `BranchSubscriptionConfig` for the student's branch

### What "used" means

```
used = SUM of quantities from subscription order items
       WHERE order.student_id = student.id
         AND order.payment_method = 'subscription'
         AND order.status = 'completed'
         AND order.created_at falls within the school month's calendar range
       GROUP BY menu_item.category
```

Voided orders (`status != completed`) are automatically excluded — no drift risk.

### Categories tracked

All four `MenuCategory` values: `meal`, `snack`, `drink`, `extra`.

### School month calendar range

Use `SchoolMonth::toMonthNumber()` to convert the enum to a calendar month number, then filter with `whereYear` + `whereMonth`. No date hardcoding.

### Response shape (returned from all affected endpoints)

```json
"subscription_monthly_status": {
  "month": "july",
  "year": 2025,
  "categories": {
    "meal":  { "allocated": 22, "used": 10, "remaining": 12 },
    "snack": { "allocated": 22, "used": 8,  "remaining": 14 },
    "drink": { "allocated": 22, "used": 0,  "remaining": 22 },
    "extra": { "allocated": 22, "used": 5,  "remaining": 17 }
  }
}
```

`subscription_monthly_status` is `null` for non-subscription students.

---

## Implementation — Laravel API

### New method on `Student` model

```php
public function monthlySubscriptionUsageByCategory(SchoolMonth $month, int $year): Collection
```

- Queries `order_items` joined via `orders` filtered by student, payment method, status, year, and calendar month.
- Groups by `pos_menu_items.category`, sums `quantity`.
- Returns a `Collection` keyed by category value, values are quantity used.

### Helper method on `Student` model

```php
public function currentMonthSubscriptionStatus(): ?array
```

- Determines the current `SchoolMonth` via `SchoolMonth::fromMonthNumber(now()->month)`.
- Returns `null` if the current calendar month is not a school month, or if the student is not a subscription student.
- Calls `monthlySubscriptionUsageByCategory()` and `BranchSubscriptionConfig::forBranch()` to build the full response array.

### Endpoint changes

| Endpoint | Change |
|---|---|
| `GET /api/v1/pos/students/{student}` | Add `subscription_monthly_status` via `currentMonthSubscriptionStatus()` in `StudentLookupController::fullStudentData()` |
| `GET /api/v1/students/{student}` | Add `subscription_monthly_status` via `currentMonthSubscriptionStatus()` in `StudentController::show()` |
| `GET /api/v1/portal/students` | Add `subscription_monthly_status` per student in `Portal\StudentController::index()` map |
| `GET /api/v1/reports/subscription` | **New endpoint** — see below |

### New endpoint: `GET /api/v1/reports/subscription`

**Controller:** `Kitchen\SubscriptionReportController@index`

**Auth:** `auth:sanctum` + ability `staff` + role `admin|manager|supervisor`

**Query parameters:**

| Parameter | Required | Description |
|---|---|---|
| `month` | Yes | School month slug: `june`, `july`, … `march` |
| `year` | Yes | Calendar year, e.g. `2025` |

**Response:** Paginated list (20 per page) of subscription students for the active branch with their monthly usage for the requested month/year. Each row includes:

**Performance note:** The report controller must NOT call `monthlySubscriptionUsageByCategory()` per student (N+1 problem). Instead it runs a single aggregated query across all students in the page — grouping by `student_id` and `category` — then maps results into the per-student shape.

```json
{
  "id": 1,
  "full_name": "Juan dela Cruz",
  "student_number": "2025-001",
  "grade_level": "Grade 3",
  "section": "Sampaguita",
  "payment_status": "paid",
  "subscription_monthly_status": {
    "month": "july",
    "year": 2025,
    "categories": {
      "meal":  { "allocated": 22, "used": 10, "remaining": 12 },
      "snack": { "allocated": 22, "used": 8,  "remaining": 14 },
      "drink": { "allocated": 22, "used": 0,  "remaining": 22 },
      "extra": { "allocated": 22, "used": 0,  "remaining": 22 }
    }
  }
}
```

`payment_status` is pulled from the student's `StudentMonthlyPayment` for the requested month/year (`paid` | `unpaid` | `not_recorded`).

**Validation:** Return 422 if `month` is not a valid `SchoolMonth` value or `year` is not a valid integer.

**Route file:** `routes/kitchen-api.php` under the `reports` group.

---

## Implementation — POS (`~/sunbites-pos`)

### Type updates

Add `subscription_monthly_status` to the `Student` type in `types/student.ts`:

```typescript
interface SubscriptionCategoryStatus {
  allocated: number;
  used: number;
  remaining: number;
}

interface SubscriptionMonthlyStatus {
  month: string;
  year: number;
  categories: Record<string, SubscriptionCategoryStatus>;
}
```

### Cart panel update (`components/pos/cart-panel.tsx`)

When `paymentMethod === "subscription"` and `student?.subscription_monthly_status` is present, add a **Monthly Usage** section directly below the existing **Daily Usage** section.

Layout — two side-by-side panels within the subscription block:

```
┌─────────────────────────────────────────┐
│ Daily Usage (Today)                     │
│  meal   1 / 1    snack  0 / 1           │
├─────────────────────────────────────────┤
│ Monthly Usage (July)                    │
│  meal  10 / 22   12 left                │
│  snack  8 / 22   14 left                │
│  drink  0 / 22   22 left                │
│  extra  0 / 22   22 left                │
└─────────────────────────────────────────┘
```

Color rules for the remaining count:
- `remaining === 0` → `text-destructive` (red)
- `remaining <= 5` → `text-amber-600` (yellow/amber)
- Otherwise → `text-foreground` (default)

Only show categories where `allocated > 0` (categories with a zero daily limit are excluded from display).

### New API service function

Add to `lib/api/reports.ts` (or create if it doesn't exist):

```typescript
export const reportApi = {
  // ... existing functions
  subscriptionUsage: (params: { month: string; year: number; page?: number }) =>
    apiClient.get<PaginatedResponse<SubscriptionReportRow>>("/reports/subscription", { params }),
};
```

### New report page

**Path:** `app/(kitchen)/reports/subscription/page.tsx`
**Path:** `app/(kitchen)/reports/subscription/loading.tsx`

**Filters (top of page):**
- Month dropdown: all 10 school month slugs with labels (`June`, `July`, … `March`). Default: current school month.
- Year input/dropdown. Default: current year.

**Table columns:**

| Column | Notes |
|---|---|
| Student | Full name + student number |
| Grade | Grade level + section |
| Payment | `Paid` (green badge) / `Unpaid` (red badge) / `Not Recorded` (muted) |
| Meal | `used / allocated` |
| Snack | `used / allocated` |
| Drink | `used / allocated` |
| Extra | `used / allocated` |
| Total Remaining | Sum of remaining across all categories |

Color coding for each category cell and total remaining:
- `remaining === 0` → red text
- `remaining <= 5` → amber text
- Otherwise → default

**Pagination:** Standard page controls matching existing report pages.

**Empty state:** "No subscription students found for this month."

**Navigation:** Add "Subscription" entry to the reports sidebar/nav, consistent with existing report nav items.

---

## Implementation — Parent Portal (`~/sunbites-portal`)

### Type updates

Add the same `SubscriptionMonthlyStatus` type to `types/student.ts` in the portal.

### Student list / student card

The portal `GET /api/v1/portal/students` response already returns all linked students in a single call. After the API change, each student object includes `subscription_monthly_status`.

On the student card or student detail view (whichever the portal currently uses), add a **Meals This Month** section for subscription students:

```
Meals This Month — July 2025
Meal    10 / 22   (12 remaining)
Snack    8 / 22   (14 remaining)
```

Only shown when `student_type === "subscription"` and `subscription_monthly_status !== null`.

---

## Testing

### Backend (PHPUnit)

New test file: `tests/Feature/Kitchen/SubscriptionReportTest.php`

Cover:
- Report returns correct `used`, `allocated`, `remaining` for each category
- Report excludes voided orders from `used` count
- Report correctly returns `payment_status` as `paid`, `unpaid`, or `not_recorded`
- Report filters by the requested month/year (orders from other months are excluded)
- Report is branch-scoped (students from other branches are excluded)
- 401 for unauthenticated requests
- 403 for staff without `admin|manager|supervisor` role
- 422 for invalid `month` or `year` parameters
- Non-subscription students are excluded from the report

Existing test files to update:
- `tests/Feature/Kitchen/StudentLookupTest.php` — assert `subscription_monthly_status` present in response for subscription students, `null` for non-subscription
- `tests/Feature/Kitchen/StudentTest.php` — same assertion on `GET /api/v1/students/{student}`
- `tests/Feature/Portal/StudentTest.php` — assert `subscription_monthly_status` present for subscription students in portal list

### Frontend (Jest + RTL)

- Cart panel shows Monthly Usage section when payment method is subscription and `subscription_monthly_status` is present
- Cart panel hides Monthly Usage section for non-subscription payment methods
- Color coding applies correctly (red at 0, amber at ≤5, default otherwise)
- Subscription report page renders table rows with correct values from MSW handler
- Subscription report page shows empty state when no students returned
- Subscription report page filters by month/year and re-fetches on change

---

## What this does NOT change

- Checkout enforcement logic (`CheckoutController`) — daily limits are unchanged
- `StudentMonthlyPayment` schema — no new columns
- Voided orders — already excluded by `status = completed` filter
- Non-subscription students — `subscription_monthly_status` is `null`, no computation done
