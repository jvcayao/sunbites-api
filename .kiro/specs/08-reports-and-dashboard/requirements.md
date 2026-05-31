# Spec 08 — Reports & Dashboard

## Overview

The kitchen admin dashboard and reporting module gives Admin, Manager, and Supervisor a comprehensive view of branch operations. Reports can be exported to Excel via `maatwebsite/excel`. Cashiers cannot access this area.

---

## Dashboard

Accessible at: `pos.sunbites.com.ph/dashboard`
Roles: Admin, Manager, Supervisor

### Stat Cards (Today's Overview)
| Metric | Description |
|---|---|
| Total Students | Count of enrolled students in active branch |
| Active / Enrolled | Students with `enrollment_status = enrolled` |
| Meals Today | Count of completed orders today |
| Revenue Today | Sum of all completed order totals today |
| Walk-In Orders | Count of orders with `student_id = null` |
| Wallet Transactions | Count of wallet-payment orders today |

### Today's Transactions Table
- Recent orders for the current day (last 10)
- Columns: Time, Receipt #, Customer, Items (pill summary), Payment Method, Total
- Link to full transaction history (POS page → Transaction History tab)

### Low Stock Alerts
- Items from `inventory_items` where `quantity <= restock_threshold` (LOW) or `quantity = 0` (OUT)
- Color-coded: yellow = LOW, red = OUT
- Quick link to inventory management

### Outstanding Credit Alerts
- Students with `credit_balance > 0` shown in a warning widget
- List: student name, grade, outstanding credit amount
- Quick link to student detail page

### Top Items Today
- Horizontal list of top 5 most ordered items today by quantity

### Staff Roster (Admin/Manager/Supervisor)
- Grid of staff cards for today: photo placeholder, name, position/role
- Each card has an inline status dropdown: Working / Off / On Leave / Emergency / On Break
- Status change updates `staff_daily_statuses` record for that user + today's date immediately via `useMutation`
- If no record exists for today, defaults to "Working" for scheduled staff

```
staff_daily_statuses
  id
  user_id     (FK → users)
  branch_id   (FK → branches)
  date        (date)
  status      (enum: Working, Off, OnLeave, Emergency, OnBreak — TitleCase)
  updated_by  (FK → users, nullable)
  created_at, updated_at
  UNIQUE KEY: (user_id, date)
```

Status badge colors:
- Working 🟢 — `bg-green-50 border-green-600`
- Off ⚫ — `bg-muted`
- On Leave 🔵 — `bg-blue-50 border-blue-600`
- Emergency 🔴 — `bg-red-50 border-destructive`
- On Break 🟡 — `bg-yellow-50 border-yellow-600`

---

## Sales Report

Accessible at: `pos.sunbites.com.ph/reports/sales`
Roles: Admin, Manager, Supervisor

### Filters
- **Date Range**: preset buttons (Today, This Week, This Month, Last Month, Custom Range)
- **Payment Method**: All / Cash / GCash / Wallet
- **Customer Type**: All / Registered / Walk-In
- **Cashier**: dropdown of staff

### Summary Panel
- Total Revenue (sum of `total`)
- Total Orders (count)
- Average Order Value
- Total Discounts Given
- Net Revenue (total - discounts)

### Transaction Table
- Receipt # (format: `ANT-2025-001001`), Date/Time, Cashier, Customer, Items, Payment Method, Discount, Total

### Export
- "Export to Excel" button → `SalesReportExport` class (Admin/Manager only)
- Filename: `sales-report-{branch}-{date_from}-{date_to}.xlsx`

---

## Student Report

Accessible at: `pos.sunbites.com.ph/reports/students`
Roles: Admin, Manager, Supervisor

### Filters
- Enrollment status filter
- Grade level filter
- Student type (subscription / non-subscription)

### Summary
- Total students enrolled
- Students by grade level (count per level)
- Students by enrollment status (count per status)

### Table
- Name, Student Number, Grade, Section, Status, Enrollment Date, Wallet Balance, Total Spent

### Export
- Excel export with explicit field allowlist
- Filename: `students-{branch}-{date}.xlsx`

---

## Wallet Report

Accessible at: `pos.sunbites.com.ph/reports/wallet`
Roles: Admin, Manager (Supervisor excluded)

### Summary
- Total wallet credits (top-ups) in date range
- Total wallet debits (purchases) in date range
- Net wallet movement
- Students with balance below ₱100 (warning list)

