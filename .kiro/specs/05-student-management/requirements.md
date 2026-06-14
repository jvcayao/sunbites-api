# Spec 05 — Student Management & Enrollment

## Overview

Students are the core entity of the system. Each student belongs to a branch, has a profile, a QR code for canteen identification, and a digital wallet. Students are enrolled by Admin, Manager, or Supervisor. Cashiers cannot access student records.

---

## Student Types

There are two types of students, set at enrollment and driving significant behavioral differences:

| | 📋 Subscription | 🪙 Non-Subscription |
|---|---|---|
| Monthly fee | Yes — fixed rate per school month (e.g. ₱2,970/month) | No monthly fee |
| Payment tracking | Tracks paid/unpaid per month for a configured date range | Not tracked |
| Student list card | Orange left border, month payment badges | Purple left border, "Wallet-only" info box |
| Parent portal tabs | Profile + Monthly Menu + Food History + Monthly Payment + Feedback | Profile + Food History + Feedback only |
| Payment reminder banner | Shown 14 days before month end | Not shown |
| POS access | Same — wallet deduction or cash/GCash | Same |

Both types have a QR code and wallet. Both can buy any menu item at the POS.

---

## Student Data Model

```
students
  id
  branch_id                (FK → branches)
  student_number           (string, nullable, unique per branch when set) — school-assigned ID; may be blank at enrollment and filled in later
  first_name               (string)
  last_name                (string)
  full_name                (virtual/computed)
  grade_level              (string)          — e.g. "Grade 3"
  section                  (string, nullable) — e.g. "Section Mabini"
  birthday                 (date)
  photo_path               (string, nullable)
  allergies                (text, nullable)
  notes                    (text, nullable)
  qr_code                  (string, unique)  — `SB-{12 random alphanumeric}`, CSPRNG, globally unique
  student_type             (enum: subscription, non_subscription)
  enrollment_status        (enum: enrolled, paused, unenrolled, banned, graduated)
  enrollment_date          (date)
  points                   (int, default 0)  — loyalty points: 1 point per ₱1,000 spent
  total_spent              (decimal, default 0) — cumulative spend for points tracking
  credit_balance           (decimal, default 0) — outstanding canteen credit (CREDIT_LIMIT = ₱300)
  created_at, updated_at, deleted_at (soft delete)

student_contacts (parent/guardian info — normalized)
  id
  student_id               (FK → students)
  full_name                (string)
  relationship             (string)          — "Mother", "Father", "Guardian"
  phone                    (string)
  address                  (string)
  email                    (string)          — informational only; never auto-matched to parents table
  is_primary               (boolean)

student_monthly_payments (subscription students only)
  id
  student_id               (FK → students)
  school_month             (enum: june, july, august, september, october,
                                  november, december, january, february, march)
  year                     (int)             — school year the payment belongs to (e.g. 2025)
  status                   (enum: paid, unpaid)
  amount                   (decimal)         — amount at time of recording (admin-overridable)
  recorded_at              (timestamp, nullable)
  recorded_by              (FK → users, nullable)

  UNIQUE KEY: (student_id, school_month, year)

branch_monthly_amounts (per-branch, per-year, per-month configuration — sole source of truth)
  id
  branch_id                (FK → branches)
  school_month             (enum)
  year                     (int)             — school year this config applies to (e.g. 2025)
  days                     (int)             — school days in this month for this year
  amount                   (decimal)         — monthly fee = daily_meal_rate × days

  UNIQUE KEY: (branch_id, school_month, year)
```

**Notes:**
- `student_monthly_payments` rows are created for each calendar month in the chosen subscription date range at enrollment, status `unpaid`, for subscription students only.
- `branch_monthly_amounts` is the sole source of truth for days and amount per school month per year per branch. Falls back to system defaults (₱135 × default days per month from `config/sunbites.php`) if no record exists for a given month+year.
- `config/sunbites.php` retains `daily_meal_rate` (₱135) as the base fallback rate and the system-default `days` per school month. The `school_months.*.amount` values in config are no longer authoritative.
- `points` rule: 1 point earned per ₱1,000 cumulative spend. Tracked on `students.total_spent`.
- `credit_balance` tracks outstanding canteen credit. `CREDIT_LIMIT = ₱300` is a system constant (configurable in `config/sunbites.php`).

