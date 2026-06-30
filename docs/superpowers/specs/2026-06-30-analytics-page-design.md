# Design: Analytics Page

**Date:** 2026-06-30
**Apps affected:** `~/sunbites-pos` (POS/admin app), `~/sunbites-api` (new API endpoint)
**Audience:** Managers and Admins only

---

## Overview

A new scrollable analytics page at `/reports/analytics` inside the POS app. It is the first item in the reports navigation submenu, acting as the visual command center above the granular table-based reports.

The page gives managers a full-picture view of branch performance across six domains — Sales, Students, Subscription Billing, Wallet, Credits, and Inventory — for any school-month range they choose. All charts use **Recharts**.

---

## Navigation Placement

The reports nav submenu order:

```
Reports
  Analytics          ← new (first)
  Sales
  Students
  Wallet
  Inventory
  Daily Summary
  Billing
  Credits
  Subscription
  Activity Log
```

Access is gated to `admin|manager` roles only. Regular staff cannot see this nav item or access the route directly.

---

## Date Range Filter

A sticky filter bar fixed at the top of the page, always visible while scrolling.

### Controls

| Control | Detail |
|---|---|
| Preset: **This School Year** | June current SY → March end of SY |
| Preset: **Last School Year** | June/March of previous SY |
| Preset: **Custom** | Activates the month+year range pickers |
| **From** | `<month select> <year select>` |
| **To** | `<month select> <year select>` |
| **Apply** button | Triggers a fresh API fetch with the selected range |

### Default on load

"This School Year" preset is active. The from/to values are derived client-side:

- **From:** June of the current school year. If today's month is June–December, `from_year = current year`. If January–May, `from_year = current year - 1`.
- **To:** The current calendar month and year, capped at March (the SY end). If today is past March of the SY end year, `to_month = March`.

### Query params sent to API

```
from_month=June&from_year=2025&to_month=March&to_year=2026
```

Month values use the `SchoolMonth` enum string values already used throughout the API.

---

## API Endpoint

### `GET /api/v1/reports/analytics`

**Auth:** `auth:sanctum` + ability `staff` + role `admin|manager`

**Query parameters:**

| Param | Type | Required | Description |
|---|---|---|---|
| `from_month` | `SchoolMonth` enum | yes | e.g. `June` |
| `from_year` | integer (2020–2099) | yes | e.g. `2025` |
| `to_month` | `SchoolMonth` enum | yes | e.g. `March` |
| `to_year` | integer (2020–2099) | yes | e.g. `2026` |

**Controller:** `Kitchen\AnalyticsController@index`

**Response shape:**

```json
{
  "period": {
    "from_month": "June",
    "from_year": 2025,
    "to_month": "March",
    "to_year": 2026,
    "months": ["June 2025", "July 2025", ..., "March 2026"]
  },
  "sales": {
    "kpis": {
      "total_revenue": 505700.00,
      "total_orders": 4294,
      "avg_order_value": 117.76,
      "total_discounts": 12340.00,
      "net_revenue": 493360.00
    },
    "revenue_trend": [
      { "label": "June 2025", "revenue": 48500.00, "orders": 412 }
    ],
    "payment_methods": [
      { "method": "wallet", "count": 2920, "amount": 343880.00 },
      { "method": "cash",   "count": 1074, "amount": 126370.00 },
      { "method": "other",  "count": 300,  "amount": 35330.00 }
    ],
    "top_items": [
      { "name": "Chicken Rice", "quantity": 1245 }
    ]
  },
  "students": {
    "kpis": {
      "total_students": 180,
      "enrolled": 167,
      "subscription_count": 142,
      "non_subscription_count": 38,
      "new_enrollments": 23,
      "subscription_upgrades": 8,
      "subscription_downgrades": 5
    },
    "by_grade": [
      { "grade_level": "Grade 1", "enrolled": 28 }
    ],
    "switch_trend": [
      { "label": "June 2025", "upgrades": 2, "downgrades": 0 }
    ]
  },
  "billing": {
    "kpis": {
      "total_collected": 858750.00,
      "total_outstanding": 44375.00,
      "total_void": 9375.00,
      "total_subscribers": 142,
      "collection_rate": 95.1,
      "discrepancy_count": 34,
      "fully_paid_count": 108
    },
    "monthly_trend": [
      {
        "label": "June 2025",
        "paid_count": 138,
        "unpaid_count": 4,
        "void_count": 2,
        "paid_amount": 86250.00,
        "unpaid_amount": 2500.00,
        "void_amount": 1250.00,
        "collection_rate": 97.2
      }
    ],
    "by_grade": [
      {
        "grade_level": "Grade 1",
        "paid": 24,
        "unpaid": 2,
        "void": 2
      }
    ]
  },
  "wallet": {
    "kpis": {
      "total_credits": 374300.00,
      "total_debits": 306100.00,
      "net_flow": 68200.00,
      "low_balance_count": 12
    },
    "monthly_trend": [
      { "label": "June 2025", "credits": 35200.00, "debits": 29400.00, "net": 5800.00 }
    ]
  },
  "credits": {
    "kpis": {
      "total_credit_balance": 4280.00,
      "students_on_credit": 18,
      "avg_credit_per_student": 237.78,
      "near_limit_count": 4
    },
    "distribution": [
      { "range": "₱1–₱100",   "count": 8 },
      { "range": "₱101–₱200", "count": 6 },
      { "range": "₱201–₱300", "count": 4 }
    ],
    "peak_hours": [
      { "hour": "6am",  "avg_orders": 18 },
      { "hour": "7am",  "avg_orders": 67 },
      { "hour": "8am",  "avg_orders": 124 },
      { "hour": "9am",  "avg_orders": 156 },
      { "hour": "10am", "avg_orders": 189 },
      { "hour": "11am", "avg_orders": 243 },
      { "hour": "12pm", "avg_orders": 142 }
    ]
  },
  "inventory": {
    "kpis": {
      "total_items": 24,
      "low_stock_count": 3,
      "out_of_stock_count": 1,
      "most_restocked_item": "Cooking Oil",
      "most_restocked_count": 18
    },
    "top_consumed": [
      { "name": "Rice", "quantity": 890, "unit": "kg" }
    ],
    "stock_events": [
      { "label": "June 2025", "low_events": 2, "out_events": 0 }
    ]
  }
}
```