### Table
- Student name, Grade, Current Balance, Outstanding Credit, Total Credited, Total Debited, Last Transaction Date

### Export
- Excel export
- Filename: `wallet-report-{branch}-{date}.xlsx`

---

## Inventory Report

Accessible at: `pos.sunbites.com.ph/reports/inventory`
Roles: Admin, Manager, Supervisor

### Summary Cards
- Items currently out of stock (count)
- Items below restock threshold (count)
- Items overstocked / above overstock threshold (count, shown only when overstock_threshold is configured on any item)

### Stock Snapshot Table
- Item name, Unit, Current Stock, Restock Threshold, Overstock Threshold, Cost/Unit, Status badge (OUT/LOW/OVER/OK), Last Restocked Date
- Filterable by status (All / OUT / LOW / OVER / OK)
- Archived items excluded

### Log History Table (below snapshot table)
- Full adjustment trail across all inventory items for the selected date range
- **Date Range Filters**: preset buttons (Today, This Week, This Month, Custom Range) — `from` and `to` params sent to backend
- **Additional Filters**: Type (All / Restock / Waste / Manual / Sale), Item (All / specific item)
- Columns: Date/Time, Item Name, Type badge, Change (±qty with unit), Stock After, Reason, Adjusted By, Order # (linked, shown for Sale/Void logs)
- Color coding: Restock rows = green, Sale/Waste rows = red, Manual rows = neutral
- Paginated 25 per page, newest first

### Discrepancy Summary (below Movement History)
- Groups all `Manual` type log entries by item for the selected date range
- Purpose: highlights items with unexplained stock corrections — high manual adjustment activity signals unrecorded loss, miscounting, or theft
- Columns: Item Name, # of Manual Adjustments, Net Units Adjusted (sum of `quantity_change`), Last Adjusted Date
- Net negative = red (potential unrecorded loss); net positive = green (overcorrection)
- Empty state: "No manual adjustments recorded for this period." when no Manual logs exist in range
- Uses the same `from`/`to` date range as the Movement History section above

### Export
- Excel export covers the stock snapshot, filtered log history, AND discrepancy summary
- Filename: `inventory-report-{branch}-{from}-{to}.xlsx`
- Three sheets: "Current Stock" (snapshot), "Movement History" (filtered logs), "Discrepancy" (manual adjustment summary)
- Export restricted to Admin/Manager (Supervisor excluded) — enforced server-side

---

## Daily Sales Summary (End-of-Day)

Accessible at: `pos.sunbites.com.ph/reports/daily-summary`
Roles: Admin, Manager

A printable end-of-day summary for cash reconciliation:
- Total orders for the day
- Cash orders total + count
- GCash orders total + count
- Wallet orders total + count
- Total discounts applied
- Total revenue
- Per-cashier breakdown
- Items sold by quantity

Printed via browser print CSS — no PDF library needed.

---

## Activity Log Viewer

Accessible at: `pos.sunbites.com.ph/reports/activity`
Roles: Admin, Manager only (Supervisor excluded)

Full operational audit trail from the `activity_log` table populated by `spatie/laravel-activitylog`.

### Filters
- Date Range — preset buttons: Today, This Week, This Month, Custom Range
- User — dropdown of all staff
- Log Name / Category — All / Auth / Students / Wallet / Payments / POS / Menu / Inventory / Users
- Search — free-text in `description` field

### Table Columns
| Column | Source |
|---|---|
| Date & Time | `activity_log.created_at` |
| User | `causer.full_name` (or "System" if null) |
| Action | `activity_log.description` |
| Category | `activity_log.log_name` badge |
| Subject | `subject_type` + `subject_id` with a link |
| Details | Expandable `properties` as clean key-value list |

- Paginated 25 per page, newest first
- No export — raw audit logs stay in system only
- No delete, no edit — activity log entries are immutable

---

## Subscription Billing Report

Accessible at: `pos.sunbites.com.ph/reports/billing`
Roles: Admin, Manager, Supervisor

Tracks monthly subscription payment collection across all enrolled subscriber students for the selected school month and year. Data source: `student_monthly_payments` joined with `students`.

### Filters
- **School Year** — year number dropdown (e.g. 2025, 2026)
- **School Month** — dropdown of school months (June → March)
- **Payment Status** — All / Paid / Unpaid
- **Grade Level** — All or specific grade

