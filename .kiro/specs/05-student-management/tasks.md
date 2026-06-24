# Tasks 05 — Student Management & Enrollment

## 1. Database
- [x] Migration: `students` table — `branch_id` (FK), `student_number` (string, unique per branch), `first_name`, `last_name`, `grade_level`, `section` (nullable), `birthday`, `photo_path` (nullable), `allergies` (text, nullable), `notes` (text, nullable), `qr_code` (string, unique), `student_type` (enum: subscription/non_subscription), `enrollment_status` (enum: enrolled/paused/unenrolled/banned/graduated), `enrollment_date`, `points` (int, default 0), `total_spent` (decimal, default 0), `credit_balance` (decimal, default 0), `created_at`, `updated_at`, `deleted_at` (soft delete)
- [x] Migration: `student_contacts` table — `student_id` (FK), `full_name`, `relationship`, `phone`, `address`, `email`, `is_primary` (bool)
- [x] Migration: `student_monthly_payments` table — `student_id` (FK), `school_month` (enum), `status` (enum: paid/unpaid), `amount` (decimal), `recorded_at` (nullable timestamp), `recorded_by` (FK → users, nullable); UNIQUE KEY on `(student_id, school_month)`
- [x] Migration: `branch_monthly_amounts` table — `branch_id` (FK), `school_month` (enum), `amount` (decimal); UNIQUE KEY on `(branch_id, school_month)`
- [x] Migration: `credit_transactions` table — `student_id` (FK), `order_id` (FK → orders, nullable), `type` (enum: Charged/Settled/Voided — TitleCase), `amount` (decimal), `notes` (nullable), `performed_by` (FK → users), `created_at`
- [x] Factory: `StudentFactory`
- [x] Migration: alter `student_monthly_payments` — add `year` (int, not nullable) column; drop existing UNIQUE KEY on `(student_id, school_month)`; add new UNIQUE KEY on `(student_id, school_month, year)`
- [x] Migration: alter `branch_monthly_amounts` — add `year` (int, not nullable) and `days` (int, not nullable) columns; drop existing UNIQUE KEY on `(branch_id, school_month)`; add new UNIQUE KEY on `(branch_id, school_month, year)`

## 2. Models
- [x] `Student` model with `HasBranch` trait, `SoftDeletes`, `LogsActivity` trait
  - [x] `$logAttributes` allowlist: `first_name`, `last_name`, `grade_level`, `section`, `birthday`, `student_type`, `enrollment_status`, `allergies`, `notes` — exclude `qr_code`, `photo_path`
  - [x] `$recordEvents = ['created', 'updated', 'deleted']`
  - [x] `full_name` computed accessor
  - [x] `contacts()` hasMany relationship
  - [x] `monthlyPayments()` hasMany relationship
  - [x] `wallet()` via `bavix/laravel-wallet` `HasWallet` trait
- [x] `StudentContact` model
- [x] `StudentMonthlyPayment` model
- [x] `BranchMonthlyAmount` model
- [x] `CreditTransaction` model
- [x] `StudentResource` API resource — excludes `qr_code`, `photo_path` from log-sensitive outputs; government IDs never included
- [x] Update `StudentMonthlyPayment` model — add `year` to `$fillable`; update any factory states that seed payment records
- [x] Update `BranchMonthlyAmount` model — add `year` and `days` to `$fillable`; add `amount` computed attribute as `daily_meal_rate × days` (or store directly — keep consistent with controller)

## 3. Enrollment