### Enrollment Statuses
| Key | Label | Meaning |
|---|---|---|
| `enrolled` | Enrolled | Active, can use canteen |
| `paused` | Paused | Temporarily suspended — no canteen access |
| `unenrolled` | Unenrolled | No longer in program |
| `banned` | Banned | Banned from canteen |
| `graduated` | Graduated | Completed schooling |

Only `enrolled` status allows POS transactions for that student.

---

## QR Code

### Regeneration (Lost / Damaged Card)
When a student's physical QR card is lost or damaged, authorized staff can generate a replacement:
- **Who can regenerate:** Admin, Manager, Supervisor
- **Where:** Student detail → Profile tab → `[Regenerate QR Code]` button
- **Action:** Generates a new `Str::random(12)` token with collision retry, replaces `students.qr_code` — the old token is immediately invalidated
- **Logged:** Activity log entry `students.qr_regenerated` with `properties: { old_qr_redacted: true, new_qr_redacted: true, performed_by }` — QR values themselves are not logged
- **After regeneration:** Success screen shows the new QR code with a print button

### Initial Generation at Enrollment
- Generated with `'SB-' . Str::random(12)` — Laravel's `Str::random()` uses `random_bytes()` internally (CSPRNG)
- On collision: retry generation until unique
- Stored in `qr_code` column (`string, unique`) — only the token is stored, not an image
- QR image rendered client-side on-demand via a QR package (print/download)
- QR is verified by database lookup only — the token contains no decodable information about the student, branch, or sequence

---

## Enrollment Form

Accessible at: `pos.sunbites.com.ph/enrollment`, roles: Admin, Manager, Supervisor

### Sections

**1. Branch** — pre-filled with active branch (read-only for non-admin; admin can override)

**2. Student Information**
- Photo upload (optional)
- First name, Last name
- Student number (manually entered — school-assigned ID; validated unique per branch)
- Grade level (select from predefined list)
- Section (text input)
- Birthday (date picker)
- Allergies / Medical restrictions (textarea)
- Additional notes (textarea)

**3. Enrollment Type** — Subscription or Non-Subscription radio cards

**4. Subscription Period** (shown only when Subscription type is selected)
- Start month + year picker (default: June of the current year)
- End month + year picker (default: March of current year + 1)
- Staff can change both pickers freely — any contiguous month range is valid
- System derives one payment row per calendar month between start and end (inclusive)
- A preview table renders below the pickers showing each month, its days, and computed amount
  - If a `branch_monthly_amounts` record exists for that month+year, use its days+amount
  - Otherwise fall back to `daily_meal_rate` × system-default days for that month
  - Preview updates live as the pickers change

**5. Contact / Guardian Information** (at least one required)
- Full name, Relationship, Phone, Address, Email (optional — but if provided, a parent portal account is provisioned)
- "Add another contact" option (up to 3 contacts)
- A note is shown on the form: "Providing an email will automatically send a portal activation link to the guardian."

**6. Permissions & Acknowledgement**
- Checkbox: permission to receive meals
- Checkbox: responsibility to notify about dietary changes
- Digital signature (type full name)
- Date (auto-filled, read-only)