### Data sources per section

| Section | Primary tables / models |
|---|---|
| Sales | `orders`, `order_items` — `status = completed`, filtered by `branch_id` and date range |
| Students | `students` — counts, grade grouping; `student_type` change events from `activity_log` |
| Billing | `student_monthly_payments` — grouped by `school_month`+`year` and `status` (paid/unpaid/void) |
| Wallet | `transactions` joined to `wallets` where `holder_type = Student` for the branch |
| Credits | `students.credit_balance > 0`; `orders.created_at` hour extraction for peak hours |
| Inventory | `inventory_logs` for consumed quantities; `inventory_items` for current stock levels |

### Collection rate formula

`collection_rate = paid_amount / (paid_amount + unpaid_amount) * 100`

Void amounts are **excluded** from both numerator and denominator — a voided payment removes the obligation entirely, so it should not reduce the collection rate.

### Subscription switch trend

Upgrades and downgrades are read from the `activity_log` table. The log records `student_type` attribute changes (spatie/laravel-activitylog tracks `attribute_changes`). The query groups these by school month:

- **Upgrade**: `old.student_type = non_subscription` → `new.student_type = subscription`
- **Downgrade**: `old.student_type = subscription` → `new.student_type = non_subscription`

---

## Frontend — Page Structure (`~/sunbites-pos`)

### File locations

```
app/(kitchen)/reports/analytics/
  page.tsx          ← Server Component shell; passes period to client
  loading.tsx       ← Skeleton for all 6 sections
lib/api/analytics.ts        ← new API service module
hooks/use-analytics.ts      ← TanStack Query hook
components/reports/analytics/
  analytics-filter-bar.tsx  ← sticky filter with presets + month/year pickers
  section-sales.tsx
  section-students.tsx
  section-billing.tsx
  section-wallet.tsx
  section-credits.tsx
  section-inventory.tsx
  kpi-card.tsx              ← shared KPI card component
  section-wrapper.tsx       ← shared section shell (stripe, title, rule)
```

### Data fetching

One `useQuery` call fetches the entire analytics payload. All six sections read from the same cached response — no per-section queries.

```typescript
// hooks/use-analytics.ts
export function useAnalytics(params: AnalyticsParams) {
  return useQuery({
    queryKey: ['analytics', params],
    queryFn: () => analyticsApi.index(params),
    staleTime: 5 * 60 * 1000,
  });
}
```

The filter bar holds `params` in local state (`useState`). Clicking **Apply** updates params, which changes the query key and triggers a refetch.

### Chart library

All charts use **Recharts**. Chart component mapping:

| Chart | Recharts component |
|---|---|
| Revenue & Orders trend | `AreaChart` + `Bar` (composed) |
| Payment method donut | `PieChart` with `innerRadius` |
| Top items | `BarChart` horizontal (`layout="vertical"`) |
| Student type donut | `PieChart` with `innerRadius` |
| Enrollment by grade | `BarChart` |
| Subscription switch rate | `BarChart` with positive/negative values (diverging) |
| Paid/Unpaid/Void per month | `BarChart` stacked (`stackId`) |
| Collection rate trend | `AreaChart` with reference line at 95% |
| Paid/Unpaid/Void per grade | `BarChart` grouped |
| Wallet credits vs debits | `BarChart` stacked |
| Net wallet flow | `AreaChart` |
| Credit distribution | `BarChart` |
| Peak hours | `BarChart` with `Cell` for peak highlight |
| Top consumed ingredients | `BarChart` horizontal |
| Low/out-of-stock events | `BarChart` stacked |