### Summary Panel
- Total Subscribers (count of subscription students in branch with payment records for the selected month/year)
- Total Collected (sum of `amount` where `status = paid`)
- Total Outstanding (sum of `amount` where `status = unpaid`)
- Collection Rate (% of subscribers who have paid)

### Table
| Column | Source |
|---|---|
| Student Name | `students.full_name` |
| Student Number | `students.student_number` |
| Grade | `students.grade_level` |
| Section | `students.section` |
| Month / Year | `school_month` + `year` |
| Amount Due | `amount` |
| Status | `status` badge — Paid (green) / Unpaid (red) |
| Paid On | `recorded_at` (blank if unpaid) |
| Recorded By | `recorded_by` → user full name (blank if unpaid) |

- Sorted by status (unpaid first), then student name
- Paginated 50 per page

### Export
- `BillingReportExport` class — Admin/Manager only
- Filename: `billing-report-{branch}-{month}-{year}.xlsx`
- Includes summary totals row at bottom

---

## Credit Collection Report

Accessible at: `pos.sunbites.com.ph/reports/credits`
Roles: Admin, Manager only — Supervisor excluded (financial sensitivity)

Full audit trail of credit transactions from the `credit_transactions` table. Shows when credit was charged at checkout, when it was settled, and when it was voided.

### Filters
- **Date Range** — preset buttons (Today, This Week, This Month, Custom Range)
- **Type** — All / Charged / Settled / Voided
- **Student** — free-text search by name or student number

### Summary Panel
- Total Credit Charged in period (sum of `amount` where `type = charged`)
- Total Settled in period (sum of `amount` where `type = settled`)
- Total Voided in period (sum of `amount` where `type = voided`)
- Net Outstanding (charged − settled − voided)

### Table
| Column | Source |
|---|---|
| Date & Time | `credit_transactions.created_at` |
| Student | `student.full_name` + student number |
| Grade | `student.grade_level` |
| Type | `type` badge — Charged (red) / Settled (green) / Voided (muted) |
| Amount | `amount` |
| Order # | linked `order_id` if present (Voided orders show strikethrough) |
| Notes | `notes` |
| Staff | `performed_by` → user full name |

- Sorted newest first
- Paginated 25 per page
- No export — credit transaction audit trail stays in-system only
- No delete or edit — entries are immutable

---

## Export Classes (maatwebsite/excel)

Each report has a dedicated export class under `app/Exports/`:

| Class | File |
|---|---|
| `SalesReportExport` | `app/Exports/SalesReportExport.php` |
| `StudentsExport` | `app/Exports/StudentsExport.php` |
| `WalletReportExport` | `app/Exports/WalletReportExport.php` |
| `InventoryReportExport` | `app/Exports/InventoryReportExport.php` |

Each export must:
- Use `WithHeadings`, `WithMapping`, `WithStyles` interfaces
- Include a summary row (totals) at the bottom
- Format currency columns properly
- Include branch name and date range in a header row

**Export Security Requirements:**
- Export permission enforced **server-side** in `ReportPolicy::export()` — Admin and Manager only. Supervisor explicitly excluded. Never rely only on frontend button visibility.
- `StudentsExport` must use an explicit field **allowlist** — never include government ID fields (`sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`) or internal system fields. Allowed fields: `student_number`, `first_name`, `last_name`, `grade_level`, `section`, `enrollment_status`, `enrollment_date`, `student_type`, `wallet_balance`, `total_spent`, plus primary contact name and phone.

---

## API Routes