### 3.1 Backend
- [x] `EnrollmentController`
  - [x] `index()` — returns branches and grade level config data for form
  - [x] `store()`:
    - [x] Validate all required fields
    - [x] Validate `student_number` unique per branch when provided (manually entered — school-assigned ID); **⚠ now nullable — see Task 13 to make it optional**
    - [x] Sanitize `allergies` and `notes` via `strip_tags()` before storage
    - [x] Auto-generate `qr_code`: `'SB-' . Str::random(12)` with collision retry loop
    - [x] Create `Student` record
    - [x] Create `StudentContact` records (at least one required)
    - [x] Create wallet via `bavix/laravel-wallet`
    - [x] If subscription: seed `StudentMonthlyPayment` records (status=unpaid) — currently fixed 10 months
    - [x] Log `students.enrolled` (properties: student_type, branch)
    - [x] Response includes: student_number, qr_code (for display/print) — no email/password credentials
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/enrollment`
  - `POST /api/v1/enrollment`
- [x] Update `EnrollmentController::index()` — also return the subscription period preview data: for each school month, the effective `days` and `amount` for the default range (June [current year] → March [current year + 1]), resolved from `branch_monthly_amounts` or config fallback
- [x] Update `EnrollmentController::store()` validation — add `subscription_start_month` (enum SchoolMonth, required if subscription), `subscription_start_year` (int, 4 digits), `subscription_end_month` (enum SchoolMonth, required if subscription), `subscription_end_year` (int, 4 digits); validate end is not before start
- [x] Update `EnrollmentController::seedMonthlyPayments()` — replace fixed 10-month loop with a range-based loop from start month+year to end month+year; for each month, look up `branch_monthly_amounts` by `(branch_id, school_month, year)`, fall back to `daily_meal_rate × default_days` from config; each row now includes `year` column
- [x] Update `EnrollmentService::seedMonthlyPayments()` — skip months where `BranchMonthlyAmount::resolveAmount()` returns `0`; no `StudentMonthlyPayment` record created for those months
- [x] Update enrollment response to include `subscription_period` (e.g. `"June 2025 – March 2026"`) for the success screen

### 3.2 Frontend
- [x] Enrollment form page at `app/(kitchen)/enrollment/page.tsx`
  - [x] Branch radio cards (pre-filled with active branch; read-only for non-admin)
  - [x] Enrollment type radio cards: Subscription / Non-Subscription
  - [x] Student info section: photo upload (80×80 circle preview), first name, last name, student number (manual input), grade level select, section, birthday, allergies textarea, notes textarea
  - [x] Contact section: full name, relationship, phone, address, email; "Add another contact" (up to 3)
  - [x] Permissions & Acknowledgement: two checkboxes, digital signature field, read-only date
  - [x] Submit via `useMutation` → `POST /api/v1/enrollment`
- [x] Enrollment success screen (replaces form after submit):
  - [x] Green border card (`border-green-300 bg-green-50`)
  - [x] Student name, student type, student number, enrolled date
  - [x] QR code display (SVG, primary-bordered container, format: `SB-{12 chars}`)
  - [x] `[🖨️ Print QR Code]` button — browser print, only QR card prints (`@media print`)
  - [x] `[Enroll Another Student]` button resets form
- [x] Add Subscription Period section to enrollment form — conditionally shown when Subscription type is selected:
  - [x] Start month dropdown (SchoolMonth enum values) + start year input (number, min 2020, max 2099)
  - [x] End month dropdown + end year input; validation: end must not be before start
  - [x] Client-side validation: end must not be before start chronologically; error shown when invalid
  - [x] Live preview: month count preview shown when both start and end are selected
  - [x] Pass `subscription_start_month`, `subscription_start_year`, `subscription_end_month`, `subscription_end_year` in the POST payload for subscription enrollments
- [x] Update enrollment success screen — show "Period: June 2025 – March 2026" row for subscription students

## 4. Student List

### 4.1 Backend
- [x] `StudentController::index()` — branch-scoped, paginated; filters: search (name/student_number), grade, status, student type tab; subscription tab adds month+payment_status filter; default sort: last_name then first_name
- [x] `StudentController::updateStatus()` — changes `enrollment_status`; requires reason for `banned`/`unenrolled`; logs `students.status_changed`
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/students`
  - `PATCH /api/v1/students/{student}/status`
- [x] Update `StudentController::index()` subscription payment filter — add `year` parameter alongside `month` and `payment_status` for the subscription tab filter

### 4.2 Frontend
- [x] Student list page at `app/(kitchen)/students/page.tsx`
  - [x] Filter controls: search, enrollment status dropdown, grade dropdown
  - [x] Type tabs: `[All]` `[📋 Subscription (N)]` `[🪙 Non-Subscription (N)]`
  - [x] Subscription tab: additional Month + Paid/Unpaid filter dropdowns
  - [x] When "All" tab: two sections with colored headers (subscription=orange, non-subscription=purple)
  - [x] Subscription student card: orange left border, month payment badges Jun–Mar (green=paid, red=unpaid), clickable to toggle, "Record Payment" button
  - [x] Non-subscription student card: purple left border, wallet-only info box (purple tint), "Load Wallet" button (purple)
  - [x] Enrollment status badge: color-coded, clickable to open status picker popover
  - [x] Red "₱X Credit Owed" badge when `credit_balance > 0`
  - [x] Payment reminder banner — implemented in Spec 11 (Payment Reminders)
  - [x] Checkbox column for multi-select; floating action bar when ≥1 selected: `[🖨️ Print QR Codes]` `[✕ Clear Selection]`
  - [x] Batch QR print preview modal: 2 or 4 cards per row selector, `[🖨️ Print All]` button
  - [x] Print layout (`@media print`): 4 cards per row on A4, no sidebar/topbar/chrome