### On Submit
- Validate all required fields
- `student_number` is **optional** — may be left blank if the school has not yet assigned one; when provided, it must be unique per branch
- Auto-generate `qr_code`
- Create wallet via `bavix/laravel-wallet`
- If subscription type: generate one `student_monthly_payments` row per calendar month in the selected range (status=unpaid, amount resolved from `branch_monthly_amounts` or config fallback)
- **Parent account provisioning** (for each contact that has an email):
  1. Look up `parents` table by email
  2. If no record exists: create a `parents` record (name from contact, email, `email_verified_at = null` = not yet activated); generate a signed activation URL via `PasswordBroker` and dispatch `ParentWelcomeMail`
  3. If a record already exists (same parent with another child): skip creation, but create the `parent_student` pivot link if it does not already exist
  4. Insert `parent_student` row: `parent_id`, `student_id`, `linked_at = now()`, `linked_by = auth()->id()`
- Show success screen with generated student number, QR code display, and print button
- If any contact had an email: success screen notes "Activation email sent to {email}"

---

## QR Code Printing

### Single QR Print
Available in two places:
1. **Enrollment success screen** — print button appears immediately after a student is enrolled
2. **Student detail → Profile tab** — `[Print QR Code]` button

Print layout (browser print, `@media print` CSS):
- Student photo (if available) or placeholder
- Student name (bold, large)
- Grade level + section
- QR code (centered, 200×200px minimum)
- QR ID text below the code
- Branch name

### Batch QR Print (Multiple Students)
Available from the student list page.

**Selection flow:**
- Checkbox column added to student list table
- "Select All" checkbox in table header (selects current filtered/visible page)
- Floating action bar appears at bottom when ≥ 1 student selected

**Print Preview Modal:**
- Shows a grid of QR cards (2 or 4 per row, user-selectable)
- Each card: photo + name + grade + QR code + QR ID
- `[Print]` button triggers `window.print()` with the grid layout
- Grid of cards optimized for A4 paper; 4 cards per row on A4

---

## Student List

Accessible at: `pos.sunbites.com.ph/students`, roles: Admin, Manager, Supervisor

### Filters
- Search by name or student number
- Filter by grade level
- Filter by enrollment status (excludes deleted students from this filter)
- **Month + Year + Payment Status filter** (subscription tab only): Month dropdown + Year dropdown + Paid/Unpaid toggle
- Group tabs: All / Subscription / Non-Subscription
- **Deleted toggle**: when active, replaces the normal list with only soft-deleted students; all other filters still apply within deleted view
- Default sort: alphabetical by last name, then first name

### Student List Layout
When "All" tab is active, students are grouped into two sections:
1. **📋 SUBSCRIPTION STUDENTS (N)** — orange section header
2. **🪙 NON-SUBSCRIPTION STUDENTS (N)** — purple section header

### Student Card
Each student shows:
- Left border: 4px — orange for subscription, purple for non-subscription
- Photo + name + student type badge
- Grade level + section
- Enrollment status badge (clickable to change, with role restriction)
- Primary contact name + phone
- Wallet balance + loyalty points
- Outstanding credit badge (red, shown only when `credit_balance > 0`): e.g. "₱135 Credit Owed"
- Enrollment date
- Actions: Edit, Wallet Top-up, Remove (with confirmation)
- When in **deleted view**: actions replaced by `[Restore]` button only; no Remove, no Wallet, no Edit

**Subscription student card (bottom section):**
- Month payment badges showing month + year (e.g. "Jun '25"): green ✓ = paid, red ✗ = unpaid — clickable to toggle
- "Record Payment" button

**Non-subscription student card (bottom section):**
- "Wallet-only account — loads wallet to purchase food items" info box (purple tint)
- "Load Wallet" button (purple)

---

## Student Wallet

Powered by `bavix/laravel-wallet`.

### Wallet Top-up
Roles: Admin, Manager, Supervisor (NOT Cashier)

**Top-up Modal:**
- Amount input
- Payment method: Cash / GCash / Bank Transfer
- Reference number (optional, for GCash/bank transfers) — validated alphanumeric max 50 characters if provided
- Note (optional)
- On confirm: deposits to wallet via `bavix/laravel-wallet` `deposit()` method

### Wallet Deduction
- Happens automatically at POS checkout via `bavix/laravel-wallet` `withdraw()` method

