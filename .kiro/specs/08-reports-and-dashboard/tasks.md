# Tasks 08 — Reports & Dashboard

## 1. Database
- [x] Migration: `staff_daily_statuses` table — `user_id` (FK), `branch_id` (FK), `date`, `status` (enum: Working/Off/OnLeave/Emergency/OnBreak — TitleCase), `updated_by` (nullable FK → users), timestamps; UNIQUE KEY on `(user_id, date)`

## 2. Dashboard

### 2.1 Backend
- [x] `DashboardController::index()` — returns all dashboard data for active branch:
  - [x] Stat cards: total students (branch), enrolled count, meals today (completed orders count), revenue today (sum of totals), walk-in orders (student_id = null), wallet payment orders
  - [x] Last 10 orders today with item pill summaries
  - [x] Low stock alerts: `inventory_items` where `quantity <= restock_threshold` or `quantity = 0`
  - [x] Outstanding credit alerts: students where `credit_balance > 0`
  - [x] Top 5 items today by order quantity (from `order_items` joined to today's completed orders)
  - [x] Staff roster: branch users with today's `staff_daily_statuses` (default "Working" if no record)
- [x] `DashboardController::updateStaffStatus()` — upserts `staff_daily_statuses` for user + today; standard authenticated API endpoint (no CSRF needed with Bearer token auth)
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/dashboard`
  - `POST /api/v1/dashboard/staff-status`

### 2.2 Frontend
- [x] Dashboard page at `app/(kitchen)/dashboard/page.tsx`
- [x] Stat cards grid: `grid-cols-2 sm:grid-cols-3 lg:grid-cols-4` — icon, number (`text-3xl font-extrabold` colored per metric), label; loaded via `useQuery`
- [x] Staff Roster widget: employee cards with name, position, status badge, inline `<Select>` dropdown from shadcn/ui; `useMutation` for `POST /api/v1/dashboard/staff-status` on change
- [x] Status badge colors: Working=green, Off=gray, OnLeave=blue, Emergency=red, OnBreak=yellow
- [x] Today's Transactions table: last 10 orders with items as colored pill badges; "View Full History" link → POS page
- [x] Low Stock Alerts widget: item name, qty, threshold, status badge; "Go to Inventory" link
- [x] Outstanding Credit Alerts widget: student name, grade, credit amount; "View Student" link per row; hidden when no credit
- [x] Top Items Today: numbered horizontal list (no chart)

## 3. Sales Report

### 3.1 Backend
- [x] `SalesReportController::index()` — filters: date range (preset + custom), payment method, customer type, cashier; paginated; summary: total revenue, orders, avg value, total discounts, net revenue
- [x] `SalesReportController::export()` — `ReportPolicy::export()` enforced server-side (Admin/Manager only, Supervisor excluded); `SalesReportExport` class
- [x] `SalesReportExport` (`app/Exports/`) — `WithHeadings`, `WithMapping`, `WithStyles`; summary row; branch name + date range in header; filename: `sales-report-{branch}-{from}-{to}.xlsx`
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/reports/sales`
  - `GET /api/v1/reports/sales/export` — role:admin,manager

### 3.2 Frontend
- [x] Sales report page at `app/(kitchen)/reports/sales/page.tsx`
- [x] Date range pill tabs: Today / This Week / This Month / Last Month / Custom Range; `useQuery` with filter params
- [x] Summary cards (same stat-card style as dashboard)
- [x] Transaction table: receipt#, date/time, cashier, customer, items, payment badge, discount, total
- [x] Export button: visible to Admin/Manager only (hidden for Supervisor); triggers `GET /api/v1/reports/sales/export`

## 4. Student Report

### 4.1 Backend
- [x] `StudentReportController::index()` — filters: enrollment status, grade level, student type; summary: total enrolled, by-grade counts, by-status counts
- [x] `StudentReportController::export()` — `ReportPolicy::export()` enforced; `StudentsExport` uses explicit field allowlist (NEVER include `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`); allowed fields: `student_number`, `first_name`, `last_name`, `grade_level`, `section`, `enrollment_status`, `enrollment_date`, `student_type`, `wallet_balance`, `total_spent`, primary contact name and phone
- [x] `StudentsExport` class; filename: `students-{branch}-{date}.xlsx`
- [x] Routes: `GET /api/v1/reports/students`, `GET /api/v1/reports/students/export` — export: role:admin,manager

### 4.2 Frontend
- [x] Student report page at `app/(kitchen)/reports/students/page.tsx`
- [x] Summary section: plain text counts (no charts); by-grade and by-status breakdowns via `useQuery`
- [x] Table: name, student number, grade, section, status badge, wallet balance, total spent

## 5. Wallet Report

### 5.1 Backend
- [x] `WalletReportController::index()` — Admin/Manager only (Supervisor excluded); date range filter; summary: total credits, total debits, net movement, students below ₱100
- [x] `WalletReportExport` class; filename: `wallet-report-{branch}-{date}.xlsx`
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager`:
  - `GET /api/v1/reports/wallet`
  - `GET /api/v1/reports/wallet/export`

### 5.2 Frontend
- [x] Wallet report page at `app/(kitchen)/reports/wallet/page.tsx`
- [x] Low balance warning banner (students below ₱100): `bg-yellow-50 border-yellow-300`
- [x] Table: student name, grade, balance, outstanding credit, total credited, total debited, last transaction date

## 6. Inventory Report

### 6.1 Backend
- [x] `InventoryReportController::index()` — shows all items; summary: out-of-stock count, below-threshold count
- [x] `InventoryReportExport` class; filename: `inventory-report-{branch}-{date}.xlsx`
- [x] Routes: `GET /api/v1/reports/inventory`, `GET /api/v1/reports/inventory/export` — export: role:admin,manager

### 6.2 Frontend
- [x] Inventory report page at `app/(kitchen)/reports/inventory/page.tsx`
- [x] Summary: out-of-stock and below-threshold counts
- [x] Table: item name, stock, unit, threshold, status badge, last restocked date

## 7. Daily Sales Summary

### 7.1 Backend
- [x] `DailySummaryController::index()` — Admin/Manager only; date filter (default today); returns: total orders, breakdown by payment method (count + amount), total discounts, total revenue, per-cashier breakdown, items sold by quantity
- [x] Route: `GET /api/v1/reports/daily-summary` — role:admin,manager

### 7.2 Frontend
- [x] Daily summary page at `app/(kitchen)/reports/daily-summary/page.tsx`
- [x] Date picker (default today); `[🖨️ Print Summary]` button via `window.print()`
- [x] Printable layout: `@media print` hides sidebar, topbar, date picker, print button — only summary content prints

## 8. Activity Log Viewer

### 8.1 Backend
- [x] `ActivityLogController::index()` — Admin/Manager only (Supervisor excluded); filters: date range, user, log_name/category, free-text search in description; paginated 25/page, newest first; branch-scoped
- [x] Route: `GET /api/v1/reports/activity` — role:admin,manager

### 8.2 Frontend
- [x] Activity log page at `app/(kitchen)/reports/activity/page.tsx`
- [x] Date range preset tabs + user dropdown + category dropdown + free-text search; `useQuery` with params
- [x] Table: date/time, user (or "System"), action description, category badge (color per log_name), subject with link to detail
- [x] Expandable row (click) — shows `properties` as clean key-value list (not raw JSON)
- [x] Category badge colors: Auth=blue, POS=green, Students=orange, Wallet=purple, Payments=yellow, Menu=pink, Inventory=gray, Users=red
- [x] No delete/edit actions — read-only, immutable

## 9. Subscription Billing Report

### 9.1 Backend
- [x] `BillingReportController::index()` — Admin/Manager/Supervisor; filters: year, school_month, status (paid/unpaid/all), grade_level; joins `student_monthly_payments` with `students`; branch-scoped via student's branch_id; sorted unpaid-first then student name; paginated 50/page; summary: total subscribers, total collected, total outstanding, collection rate %
- [x] `BillingReportController::export()` — `ReportPolicy::export()` enforced (Admin/Manager only); `BillingReportExport` class
- [x] `BillingReportExport` (`app/Exports/`) — `WithHeadings`, `WithMapping`, `WithStyles`; summary totals row at bottom; branch name + month/year in header; filename: `billing-report-{branch}-{month}-{year}.xlsx`
- [x] Routes under `auth:sanctum` + `ability:staff`:
  - `GET /api/v1/reports/billing` — role:admin,manager,supervisor
  - `GET /api/v1/reports/billing/export` — role:admin,manager

### 9.2 Frontend
- [x] Billing report page at `app/(kitchen)/reports/billing/page.tsx`
- [x] Filters: year dropdown, school month dropdown, status dropdown (All/Paid/Unpaid), grade level dropdown; `useQuery` with params
- [x] Summary cards: total subscribers, collected (₱), outstanding (₱), collection rate (%)
- [x] Table: student name, student number, grade, section, month/year, amount due, status badge (Paid=green/Unpaid=red), paid-on date, recorded-by; unpaid rows listed first
- [x] `loading.tsx` skeleton for the billing report page
- [x] Export button: Admin/Manager only — hidden for Supervisor

## 10. Credit Collection Report

### 10.1 Backend
- [x] `CreditReportController::index()` — Admin/Manager only (Supervisor excluded); filters: date range (preset + custom), type (all/charged/settled/voided), student free-text search; paginated 25/page, newest first; branch-scoped; summary: total charged, total settled, total voided, net outstanding
- [x] Route under `auth:sanctum` + `ability:staff` + `role:admin,manager`:
  - `GET /api/v1/reports/credits`

### 10.2 Frontend
- [x] Credit report page at `app/(kitchen)/reports/credits/page.tsx`
- [x] Date range preset tabs + type dropdown + student free-text search; `useQuery` with params
- [x] Summary cards: total charged, settled, voided, net outstanding
- [x] Table: date/time, student name + number, grade, type badge (Charged=red/Settled=green/Voided=muted), amount, order # (linked if present), notes, staff who performed it
- [x] `loading.tsx` skeleton for the credit report page
- [x] No export button, no delete/edit actions — read-only, immutable

## 11. Export Security
- [x] `ReportPolicy` — `view()`: admin/manager/supervisor; `export()`: admin/manager only (Supervisor explicitly excluded)
- [x] All export endpoints check `ReportPolicy::export()` server-side — never rely only on frontend button visibility

## 12. Routing & Access
- [x] All report routes under `auth:sanctum` + `ability:staff` + appropriate role middleware
- [x] Wallet report, Daily Summary, Activity Log, Credit Collection: role:admin,manager (Supervisor excluded)
- [x] "Reports" group in `KitchenLayout` sidebar: Dashboard, Sales, Students, Wallet, Inventory, Daily Summary, Billing
- [x] "Activity Log" and "Credit Collection" links visible to Admin/Manager only (hidden for Supervisor)

## 13. Tests
- [x] `DashboardTest` — stat cards return correct counts for active branch; low stock items appear in alerts; credit alerts shown when credit_balance > 0; staff status update creates/upserts `staff_daily_statuses`; supervisor cannot access dashboard (403 test)
- [x] `SalesReportTest` — filters work correctly (date range, payment method, cashier); export restricted to admin/manager (supervisor gets 403 on export endpoint); branch-scoped (other branch orders not returned)
- [x] `StudentReportTest` — summary counts match database state; filters work; export respects allowlist (government ID fields absent from export output)
- [x] `WalletReportTest` — supervisor cannot access wallet report (403); export restricted to admin/manager
- [x] `ActivityLogTest` — supervisor cannot access activity log (403); entries are read-only (no delete endpoint); date/user/category filters narrow results correctly
- [x] `ExportAllowlistTest` — `StudentsExport` headings and data never include `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`
- [x] `BillingReportTest` — filters (year/month/status/grade) narrow results correctly; unpaid-first sort order; summary totals match; export restricted to admin/manager (supervisor gets 403 on export); branch-scoped
- [x] `CreditReportTest` — supervisor cannot access credit report (403); filters (date range, type, student search) work correctly; summary totals match; no delete endpoint exists