- [x] Update subscription tab filters — add Year dropdown alongside Month and Paid/Unpaid dropdowns
- [x] Update month payment badge labels — include abbreviated year (e.g. "Jun '25 ✓" instead of "Jun ✓")

## 5. Student Detail Page

### 5.1 Backend
- [x] `StudentController::show()` — returns student with contacts, wallet balance, recent transactions
- [x] `StudentController::update()` — updates student profile; `strip_tags()` on `notes`/`allergies`; logs `students.updated`
- [x] `StudentController::destroy()` — soft delete; logs `students.deleted`
- [x] `StudentController::regenerateQr()` — generates new QR code with collision retry; old code immediately invalidated; logs `students.qr_regenerated` (values not logged)
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/students/{student}`
  - `PUT /api/v1/students/{student}`
  - `DELETE /api/v1/students/{student}`
  - `POST /api/v1/students/{student}/regenerate-qr`

### 5.2 Frontend
- [x] Student detail page at `app/(kitchen)/students/[id]/page.tsx`
  - [x] Header: photo, name, grade, enrollment status badge, student type badge, wallet balance, QR code preview
  - [x] `[⋮ Actions]` dropdown: Change Status, Top Up Wallet, Print QR Code, Remove Student
  - [x] Tab navigation: Profile | Wallet | Order History | Payment | Logs
  - [x] **Profile tab**: personal info, contacts, QR code section with `[🖨️ Print QR]` and `[⬇ Download PNG]`; `[↺ Regenerate QR Code]` button (Admin/Manager/Supervisor) via `useMutation`
  - [x] **Wallet tab**: current balance, top-up button, transaction history table
  - [x] **Order History tab**: paginated orders via `useQuery`, newest first
  - [x] **Payment tab**: for subscription — month grid with paid/unpaid toggle via `useMutation`; for non-subscription — "no subscription" message
  - [x] **Logs tab**: activity_log entries where `subject = Student`; read-only, newest first
  - [x] **Contacts tab**: each contact row shows a portal account status badge — "Activated" (green) when `email_verified_at` is set, "Pending Activation" (amber) when parent record exists but not yet activated, "No Email" (muted) when contact has no email; badge fetched from linked `parents` table via contact email match
- [x] Update Payment tab — group payment rows by year (year as section header); show "Month Year" (e.g. "June 2025") instead of "Month" in each payment card
- [x] Add `[+ Add Subscription Period]` button to Payment tab (admin/manager/supervisor only) — opens the Add Subscription Period dialog

## 6. Wallet Top-Up

### 6.1 Backend
- [x] `WalletController::topUp()` — validates amount, payment_method, reference_number (alphanumeric max 50 chars if provided); `deposit()` via bavix; logs `wallet.topped_up`
- [x] Route under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `POST /api/v1/students/{student}/wallet/top-up`

### 6.2 Frontend
- [x] Top-up modal: current balance, amount input, payment method (Cash/GCash/Bank Transfer), reference number field (shown for GCash/Bank), live "New Balance" preview (`text-lg font-extrabold text-green-600`), `useMutation` for submit

## 7. Credit System

### 7.1 Backend
- [x] `CreditController::settle()` — Admin/Manager only (Supervisor explicitly excluded via `StudentPolicy::settleCredit()`); inserts `credit_transactions` (type=Settled) + atomically sets `credit_balance = 0` with `lockForUpdate()`; logs `wallet.credit_settled`
- [x] All credit changes go through `credit_transactions` insert + atomic `credit_balance` update in same `DB::transaction()`
- [x] Route under `auth:sanctum` + `ability:staff` + `role:admin,manager`:
  - `POST /api/v1/students/{student}/credit/settle`

## 8. Subscription Payments

### 8.1 Backend
- [x] `PaymentController::index()` — returns student's monthly payments
- [x] `PaymentController::toggle()` — Admin/Manager toggle month paid/unpaid; logs `payments.recorded`
- [x] `PaymentController::record()` — explicitly records payment with amount
- [x] `BranchMonthlyAmountController::update()` — upserts monthly amount per branch (month identified by URL segment)
- [x] Routes under `auth:sanctum` + `ability:staff`:
  - `GET /api/v1/students/{student}/payments` — admin, manager, supervisor
  - `PATCH /api/v1/students/{student}/payments/{payment}` — role: admin, manager
  - `POST /api/v1/students/{student}/payments` — role: admin, manager
  - `PUT /api/v1/branch-monthly-amounts/{month}` — role: admin
- [x] Update `PaymentController::index()` — include `year` in each payment record response; order by year ASC then id ASC
- [x] Update `PaymentController::toggle()` — activity log properties include `year`
- [x] Update `PaymentController::record()` — validate `year` in request; look up payment by `(student_id, school_month, year)` not just school_month
- [x] Add `PaymentController::addRange()` — `POST /api/v1/students/{student}/payments/range`:
  - [x] Validate `subscription_start_month`, `subscription_start_year`, `subscription_end_month`, `subscription_end_year`
  - [x] Skip existing `(student_id, school_month, year)` records; return created count + skipped list
  - [x] Resolve amount per month from `branch_monthly_amounts` or config fallback
  - [x] Create new `student_monthly_payments` rows (status=unpaid) for each month in range
  - [x] Return list of created payment records + skipped list
  - [x] Roles: admin, manager, supervisor
  - [x] Skip months where resolved amount is `0` (i.e. `days = 0`) — same guard as `EnrollmentService::seedMonthlyPayments()`

### 8.2 Branch Monthly Amounts — Full CRUD
- [x] `BranchMonthlyAmountController::index()` — `GET /api/v1/branch-monthly-amounts?year=YYYY`: returns all 10 school months for active branch+year, merges DB config with defaults, includes `is_configured` flag
- [x] `BranchMonthlyAmountController::store()` — `POST /api/v1/branch-monthly-amounts`: upsert on `(branch_id, school_month, year)`, computes `amount = daily_meal_rate × days`
- [x] `BranchMonthlyAmountController::update()` — `PUT /api/v1/branch-monthly-amounts/{id}`: update by model binding; recomputes amount; roles: admin, manager, supervisor
- [x] `BranchMonthlyAmountController::destroy()` — `DELETE /api/v1/branch-monthly-amounts/{id}`: admin, manager, supervisor
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/branch-monthly-amounts`
  - `POST /api/v1/branch-monthly-amounts`
  - `PUT /api/v1/branch-monthly-amounts/{branchMonthlyAmount}`
  - `DELETE /api/v1/branch-monthly-amounts/{branchMonthlyAmount}`