All routes under `auth:sanctum` + `ability:staff` middleware.

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/dashboard` | admin, manager, supervisor | All dashboard data |
| POST | `/api/v1/dashboard/staff-status` | admin, manager, supervisor | Update staff daily status |
| GET | `/api/v1/reports/sales` | admin, manager, supervisor | Sales report with filters |
| GET | `/api/v1/reports/sales/export` | admin, manager | Excel export |
| GET | `/api/v1/reports/students` | admin, manager, supervisor | Student report |
| GET | `/api/v1/reports/students/export` | admin, manager | Excel export |
| GET | `/api/v1/reports/wallet` | admin, manager | Wallet report |
| GET | `/api/v1/reports/wallet/export` | admin, manager | Excel export |
| GET | `/api/v1/reports/inventory` | admin, manager, supervisor | Inventory report |
| GET | `/api/v1/reports/inventory/export` | admin, manager | Excel export |
| GET | `/api/v1/reports/daily-summary` | admin, manager | Daily summary |
| GET | `/api/v1/reports/activity` | admin, manager | Activity log viewer |
| GET | `/api/v1/reports/billing` | admin, manager, supervisor | Subscription billing report |
| GET | `/api/v1/reports/billing/export` | admin, manager | Excel export |
| GET | `/api/v1/reports/credits` | admin, manager | Credit collection report |

---

## Requirements

- [ ] `staff_daily_statuses` table with `status` enum (Working/Off/OnLeave/Emergency/OnBreak — TitleCase); UNIQUE(user_id, date)
- [ ] Dashboard stat cards: Total Students, Enrolled, Meals Today, Revenue Today, Walk-In Orders, Wallet Transactions
- [ ] Today's transactions table (last 10 orders, "View Full History" link to POS page)
- [ ] Low stock alerts widget on dashboard
- [ ] Outstanding credit alerts widget on dashboard (students with `credit_balance > 0`)
- [ ] Staff Roster widget: employee cards with inline status dropdown; status updates via `useMutation` → `POST /api/v1/dashboard/staff-status` (regular authenticated API endpoint)
- [ ] Top 5 items today widget (horizontal list, by order quantity)
- [ ] Sales report at `pos.sunbites.com.ph/reports/sales` with all filters and summary panel
- [ ] Student report at `pos.sunbites.com.ph/reports/students` with filters and summary
- [ ] Wallet report at `pos.sunbites.com.ph/reports/wallet` (Admin/Manager only)
- [ ] Inventory report at `pos.sunbites.com.ph/reports/inventory` — stock snapshot + log history with date range filter
- [ ] Inventory report summary cards: OUT count, LOW count, OVER count (when applicable)
- [ ] Inventory report log history table: date range filter (preset + custom), type filter, item filter; paginated 25/page
- [ ] Inventory report export: two-sheet Excel — "Current Stock" snapshot + "Movement History" filtered logs; restricted to Admin/Manager server-side
- [ ] Daily summary at `pos.sunbites.com.ph/reports/daily-summary` (printable, `@media print` hides UI chrome)
- [ ] Excel exports for all 4 reports via maatwebsite/excel
- [ ] Export restricted to Admin and Manager — enforced server-side in `ReportPolicy::export()`, not just frontend button
- [ ] `StudentsExport` uses explicit field allowlist — government ID fields never included
- [ ] All reports strictly branch-scoped
- [ ] Admin can switch branch (via branch switcher) and see reports for either branch
- [ ] Report pages use loading states (`useQuery` with skeleton placeholders) during data fetch
- [ ] `ReportPolicy` — view (admin/manager/supervisor), export (admin/manager)
- [ ] Activity Log Viewer at `pos.sunbites.com.ph/reports/activity` (Admin/Manager only)
- [ ] Activity log filters: date range, user dropdown, log_name/category, free-text search
- [ ] Activity log table: date/time, user, action, category badge, subject link, expandable properties
- [ ] Activity log entries are immutable — no delete or edit actions
- [ ] Subscription Billing report at `pos.sunbites.com.ph/reports/billing` with school year/month/status/grade filters and summary panel
- [ ] Billing table: student name, number, grade, section, month/year, amount due, status badge, paid-on date, recorded-by; sorted unpaid-first
- [ ] `BillingReportExport` — Admin/Manager only; includes summary totals row; filename `billing-report-{branch}-{month}-{year}.xlsx`
- [ ] Credit Collection report at `pos.sunbites.com.ph/reports/credits` — Admin/Manager only (Supervisor excluded)
- [ ] Credit report filters: date range, type (All/Charged/Settled/Voided), student search
- [ ] Credit report table: date/time, student, grade, type badge (Charged=red/Settled=green/Voided=muted), amount, order #, notes, staff
- [ ] Credit report summary: total charged, settled, voided, and net outstanding for the period
- [ ] Credit report entries are immutable — no delete or edit; no export
- [ ] "Reports" group in `KitchenLayout` sidebar: Dashboard, Sales, Students, Wallet, Inventory, Daily Summary, Billing
- [ ] "Activity Log" and "Credits" links visible to Admin/Manager only
