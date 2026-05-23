# Tasks 08 — Reports & Dashboard

## 1. Database
- [ ] Migration: `staff_daily_statuses` table — `user_id` (FK), `branch_id` (FK), `date`, `status` (enum: Working/Off/OnLeave/Emergency/OnBreak — TitleCase), `updated_by` (nullable FK → users), timestamps; UNIQUE KEY on `(user_id, date)`

## 2. Dashboard

### 2.1 Backend
- [ ] `DashboardController::index()` — returns all dashboard data for active branch:
  - [ ] Stat cards: total students (branch), enrolled count, meals today (completed orders count), revenue today (sum of totals), walk-in orders (student_id = null), wallet payment orders
  - [ ] Last 10 orders today with item pill summaries
  - [ ] Low stock alerts: `inventory_items` where `quantity <= restock_threshold` or `quantity = 0`
  - [ ] Outstanding credit alerts: students where `credit_balance > 0`
  - [ ] Top 5 items today by order quantity (from `order_items` joined to today's completed orders)
  - [ ] Staff roster: branch users with today's `staff_daily_statuses` (default "Working" if no record)
- [ ] `DashboardController::updateStaffStatus()` — upserts `staff_daily_statuses` for user + today; standard authenticated API endpoint (no CSRF needed with Bearer token auth)
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/dashboard`
  - `POST /api/v1/dashboard/staff-status`

### 2.2 Frontend
- [ ] Dashboard page at `app/(kitchen)/dashboard/page.tsx`
- [ ] Stat cards grid: `grid-cols-2 sm:grid-cols-3 lg:grid-cols-4` — icon, number (`text-3xl font-extrabold` colored per metric), label; loaded via `useQuery`
- [ ] Staff Roster widget: employee cards with name, position, status badge, inline `<Select>` dropdown from shadcn/ui; `useMutation` for `POST /api/v1/dashboard/staff-status` on change
- [ ] Status badge colors: Working=green, Off=gray, OnLeave=blue, Emergency=red, OnBreak=yellow
- [ ] Today's Transactions table: last 10 orders with items as colored pill badges; "View Full History" link → POS page
- [ ] Low Stock Alerts widget: item name, qty, threshold, status badge; "Go to Inventory" link
- [ ] Outstanding Credit Alerts widget: student name, grade, credit amount; "View Student" link per row; hidden when no credit
- [ ] Top Items Today: numbered horizontal list (no chart)

## 3. Sales Report

### 3.1 Backend
- [ ] `SalesReportController::index()` — filters: date range (preset + custom), payment method, customer type, cashier; paginated; summary: total revenue, orders, avg value, total discounts, net revenue
- [ ] `SalesReportController::export()` — `ReportPolicy::export()` enforced server-side (Admin/Manager only, Supervisor excluded); `SalesReportExport` class
- [ ] `SalesReportExport` (`app/Exports/`) — `WithHeadings`, `WithMapping`, `WithStyles`; summary row; branch name + date range in header; filename: `sales-report-{branch}-{from}-{to}.xlsx`
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/reports/sales`
  - `GET /api/v1/reports/sales/export` — role:admin,manager

### 3.2 Frontend
- [ ] Sales report page at `app/(kitchen)/reports/sales/page.tsx`
- [ ] Date range pill tabs: Today / This Week / This Month / Last Month / Custom Range; `useQuery` with filter params
- [ ] Summary cards (same stat-card style as dashboard)
- [ ] Transaction table: receipt#, date/time, cashier, customer, items, payment badge, discount, total
- [ ] Export button: visible to Admin/Manager only (hidden for Supervisor); triggers `GET /api/v1/reports/sales/export`

## 4. Student Report

### 4.1 Backend
- [ ] `StudentReportController::index()` — filters: enrollment status, grade level, student type; summary: total enrolled, by-grade counts, by-status counts
- [ ] `StudentReportController::export()` — `ReportPolicy::export()` enforced; `StudentsExport` uses explicit field allowlist (NEVER include `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`); allowed fields: `student_number`, `first_name`, `last_name`, `grade_level`, `section`, `enrollment_status`, `enrollment_date`, `student_type`, `wallet_balance`, `total_spent`, primary contact name and phone
- [ ] `StudentsExport` class; filename: `students-{branch}-{date}.xlsx`
- [ ] Routes: `GET /api/v1/reports/students`, `GET /api/v1/reports/students/export` — export: role:admin,manager

### 4.2 Frontend
- [ ] Student report page at `app/(kitchen)/reports/students/page.tsx`
- [ ] Summary section: plain text counts (no charts); by-grade and by-status breakdowns via `useQuery`
- [ ] Table: name, student number, grade, section, status badge, wallet balance, total spent

## 5. Wallet Report

### 5.1 Backend
- [ ] `WalletReportController::index()` — Admin/Manager only (Supervisor excluded); date range filter; summary: total credits, total debits, net movement, students below ₱100
- [ ] `WalletReportExport` class; filename: `wallet-report-{branch}-{date}.xlsx`
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager`:
  - `GET /api/v1/reports/wallet`
  - `GET /api/v1/reports/wallet/export`

### 5.2 Frontend
- [ ] Wallet report page at `app/(kitchen)/reports/wallet/page.tsx`
- [ ] Low balance warning banner (students below ₱100): `bg-yellow-50 border-yellow-300`
- [ ] Table: student name, grade, balance, outstanding credit, total credited, total debited, last transaction date

## 6. Inventory Report

### 6.1 Backend
- [ ] `InventoryReportController::index()` — shows all items; summary: out-of-stock count, below-threshold count
- [ ] `InventoryReportExport` class; filename: `inventory-report-{branch}-{date}.xlsx`
- [ ] Routes: `GET /api/v1/reports/inventory`, `GET /api/v1/reports/inventory/export` — export: role:admin,manager

### 6.2 Frontend
- [ ] Inventory report page at `app/(kitchen)/reports/inventory/page.tsx`
- [ ] Summary: out-of-stock and below-threshold counts
- [ ] Table: item name, stock, unit, threshold, status badge, last restocked date

## 7. Daily Sales Summary

### 7.1 Backend
- [ ] `DailySummaryController::index()` — Admin/Manager only; date filter (default today); returns: total orders, breakdown by payment method (count + amount), total discounts, total revenue, per-cashier breakdown, items sold by quantity
- [ ] Route: `GET /api/v1/reports/daily-summary` — role:admin,manager

### 7.2 Frontend
- [ ] Daily summary page at `app/(kitchen)/reports/daily-summary/page.tsx`
- [ ] Date picker (default today); `[🖨️ Print Summary]` button via `window.print()`
- [ ] Printable layout: `@media print` hides sidebar, topbar, date picker, print button — only summary content prints

## 8. Activity Log Viewer

### 8.1 Backend
- [ ] `ActivityLogController::index()` — Admin/Manager only (Supervisor excluded); filters: date range, user, log_name/category, free-text search in description; paginated 25/page, newest first; branch-scoped
- [ ] Route: `GET /api/v1/reports/activity` — role:admin,manager

### 8.2 Frontend
- [ ] Activity log page at `app/(kitchen)/reports/activity/page.tsx`
- [ ] Date range preset tabs + user dropdown + category dropdown + free-text search; `useQuery` with params
- [ ] Table: date/time, user (or "System"), action description, category badge (color per log_name), subject with link to detail
- [ ] Expandable row (click) — shows `properties` as clean key-value list (not raw JSON)
- [ ] Category badge colors: Auth=blue, POS=green, Students=orange, Wallet=purple, Payments=yellow, Menu=pink, Inventory=gray, Users=red
- [ ] No delete/edit actions — read-only, immutable

## 9. Export Security
- [ ] `ReportPolicy` — `view()`: admin/manager/supervisor; `export()`: admin/manager only (Supervisor explicitly excluded)
- [ ] All export endpoints check `ReportPolicy::export()` server-side — never rely only on frontend button visibility

## 10. Routing & Access
- [ ] All report routes under `auth:sanctum` + `ability:staff` + appropriate role middleware
- [ ] Wallet report, Daily Summary, Activity Log: role:admin,manager (Supervisor excluded)
- [ ] "Reports" group in `KitchenLayout` sidebar: Dashboard, Sales, Students, Wallet, Inventory, Daily Summary
- [ ] "Activity Log" link visible to Admin/Manager only (hidden for Supervisor)

## 11. Tests
- [ ] `DashboardTest` — stat cards return correct counts for active branch; low stock items appear in alerts; credit alerts shown when credit_balance > 0; staff status update creates/upserts `staff_daily_statuses`; supervisor cannot access dashboard (403 test)
- [ ] `SalesReportTest` — filters work correctly (date range, payment method, cashier); export restricted to admin/manager (supervisor gets 403 on export endpoint); branch-scoped (other branch orders not returned)
- [ ] `StudentReportTest` — summary counts match database state; filters work; export respects allowlist (government ID fields absent from export output)
- [ ] `WalletReportTest` — supervisor cannot access wallet report (403); export restricted to admin/manager
- [ ] `ActivityLogTest` — supervisor cannot access activity log (403); entries are read-only (no delete endpoint); date/user/category filters narrow results correctly
- [ ] `ExportAllowlistTest` — `StudentsExport` headings and data never include `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`