- [x] Update `BranchMonthlyAmountController::store()` — accept optional `amount` field; if provided use directly, otherwise compute `days × SystemConfiguration::getValue('daily_meal_rate', 135)`
- [x] Update `BranchMonthlyAmountController::update()` — same optional `amount` override logic
- [x] Update `BranchMonthlyAmountController::store()` and `update()` — change `days` validation from `min:1` to `min:0`; add `Rule::prohibitedIf($request->integer('days') === 0)` on the `amount` field — returns 422 with message "Amount override is not allowed when school days is 0."

### 8.3 Backend — Payment Amount Adjustment
- [x] Add `PaymentController::updateAmount()` — dedicated `PATCH /api/v1/students/{student}/payments/{payment}/amount` route; updates `amount` column only on `unpaid` payments; roles: admin, manager
  - Implementation: split into a dedicated action (`updateAmount()`) and separate route rather than detecting intent inside `toggle()`

### 8.4 Frontend — Payment Tab Updates
- [x] Update payment tab component — display year in payment row headers; group rows by year using section dividers; show "Month Year" (e.g. "June 2025") labels
- [x] Add `[+ Add Subscription Period]` button (admin/manager only — canToggle gate); renders at top of Payment tab
- [x] Build Add Subscription Period dialog component:
  - [x] Month+year range pickers (start and end); year range 2020–2099 enforced client-side
  - [x] Submit via `useMutation` → `POST /api/v1/students/{student}/payments/range`
  - [x] On success: invalidate `["student-payments", studentId]` query; shows created/skipped counts
  - [x] Validation: end before start rejected client-side