---

## Student Detail Page

### Tabs
- **Profile** — full student info, photo, QR code (with download/print), contacts
- **Contacts** — guardian/contact management (see Guardian Contact Management below)
- **Wallet** — current balance, top-up button, transaction history
- **Order History** — all canteen transactions for this student
- **Payment** — subscription payment manager (Admin/Manager: mark paid/unpaid, edit monthly amounts; "Add Subscription Period" button for Admin/Manager/Supervisor)
- **Notes / Logs** — `activity_log` records where `subject_type = Student`; read-only, newest first

---

## Add Subscription Period (Student Detail — Payment Tab)

Allows adding additional subscription date ranges to an existing student's payment records.

- **Who:** Admin, Manager, Supervisor
- **Where:** Student detail → Payment tab → `[+ Add Subscription Period]` button
- **Action:** Opens a dialog with the same month+year range picker used in enrollment
- **Validation:** No overlap with existing `student_monthly_payments` records for this student (same school_month + year combination must not already exist)
- **Preview:** Same preview table as enrollment — shows computed amount per month before confirming
- **On confirm:** Creates new `student_monthly_payments` rows for all months in the new range (status=unpaid)

---

## Branch Monthly Amounts — Full CRUD

Accessible at: `pos.sunbites.com.ph/references/subscription-config`

Roles: Admin, Manager, Supervisor

This is the management interface for the `branch_monthly_amounts` table. Admins/Managers/Supervisors configure how many school days are in each month for a given year and branch, which determines the subscription fee for that month.

### Amount Computation
`amount = daily_meal_rate × days`

Where `daily_meal_rate` is read from the `system_configurations` table (key: `daily_meal_rate`), falling back to `135` if no record exists. See Spec 09 (System Configuration).

The computed amount is the default — admins can override it directly in the UI by entering a manual amount. If an explicit `amount` is provided in the request, it is stored as-is; otherwise the server computes `days × daily_meal_rate`.

If no record exists for a given school month + year combination, the system uses the default days from `config/sunbites.php` and computes the fallback amount at runtime.

### Default Days (System Fallback)
| Month | Default Days | Default Amount |
|---|---|---|
| June | 22 | ₱2,970 |
| July | 22 | ₱2,970 |
| August | 18 | ₱2,430 |
| September | 22 | ₱2,970 |
| October | 22 | ₱2,970 |
| November | 16 | ₱2,160 |
| December | 15 | ₱2,025 |
| January | 20 | ₱2,700 |
| February | 18 | ₱2,430 |
| March | 7 | ₱945 |

---

## Guardian Contact Management

Accessible at: Student detail → **Contacts** tab. Roles: Admin, Manager, Supervisor.

This tab replaces the previous link-request approval flow. All parent-linking happens automatically at enrollment or when a contact with an email is added here.

### Contact List
- Displays all `student_contacts` for the student
- Each row shows: Full name, Relationship, Phone, Address, Email, Portal account status badge (`Activated` / `Pending Activation` / `No Email`)
- One contact must always be marked `is_primary = true`

### Add Contact
- Same fields as enrollment: Full name, Relationship, Phone, Address, Email (optional)
- If an email is provided:
  - Follow the same find-or-create parent logic as enrollment
  - Create/link `parent_student` pivot
  - Send activation email if the account is new
- A student may have up to 3 contacts total

### Edit Contact
- All fields are editable
- If the email is changed and a new email is provided:
  - Treat as a new find-or-create: look up by new email, create parent if not found, link pivot
  - Old parent account loses the `parent_student` pivot link for this student if no other contacts share that email
- If email is removed: `parent_student` pivot is removed (parent loses access to this student)

### Delete Contact
- Requires at least one contact to remain (`is_primary` contact cannot be deleted while other contacts exist without a primary replacement)
- If deleted contact had a linked parent account: remove the `parent_student` pivot for this student; the parent account itself is NOT deleted (they may be linked to other students)

