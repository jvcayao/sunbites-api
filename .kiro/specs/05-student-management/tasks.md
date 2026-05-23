# Tasks 05 — Student Management & Enrollment

## 1. Database
- [ ] Migration: `students` table — `branch_id` (FK), `student_number` (string, unique per branch), `first_name`, `last_name`, `grade_level`, `section` (nullable), `birthday`, `photo_path` (nullable), `allergies` (text, nullable), `notes` (text, nullable), `qr_code` (string, unique), `student_type` (enum: subscription/non_subscription), `enrollment_status` (enum: enrolled/paused/unenrolled/banned/graduated), `enrollment_date`, `points` (int, default 0), `total_spent` (decimal, default 0), `credit_balance` (decimal, default 0), `created_at`, `updated_at`, `deleted_at` (soft delete)
- [ ] Migration: `student_contacts` table — `student_id` (FK), `full_name`, `relationship`, `phone`, `address`, `email`, `is_primary` (bool)
- [ ] Migration: `student_monthly_payments` table — `student_id` (FK), `school_month` (enum), `status` (enum: paid/unpaid), `amount` (decimal), `recorded_at` (nullable timestamp), `recorded_by` (FK → users, nullable); UNIQUE KEY on `(student_id, school_month)`
- [ ] Migration: `branch_monthly_amounts` table — `branch_id` (FK), `school_month` (enum), `amount` (decimal); UNIQUE KEY on `(branch_id, school_month)`
- [ ] Migration: `credit_transactions` table — `student_id` (FK), `order_id` (FK → orders, nullable), `type` (enum: Charged/Settled/Voided — TitleCase), `amount` (decimal), `notes` (nullable), `performed_by` (FK → users), `created_at`
- [ ] Factory: `StudentFactory`

## 2. Models
- [ ] `Student` model with `HasBranch` trait, `SoftDeletes`, `LogsActivity` trait
  - [ ] `$logAttributes` allowlist: `first_name`, `last_name`, `grade_level`, `section`, `birthday`, `student_type`, `enrollment_status`, `allergies`, `notes` — exclude `qr_code`, `photo_path`
  - [ ] `$recordEvents = ['created', 'updated', 'deleted']`
  - [ ] `full_name` computed accessor
  - [ ] `contacts()` hasMany relationship
  - [ ] `monthlyPayments()` hasMany relationship
  - [ ] `wallet()` via `bavix/laravel-wallet` `HasWallet` trait
- [ ] `StudentContact` model
- [ ] `StudentMonthlyPayment` model
- [ ] `BranchMonthlyAmount` model
- [ ] `CreditTransaction` model
- [ ] `StudentResource` API resource — excludes `qr_code`, `photo_path` from log-sensitive outputs; government IDs never included

## 3. Enrollment

### 3.1 Backend
- [ ] `EnrollmentController`
  - [ ] `index()` — returns branches and grade level config data for form
  - [ ] `store()`:
    - [ ] Validate all required fields
    - [ ] Validate `student_number` unique per branch (manually entered — school-assigned ID)
    - [ ] Sanitize `allergies` and `notes` via `strip_tags()` before storage
    - [ ] Auto-generate `qr_code`: `'SB-' . Str::random(12)` with collision retry loop
    - [ ] Create `Student` record
    - [ ] Create `StudentContact` records (at least one required)
    - [ ] Create wallet via `bavix/laravel-wallet`
    - [ ] If subscription: seed `StudentMonthlyPayment` for all 10 school months (status=unpaid, amount from `branch_monthly_amounts` or config fallback)
    - [ ] Log `students.enrolled` (properties: student_type, branch)
    - [ ] Response includes: student_number, qr_code (for display/print) — no email/password credentials
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/enrollment`
  - `POST /api/v1/enrollment`

### 3.2 Frontend
- [ ] Enrollment form page at `app/(kitchen)/enrollment/page.tsx`
  - [ ] Branch radio cards (pre-filled with active branch; read-only for non-admin)
  - [ ] Enrollment type radio cards: Subscription / Non-Subscription
  - [ ] Student info section: photo upload (80×80 circle preview), first name, last name, student number (manual input), grade level select, section, birthday, allergies textarea, notes textarea
  - [ ] Contact section: full name, relationship, phone, address, email; "Add another contact" (up to 3)
  - [ ] Permissions & Acknowledgement: two checkboxes, digital signature field, read-only date
  - [ ] Submit via `useMutation` → `POST /api/v1/enrollment`
- [ ] Enrollment success screen (replaces form after submit):
  - [ ] Green border card (`border-green-300 bg-green-50`)
  - [ ] Student name, student type, student number, enrolled date
  - [ ] QR code display (SVG, primary-bordered container, format: `SB-{12 chars}`)
  - [ ] `[🖨️ Print QR Code]` button — browser print, only QR card prints (`@media print`)
  - [ ] `[Enroll Another Student]` button resets form

## 4. Student List

### 4.1 Backend
- [ ] `StudentController::index()` — branch-scoped, paginated; filters: search (name/student_number), grade, status, student type tab; subscription tab adds month+payment_status filter; default sort: last_name then first_name
- [ ] `StudentController::updateStatus()` — changes `enrollment_status`; requires reason for `banned`/`unenrolled`; logs `students.status_changed`
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/students`
  - `PATCH /api/v1/students/{student}/status`