- [x] Add `[Edit Amount]` button on each `unpaid` payment row (admin/manager only — `canToggle` gate):
  - Opens small inline dialog pre-filled with current amount
  - Decimal input, min 0
  - Calls `PATCH /api/v1/students/{student}/payments/{payment}/amount` with `{ amount }`
  - On success: invalidates `["student-payments", studentId]` query
- [x] Update `lib/api/students.ts` — add `updatePaymentAmount(studentId, paymentId, amount)` method calling `PATCH /payments/{payment}/amount` with `{ amount }`

### 8.5 Frontend — Subscription Config Page
- [x] Page at `app/(kitchen)/references/subscription-config/page.tsx` (admin-only, non-admins redirected)
- [x] Year selector at top; defaults to current year; drives `useQuery`
- [x] Table: all 10 school months, columns: Month | Days | Amount | Status | Actions
  - [x] Source badge: green "Configured" badge vs muted "Default" badge
  - [x] Edit/Set button → opens modal with days input, live amount preview (days × rate)
  - [x] Delete/Revert for configured rows (window.confirm before delete)
- [x] `createBranchMonthlyAmount` for new records; `updateBranchMonthlyAmount` for existing
- [x] On success: invalidates `["branch-monthly-amounts", year]` query
- [x] "Subscription Config" added to References nav in `kitchen-layout.tsx`
- [x] `loading.tsx` skeleton created
- [x] Update edit dialog — add optional "Amount Override" decimal input below the computed amount preview; if filled, include `amount` in the request payload; if empty, omit it (server computes from days × rate)
- [x] Update `lib/api/students.ts` — update `createBranchMonthlyAmount` and `updateBranchMonthlyAmount` payloads to accept optional `amount?: number`
- [x] Update edit dialog — change days input `min={1}` to `min={0}`; change inline guard from `days < 1` to `days < 0`; update error message to "Days must be between 0 and 31."
- [x] Update edit dialog — when `days === 0`: clear and disable the Amount Override input; show helper text "No charge — month has no school activity. Students will not be billed for this month."; computed amount preview shows "₱0 (no charge)"

## 9. Photo Upload
- [x] Student photo: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation, stored in `storage/app/private/photos/students/`

## 10. Policies
- [x] `StudentPolicy` — view/create/update/delete (admin/manager/supervisor); topUp (admin/manager/supervisor); settleCredit (admin/manager — Supervisor explicitly excluded)

## 11. Tests