### Resend Activation
- Available on contacts whose parent account exists but `email_verified_at` is null (pending activation)
- Button label: `Resend Activation Email`
- Throttled: max 3 resends per guardian per 24 hours (server-side)
- Also available for activated accounts as `Send Password Reset Email`

### Minimum Guardian Enforcement
- At least one contact must exist at all times — the delete action is disabled (greyed out with tooltip) when only one contact remains

---

## Constraints

- Students are strictly branch-scoped — a student enrolled in Antipolo cannot appear in Iloilo
- Student number is unique per branch
- QR code is globally unique across all branches
- Soft delete: removing a student archives them; order history is retained
- A student with `paused`, `unenrolled`, `banned`, or `graduated` status cannot be selected in POS

## Security Constraints

### Freetext Field Sanitization
Fields `notes` and `allergies` must be sanitized server-side before storage using `strip_tags()`. These fields must be rendered as plain text in the UI.

### Credit Settlement Permission
Mark Credit as Settled is Admin and Manager only — Supervisor is explicitly excluded. Enforced in `StudentPolicy::settleCredit()`. UI hides the action for Supervisors, but the policy is the authoritative enforcement.

### Credit Transaction Ledger
`credit_balance` on the `students` table is a cached aggregate — never modified directly by arbitrary code. All credit changes go through an immutable `credit_transactions` log first:

```
credit_transactions
  id
  student_id       (FK → students)
  order_id         (FK → orders, nullable)
  type             (enum: Charged, Settled, Voided)
  amount           (decimal — positive value; type determines direction)
  notes            (string, nullable)
  performed_by     (FK → users)
  created_at
```

On each `credit_transactions` insert, `students.credit_balance` is updated atomically in the same `DB::transaction()`. This prevents balance drift and provides an immutable audit trail.

### Student Photo Upload
- Accepted MIME types: `image/jpeg`, `image/png`, `image/webp` only
- Max file size: 2MB
- Validate server-side before storage — reject invalid uploads with 422 error

---

## Deleted Students (Soft-Delete Recovery)

Students are soft-deleted — they are never permanently erased. This preserves order history, wallet transaction history, activity logs, and payment records for reporting and auditing purposes.

### Rules
- Deleted students are excluded from all normal list views, the POS lookup, and any active data queries (handled by global `BranchScope` + `SoftDeletes`)
- No force delete is permitted — the only delete operation is soft delete
- Admin, Manager, and Supervisor can restore a soft-deleted student

### Deleted Student List (filtered view)
- Accessible via a "Deleted" toggle/filter on the student list page
- Shows only students where `deleted_at IS NOT NULL` for the active branch
- All other filters (search, grade, type tabs) still apply in the deleted view
- Each deleted student card shows: name, grade, student type, `deleted_at` date, primary contact
- The only action visible is `[Restore]` — no Edit, no Wallet, no Remove button
- A `deleted_at` label shows when the student was removed

### Restore
- Calls `POST /api/v1/students/{student}/restore` (with `.withTrashed()` route binding)
- Clears `deleted_at`; student returns to normal list with their previous `enrollment_status`
- Logs `students.restored` with causer
- UI shows a success toast and removes the student card from the deleted view

---

## Student Number

The student number is assigned by the school administration, not by this system. At the time of enrollment, the number may not yet be available.

### Rules
- `student_number` is **optional at enrollment** — staff may leave it blank if the school has not assigned one yet
- Uniqueness is enforced per branch at the time the number is set — null values are excluded from the unique constraint
- Staff can set or update a student's number at any time from the student profile edit form
- The edit field appears in the profile edit section of the student detail page
- The enrollment form shows the field as optional (labelled "Student No. (optional)")
- If a student with a blank number is assigned a number that already belongs to another student in the same branch, a 422 validation error is returned
- Activity log tracks `student_number` changes via `LogsActivity` (already in `$logAttributes`)

---

## API Routes