### Color tokens for charts

| Series | Color |
|---|---|
| Paid / Revenue / Credits | `#0A7160` (primary teal) |
| Unpaid / Debits / Downgrade | `#C84B12` (accent orange) |
| Void | `#94A3B8` (slate) |
| Orders / Wallet credits | `#2F5FA8` (blue) |
| Net flow | `#7140CC` (purple) |
| Inventory | `#0E7490` (cyan) |

---

## Section Detail

### Section 1 — Sales & Revenue

**KPI cards (5):** Total Revenue · Total Orders · Avg Order Value · Total Discounts · Net Revenue

**Charts:**
- Monthly Revenue & Orders — `ComposedChart` with `Area` (revenue) + `Bar` (orders) on dual Y-axes
- Payment Method Breakdown — `PieChart` donut (wallet / cash / other) by count
- Top 10 Selling Items — horizontal `BarChart` sorted descending by quantity

---

### Section 2 — Students & Enrollment

**KPI cards (7):** Total Students · Enrolled · Subscription · Non-Subscription · New Enrollments · Upgrades · Downgrades

**Charts:**
- Student Type Split — `PieChart` donut (subscription vs non-subscription)
- Enrolled Students by Grade Level — vertical `BarChart`
- Subscription Switch Rate — diverging `BarChart`; upgrades rendered as positive values (teal), downgrades as negative (orange); a `ReferenceLine` at y=0 serves as the baseline

---

### Section 3 — Subscription & Billing

**KPI cards (5):** Total Collected · Total Outstanding · Total Subscribers · Discrepancy Count · Fully Paid Students

**Charts:**
- Paid vs Unpaid vs Void per School Month — stacked `BarChart` with three `stackId="billing"` bars (paid bottom, unpaid middle, void top)
- Collection Rate Trend — `AreaChart` with a dashed `ReferenceLine` at `y={95}` labelled "95% target"
- Paid vs Unpaid vs Void per Grade Level — grouped `BarChart` (three bars per grade, not stacked)

**Table:** Monthly Collection Breakdown — columns: School Month · Subscribers · Paid · Unpaid · Void · Amount Collected · Outstanding · Void Amount · Collection Rate (with inline progress bar)

---

### Section 4 — Wallet Activity

**KPI cards (4):** Total Credits · Total Debits · Net Wallet Flow · Low Balance Students (`< ₱100`, current live count)

**Charts:**
- Credits vs Debits per Month — stacked `BarChart` (credits purple, debits orange)
- Net Wallet Flow — `AreaChart` (purple fill)

---

### Section 5 — Credits & Debt

**KPI cards (4):** Total Credit Balance · Students on Credit · Avg Credit Per Student · Near Limit Count

**Charts:**
- Credit Balance Distribution — vertical `BarChart` (3 ranges: ₱1–100, ₱101–200, ₱201–300)
- Peak Ordering Hours — vertical `BarChart` with `Cell` highlighting the peak hour bar in primary teal; all others in a muted teal tint

---

### Section 6 — Inventory

**KPI cards (4):** Items Tracked · Currently Low Stock · Currently Out of Stock · Most Restocked Item

**Charts:**
- Top 10 Most Consumed Ingredients — horizontal `BarChart` with unit labels (`kg`, `L`, `pcs`) appended to value labels
- Low & Out-of-Stock Events per Month — stacked `BarChart` (low stock cyan bottom, out-of-stock orange top)

---

## Role Gating

The route and the API endpoint both enforce `admin|manager`. In the POS app:

- The reports nav conditionally renders the Analytics item only when the authenticated user has role `admin` or `manager`
- The page itself redirects to `/reports/sales` if a non-admin/manager somehow reaches it directly

The API uses the existing `role:admin,manager` middleware already applied on other report endpoints.

---

## Error & Loading States

| State | Behaviour |
|---|---|
| Loading | Skeleton cards for all 6 sections shown via `loading.tsx` |
| API error | Each section shows an `ErrorMessage` component; other sections are unaffected |
| Empty range | KPI values show `0` / `₱0`; charts render empty axes (no blank screen) |
| No data for a chart | Chart area shows a centred "No data for this period" label |

---

## Testing

### Backend

- `AnalyticsController` returns 200 with correct shape for a valid date range
- Returns 403 for staff without `admin|manager` role
- Returns 422 for invalid `from_month` / missing required params
- `billing.monthly_trend` correctly splits paid / unpaid / void counts and amounts
- `students.switch_trend` correctly reads upgrades and downgrades from activity log
- Branch scoping: response contains only the requesting branch's data

### Frontend

- Filter bar: selecting a preset updates the from/to displays; clicking Apply refetches
- Custom range: month/year pickers become active when "Custom" preset is selected
- Charts render with sample MSW fixture without throwing
- Error state: API 500 shows `ErrorMessage` in the affected section, not a full page crash
- Role gate: manager sees the Analytics nav item; regular staff does not