### 11.1 Existing Tests (passing)
- [x] `EnrollmentTest` — subscription enrollment seeds 10 monthly payments; non-subscription enrollment skips; QR uniqueness collision retry; duplicate student_number rejected per branch when provided; cashier cannot enroll (403); **⚠ Task 13 adds: null student_number allowed; two nulls in same branch both succeed**
- [x] `StudentListTest` — filters work; branch-scoped (other branch's students not returned); subscription/non-subscription grouping
- [x] `StudentDetailTest` — profile update strips tags from notes/allergies; QR regeneration invalidates old code; status change with reason; soft delete retains orders
- [x] `WalletTopUpTest` — cashier cannot top-up (403); reference number alphanumeric validation; new balance preview matches deposit
- [x] `CreditSettlementTest` — supervisor cannot settle credit (403); atomic balance update; credit_transactions entry created on each change

### 11.2 New / Updated Tests
- [x] `EnrollmentTest` — updated: subscription seeding test asserts `year` column; partial range (Aug–Dec = 5 rows); end-before-start rejected (422)
- [x] `BranchMonthlyAmountTest` (new, 7 tests) — full CRUD coverage: list, configured/default display, create, upsert (idempotent), update by ID, delete, cashier 403
- [x] `PaymentRangeTest` (new, 4 tests) — happy path, skip-existing, end-before-start 422, cashier 403
- [x] `PaymentControllerTest` — update existing toggle and record tests to assert `year` is in response/request
- [x] Update `EnrollmentTest` factory states — `StudentFactory::subscriptionPayload()` should accept optional subscription period fields
- [x] `BranchMonthlyAmountTest` — new: admin can create a month config with `days = 0` (assert 201, record saved); admin can update to `days = 0` (assert 200); `days = 0` with `amount` override returns 422 on `amount` field
- [x] `EnrollmentTest` — new: enrolling a subscription student when one month has `days = 0` skips that month; assert `student_monthly_payments` count = total months in range minus 0-day months
- [x] `PaymentRangeTest` — new: `POST /api/v1/students/{student}/payments/range` when a month in range has `days = 0` skips it; assert created count excludes that month

## 12. Soft-Deleted Student Filter & Restore

### 12.1 Backend
- [x] Migration: no schema change needed — `deleted_at` column already exists on `students` table
- [x] `StudentController::index()` — add `deleted` query parameter: `onlyTrashed()` branch + shared search/grade filters; unified query with ternary scope — simplifier refactored
- [x] `StudentController::index()` response — `deleted_at` included in StudentResource conditionally
- [x] New `StudentController::restore()` — 422 guard if not trashed; `$student->restore()`; logs `students.restored`; returns `StudentResource`; admin|manager|supervisor
- [x] Route: `POST /students/{student}/restore` with `->withTrashed()` — registered in admin|manager|supervisor group
- [x] `StudentResource` — `deleted_at` conditionally included; simplifier removed redundant null-safe operator
- [x] Pattern follows `UserManagementController::reactivate()`

### 12.2 Frontend (sunbites-pos)
- [x] "Show Deleted" / "Hide Deleted" toggle pill button (red filled when active)
- [x] `showDeleted` state drives `deleted: 1` query param
- [x] `DeletedStudentCard` component: shows "Removed: {date}", `[Restore]` button only, hides Edit/Wallet/Remove
- [x] `[Restore]` → `useMutation` → `studentApi.restore()` → invalidate list + toast "Student restored."
- [x] `restore(id)` added to `lib/api/students.ts`
- [x] Deleted view hides type tabs and month filter; keeps search + grade

### 12.3 Tests
- [x] `StudentListTest` — 3 new tests: deleted filter / active list excludes deleted / branch-scoped
- [x] `StudentRestoreTest` — 4 tests: restore 200, activity log, cashier 403, active student 422 — 14/14 pass; simplifier extracted `asUser()` helper

## 13. Nullable / Editable Student Number

### 13.1 Backend
- [x] Migration: `make_student_number_nullable_on_students_table` — column now NULL; 412/412 tests pass
- [x] `EnrollmentController::store()` — `nullable` + `whereNotNull()` uniqueness guard
- [x] `StudentController::update()` — `student_number` added with `nullable` + `ignore()->whereNotNull()`; included via `$student->update($validated)` passthrough
- [x] `StudentResource` — no change needed; null returned as-is
- [x] `StudentFactory` — `withoutStudentNumber()` state added

### 13.2 Frontend (sunbites-pos)
- [x] `enrollment/page.tsx` — label "Student No. (optional)"; removed `required`; Zod: `.optional().or(z.literal(''))`;  empty string → null before submit
- [x] `students/[id]/page.tsx` — `EditProfileForm` adds `studentNumber` state + field + payload; success screen shows "—" when null
- [x] `types/student.ts` — `student_number: string | null`; `UpdateStudentPayload` has `student_number?: string | null`
- [x] `student_number` displays "—" when null in InfoRow

### 13.3 Tests
- [x] `EnrollmentTest` — 3 new tests: null succeeds, two nulls both succeed, duplicate still 422
- [x] `StudentDetailTest` — 3 new tests: update succeeds, duplicate 422, clear to null succeeds — 34/34 pass; 412/412 full suite

## 14. QR ID Card Color Differentiation by Student Type

### 14.1 Frontend (sunbites-pos)
- [ ] Create `lib/utils/card-accent-colors.ts` — `getCardAccentColors(studentType: StudentType): CardAccentColors` utility; subscription returns red palette (`#e5322a`), non-subscription returns yellow palette (`#f4b400`); write unit tests: subscription → `headerBg: "#e5322a"`, non-subscription → `headerBg: "#f4b400"`
- [ ] Update `PrintCard` in `app/(kitchen)/students/page.tsx` — call `getCardAccentColors(student.student_type)` and replace all `oklch(0.577 0.245 27.325)` / hardcoded footer colors with returned values; write render tests asserting header `backgroundColor` per student type
- [ ] Update inline print card in `app/(kitchen)/students/[id]/page.tsx` — same color replacement using the utility
- [ ] Update QR print section in `app/(kitchen)/enrollment/page.tsx` — same color replacement using the utility
- [ ] Verify existing tests in `students/student-list.test.tsx` still pass after changes