All routes under `auth:sanctum` + `ability:staff` middleware.

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/enrollment` | admin, manager, supervisor | Enrollment form data (branches, grade levels) |
| POST | `/api/v1/enrollment` | admin, manager, supervisor | Enroll a new student |
| GET | `/api/v1/students` | admin, manager, supervisor | Paginated student list with filters |
| GET | `/api/v1/students/{student}` | admin, manager, supervisor | Student detail |
| PUT | `/api/v1/students/{student}` | admin, manager, supervisor | Update student profile |
| DELETE | `/api/v1/students/{student}` | admin, manager, supervisor | Soft delete student |
| POST | `/api/v1/students/{student}/restore` | admin, manager, supervisor | Restore a soft-deleted student (`.withTrashed()` binding) |
| POST | `/api/v1/students/{student}/regenerate-qr` | admin, manager, supervisor | Regenerate QR code |
| PATCH | `/api/v1/students/{student}/status` | admin, manager, supervisor | Change enrollment status |
| POST | `/api/v1/students/{student}/wallet/top-up` | admin, manager, supervisor | Wallet top-up |
| POST | `/api/v1/students/{student}/credit/settle` | admin, manager | Settle outstanding credit |
| GET | `/api/v1/students/{student}/payments` | admin, manager, supervisor | Monthly payment records |
| PATCH | `/api/v1/students/{student}/payments/{payment}` | admin, manager | Toggle month paid/unpaid |
| POST | `/api/v1/students/{student}/payments/range` | admin, manager, supervisor | Add subscription period (new date range) |
| GET | `/api/v1/branch-monthly-amounts` | admin, manager, supervisor | List all months for active branch + year |
| POST | `/api/v1/branch-monthly-amounts` | admin, manager, supervisor | Create/upsert a month config |
| PUT | `/api/v1/branch-monthly-amounts/{id}` | admin, manager, supervisor | Update a specific month config record |
| DELETE | `/api/v1/branch-monthly-amounts/{id}` | admin, manager, supervisor | Delete a month config record |
| GET | `/api/v1/students/{student}/contacts` | admin, manager, supervisor | List contacts for a student |
| POST | `/api/v1/students/{student}/contacts` | admin, manager, supervisor | Add a new contact (triggers parent provisioning if email given) |
| PUT | `/api/v1/students/{student}/contacts/{contact}` | admin, manager, supervisor | Update a contact |
| DELETE | `/api/v1/students/{student}/contacts/{contact}` | admin, manager, supervisor | Delete a contact (min 1 enforced) |
| POST | `/api/v1/students/{student}/contacts/{contact}/resend-activation` | admin, manager, supervisor | Resend activation or password reset email |

---

## Requirements

- [x] `students` table with all fields including `student_type`, `points`, `total_spent`, `credit_balance`
- [x] `student_contacts` table (one-to-many from student)
- [x] `student_monthly_payments` table with UNIQUE KEY on `(student_id, school_month)` (to be updated to `(student_id, school_month, year)`)
- [x] `branch_monthly_amounts` table with UNIQUE KEY on `(branch_id, school_month)` (to be updated to `(branch_id, school_month, year)`)
- [x] `credit_transactions` table with `type` enum (Charged/Settled/Voided — TitleCase)
- [x] `HasBranch` trait on `Student` model
- [x] `student_number` manually entered by staff — school-assigned ID; validated unique per branch
- [x] Auto-generate unique `qr_code` on creation: `'SB-' . Str::random(12)` with collision retry loop
- [x] QR regeneration: Admin/Manager/Supervisor; old token immediately invalidated; activity log entry (values not logged)
- [x] Wallet created via `bavix/laravel-wallet` on student enrollment
- [x] Points tracking: 1 point per ₱1,000 cumulative spend — incremented at POS checkout
- [x] Enrollment API response includes: student_number, qr_code (for display/print) — no email/password credentials
- [x] Student list endpoint with filters: search, grade, enrollment status, month+payment filter (subscription only), type
- [x] Default sort: alphabetical by last_name then first_name
- [x] Subscription student: month badges with toggle paid/unpaid
- [x] Non-subscription student: wallet-only info, no monthly payments
- [x] Student status change with role enforcement; reason required for `banned`/`unenrolled`
- [x] Wallet top-up (Admin/Manager/Supervisor only): amount, payment method, reference number (alphanumeric max 50 chars if provided), live new-balance preview
- [x] Student detail response with Profile, Wallet, Order History, Payment, and Logs tabs
- [x] `credit_balance` is a cached aggregate — all credit changes go through `credit_transactions` insert + atomic `credit_balance` update in same `DB::transaction()`
- [x] Admin/Manager only can settle credit (Supervisor excluded) — enforced in `StudentPolicy::settleCredit()`
- [x] Mark as Settled: inserts `credit_transactions` (type=Settled), atomically sets `credit_balance = 0` with `lockForUpdate()`
- [x] Student card shows red "₱X Credit Owed" badge when `credit_balance > 0`
- [x] Freetext fields (notes, allergies): `strip_tags()` server-side before storage; rendered as plain text in UI
- [x] GCash/Bank reference number: alphanumeric max 50 chars, server-side validation if provided
- [x] Student photo upload: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation
- [x] `CREDIT_LIMIT` constant (₱300) defined in `config/sunbites.php`
- [x] Export students list to Excel (Admin/Manager only); explicit field allowlist — no government ID fields
- [x] Soft delete with retention of order history
- [x] `StudentPolicy` covering view, create, update, delete, top-up, settleCredit
- [x] Log `students.enrolled` (properties: student_type, branch)
- [x] Log `students.updated` (dirty-tracked via `LogsActivity` trait)
- [x] Log `students.status_changed` (properties: old_status, new_status, reason)
- [x] Log `students.deleted` on soft delete
- [x] Log `wallet.topped_up` (properties: amount, payment_method, reference, new_balance)
- [x] Log `wallet.inline_reload` on POS inline reload (properties: amount, payment_method, cashier, order context)
- [x] Log `wallet.credit_settled` (properties: amount_settled, settled_by)
- [x] Log `payments.recorded` (properties: school_month, year, status, amount, recorded_by)
- [ ] Soft-deleted students viewable via `?deleted=1` filter on the student list endpoint
- [ ] `POST /api/v1/students/{student}/restore` — restore a soft-deleted student; `.withTrashed()` route binding; roles: admin, manager, supervisor; logs `students.restored`
- [ ] No force delete endpoint — permanent deletion is not supported
- [ ] `student_number` is nullable in the `students` table — migration required to ALTER the column
- [ ] Enrollment validation: `student_number` changed from `required` to `nullable` — uniqueness still enforced per branch when a value is provided
- [ ] `StudentController::update()` — add `student_number` to editable fields with `Rule::unique('students')->where(branch_id)->ignore($student->id)->whereNotNull('student_number')` uniqueness validation
- [ ] Student detail profile edit form — show editable `student_number` field alongside existing profile fields
- [ ] Enrollment form — `student_number` field labelled as "Student No. (optional)"
- [ ] `BranchMonthlyAmountController::store()` and `update()` — accept optional `amount` field in request; if provided, store it directly instead of computing `days × daily_meal_rate`; if absent, compute from current `daily_meal_rate` system config value
- [ ] Student detail Payment tab — for `unpaid` payments only: show an `[Edit Amount]` inline button next to the toggle; opens a small dialog with a decimal input pre-filled with current amount; on save: `PATCH /api/v1/students/{student}/payments/{payment}` with `{ amount }`; updates the `amount` column without changing status
- [ ] `PaymentController::updateAmount(Request, Student, StudentMonthlyPayment)` — new action on existing PATCH route: if request only contains `amount` (no status toggle), update the `amount` field on the payment record; only allowed on `unpaid` payments; roles: admin, manager
- [ ] Migration: add `year` (int) column to `student_monthly_payments`; drop old unique key `(student_id, school_month)`; add new unique key `(student_id, school_month, year)`
- [ ] Migration: add `year` (int) and `days` (int) columns to `branch_monthly_amounts`; drop old unique key `(branch_id, school_month)`; add new unique key `(branch_id, school_month, year)`
- [ ] `config/sunbites.php`: keep `daily_meal_rate` and `school_months.*.days` as fallback defaults; `school_months.*.amount` is no longer authoritative but may remain for reference
- [ ] Enrollment form: replace fixed 10-month seeding with subscription period date range picker (start month+year, end month+year); default June [current year] → March [current year + 1]
- [ ] Enrollment form: preview table showing each month in range with resolved days+amount before submit
- [ ] `EnrollmentController::store()`: accept `subscription_start` (month+year) and `subscription_end` (month+year); generate one payment row per calendar month in that range; resolve amount from `branch_monthly_amounts` for the given year, else fallback to `daily_meal_rate × default_days`
- [ ] `GET /api/v1/branch-monthly-amounts?year=YYYY` — list all configured months for active branch + specified year
- [ ] `POST /api/v1/branch-monthly-amounts` — create/upsert a month config (branch from active branch, school_month, year, days, amount computed as `daily_meal_rate × days`)
- [ ] `PUT /api/v1/branch-monthly-amounts/{id}` — update days (and recompute amount); roles: admin, manager, supervisor
- [ ] `DELETE /api/v1/branch-monthly-amounts/{id}` — delete a month config record; roles: admin, manager, supervisor
- [ ] `POST /api/v1/students/{student}/payments/range` — add subscription period for existing student; validate no overlap on `(student_id, school_month, year)`; roles: admin, manager, supervisor
- [ ] Student detail Payment tab: show `year` alongside month in each payment row
- [ ] Student detail Payment tab: `[+ Add Subscription Period]` button (admin/manager/supervisor) — opens dialog with same range picker + preview table
- [ ] New page: `pos.sunbites.com.ph/references/subscription-config` — year selector, table of configured months (Month | Days | Amount | Actions), "Add Month" button, inline or modal edit, show default vs configured indicator
- [ ] **Enrollment → parent provisioning**: `EnrollmentController::store()` loops contacts; for each contact with a non-null email, calls `ParentProvisioningService::provision(email, name, studentId, enrolledBy)` — find-or-create `parents` record, create `parent_student` pivot, dispatch `ParentWelcomeMail` if account is new
- [ ] `ParentProvisioningService` — reusable service used by enrollment and contact CRUD; idempotent: calling twice with same email+student does not create duplicate pivot
- [ ] `parent_student` pivot row: `parent_id`, `student_id`, `linked_at`, `linked_by`, `wallet_alert_threshold (decimal, default 0)`
- [ ] Student detail Contacts tab: lists all `student_contacts` with portal account status badge (`Activated` / `Pending Activation` / `No Email`)
- [ ] `StudentContactController::store()` — add contact; calls `ParentProvisioningService` if email present; enforces max 3 contacts per student
- [ ] `StudentContactController::update()` — edit contact; handles email change (re-provision parent with new email; remove old pivot if old email no longer associated with any contact for this student)
- [ ] `StudentContactController::destroy()` — delete contact; removes linked `parent_student` pivot; enforces min 1 contact remains
- [ ] `StudentContactController::resendActivation()` — sends activation email (if `email_verified_at` null) or password reset email (if activated); rate-limited: max 3 per guardian per 24 hours
- [ ] Enrollment success screen shows "Activation email sent to {email}" for each contact email provided
- [ ] Log `student_contact.added` (properties: contact_name, email_provided, parent_provisioned)
- [ ] Log `student_contact.updated` (properties: dirty fields)
- [ ] Log `student_contact.deleted` (properties: contact_name)
- [ ] Log `parent.activation_resent` (properties: parent_email, sent_by)