### 4.2 Frontend
- [ ] Student list page at `app/(kitchen)/students/page.tsx`
  - [ ] Filter controls: search, enrollment status dropdown, grade dropdown
  - [ ] Type tabs: `[All]` `[📋 Subscription (N)]` `[🪙 Non-Subscription (N)]`
  - [ ] Subscription tab: additional Month + Paid/Unpaid filter dropdowns
  - [ ] When "All" tab: two sections with colored headers (subscription=orange, non-subscription=purple)
  - [ ] Subscription student card: orange left border, month payment badges Jun–Mar (green=paid, red=unpaid), clickable to toggle, "Record Payment" button
  - [ ] Non-subscription student card: purple left border, wallet-only info box (purple tint), "Load Wallet" button (purple)
  - [ ] Enrollment status badge: color-coded, clickable to open status picker popover
  - [ ] Red "₱X Credit Owed" badge when `credit_balance > 0`
  - [ ] Payment reminder banner (14 days before month end, subscription students only)
  - [ ] Checkbox column for multi-select; floating action bar when ≥1 selected: `[🖨️ Print QR Codes]` `[✕ Clear Selection]`
  - [ ] Batch QR print preview modal: 2 or 4 cards per row selector, `[🖨️ Print All]` button
  - [ ] Print layout (`@media print`): 4 cards per row on A4, no sidebar/topbar/chrome

## 5. Student Detail Page

### 5.1 Backend
- [ ] `StudentController::show()` — returns student with contacts, wallet balance, recent transactions
- [ ] `StudentController::update()` — updates student profile; `strip_tags()` on `notes`/`allergies`; logs `students.updated`
- [ ] `StudentController::destroy()` — soft delete; logs `students.deleted`
- [ ] `StudentController::regenerateQr()` — generates new QR code with collision retry; old code immediately invalidated; logs `students.qr_regenerated` (values not logged)
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/students/{student}`
  - `PUT /api/v1/students/{student}`
  - `DELETE /api/v1/students/{student}`
  - `POST /api/v1/students/{student}/regenerate-qr`

### 5.2 Frontend
- [ ] Student detail page at `app/(kitchen)/students/[id]/page.tsx`
  - [ ] Header: photo, name, grade, enrollment status badge, student type badge, wallet balance, QR code preview
  - [ ] `[⋮ Actions]` dropdown: Change Status, Top Up Wallet, Print QR Code, Remove Student
  - [ ] Tab navigation: Profile | Wallet | Order History | Payment | Logs
  - [ ] **Profile tab**: personal info, contacts, QR code section with `[🖨️ Print QR]` and `[⬇ Download PNG]`; `[↺ Regenerate QR Code]` button (Admin/Manager/Supervisor) via `useMutation`
  - [ ] **Wallet tab**: current balance, top-up button, transaction history table
  - [ ] **Order History tab**: paginated orders via `useQuery`, newest first
  - [ ] **Payment tab**: for subscription — month grid with paid/unpaid toggle via `useMutation`; for non-subscription — "no subscription" message
  - [ ] **Logs tab**: activity_log entries where `subject = Student`; read-only, newest first

## 6. Wallet Top-Up

### 6.1 Backend
- [ ] `WalletController::topUp()` — validates amount, payment_method, reference_number (alphanumeric max 50 chars if provided); `deposit()` via bavix; logs `wallet.topped_up`
- [ ] Route under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `POST /api/v1/students/{student}/wallet/top-up`

### 6.2 Frontend
- [ ] Top-up modal: current balance, amount input, payment method (Cash/GCash/Bank Transfer), reference number field (shown for GCash/Bank), live "New Balance" preview (`text-lg font-extrabold text-green-600`), `useMutation` for submit

## 7. Credit System

### 7.1 Backend
- [ ] `CreditController::settle()` — Admin/Manager only (Supervisor explicitly excluded via `StudentPolicy::settleCredit()`); inserts `credit_transactions` (type=Settled) + atomically sets `credit_balance = 0` with `lockForUpdate()`; logs `wallet.credit_settled`
- [ ] All credit changes go through `credit_transactions` insert + atomic `credit_balance` update in same `DB::transaction()`
- [ ] Route under `auth:sanctum` + `ability:staff` + `role:admin,manager`:
  - `POST /api/v1/students/{student}/credit/settle`

## 8. Subscription Payments

### 8.1 Backend
- [ ] `PaymentController::toggle()` — Admin/Manager toggle month paid/unpaid; logs `payments.recorded`
- [ ] `PaymentController::record()` — explicitly records payment with amount
- [ ] `BranchMonthlyAmountController::update()` — Admin can override monthly amount per branch
- [ ] Routes under `auth:sanctum` + `ability:staff`:
  - `PATCH /api/v1/students/{student}/payments/{month}` — role:admin,manager
  - `PUT /api/v1/branch-monthly-amounts/{month}` — role:admin

## 9. Photo Upload
- [ ] Student photo: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation, stored in `storage/app/private/photos/students/`

## 10. Policies
- [ ] `StudentPolicy` — view/create/update/delete (admin/manager/supervisor); topUp (admin/manager/supervisor); settleCredit (admin/manager — Supervisor explicitly excluded)

## 11. Tests
- [ ] `EnrollmentTest` — subscription enrollment seeds 10 monthly payments; non-subscription enrollment skips; QR uniqueness collision retry; duplicate student_number rejected per branch; cashier cannot enroll (403)
- [ ] `StudentListTest` — filters work; branch-scoped (other branch's students not returned); subscription/non-subscription grouping
- [ ] `StudentDetailTest` — profile update strips tags from notes/allergies; QR regeneration invalidates old code; status change with reason; soft delete retains orders
- [ ] `WalletTopUpTest` — cashier cannot top-up (403); reference number alphanumeric validation; new balance preview matches deposit
- [ ] `CreditSettlementTest` — supervisor cannot settle credit (403); atomic balance update; credit_transactions entry created on each change
