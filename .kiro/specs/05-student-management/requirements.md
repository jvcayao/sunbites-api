# Spec 05 — Student Management & Enrollment

## Overview

Students are the core entity of the system. Each student belongs to a branch, has a profile, a QR code for canteen identification, and a digital wallet. Students are enrolled by Admin, Manager, or Supervisor. Cashiers cannot access student records.

---

## Student Types

There are two types of students, set at enrollment and driving significant behavioral differences:

| | 📋 Subscription | 🪙 Non-Subscription |
|---|---|---|
| Monthly fee | Yes — fixed rate per school month (e.g. ₱2,970/month) | No monthly fee |
| Payment tracking | Tracks paid/unpaid per month (June–March) | Not tracked |
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
  student_number           (string, unique per branch) — manually entered at enrollment (school-assigned ID)
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
  status                   (enum: paid, unpaid)
  amount                   (decimal)         — amount at time of recording (admin-overridable)
  recorded_at              (timestamp, nullable)
  recorded_by              (FK → users, nullable)

  UNIQUE KEY: (student_id, school_month)

branch_monthly_amounts (admin-overridable monthly rates per branch)
  id
  branch_id                (FK → branches)
  school_month             (enum)
  amount                   (decimal)         — default from school_months config

  UNIQUE KEY: (branch_id, school_month)
```

**Notes:**
- `student_monthly_payments` rows are created for all 10 school months at enrollment, status `unpaid`, for subscription students only.
- `branch_monthly_amounts` stores per-branch overrides of the default monthly fee. Falls back to config default (₱135 × school days) if no override exists.
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

**3. Contact / Guardian Information** (at least one required)
- Full name, Relationship, Phone, Address, Email
- "Add another contact" option (up to 3 contacts)

**4. Permissions & Acknowledgement**
- Checkbox: permission to receive meals
- Checkbox: responsibility to notify about dietary changes
- Digital signature (type full name)
- Date (auto-filled, read-only)

### On Submit
- Validate all required fields
- Validate `student_number` is unique per branch
- Auto-generate `qr_code`
- Create wallet via `bavix/laravel-wallet`
- If subscription type: seed all 10 monthly payment records (status=unpaid)
- Show success screen with generated student number, QR code display, and print button

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
- Filter by enrollment status
- **Month + Payment Status filter** (subscription tab only): Month dropdown + Paid/Unpaid toggle
- Group tabs: All / Subscription / Non-Subscription
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

**Subscription student card (bottom section):**
- Month payment badges (Jun–Mar): green ✓ = paid, red ✗ = unpaid — clickable to toggle
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
- **Wallet** — current balance, top-up button, transaction history
- **Order History** — all canteen transactions for this student
- **Payment** — subscription payment manager (Admin/Manager: mark paid/unpaid, edit monthly amounts)
- **Notes / Logs** — `activity_log` records where `subject_type = Student`; read-only, newest first

---

## Parent–Student Link Requests

Full detail in Spec 07 (Parent Portal). Summary:
- When a parent requests to link, a `parent_student_requests` record is created
- Admin/Manager/Supervisor see pending requests and can approve/reject from the student's profile

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
| POST | `/api/v1/students/{student}/regenerate-qr` | admin, manager, supervisor | Regenerate QR code |
| PATCH | `/api/v1/students/{student}/status` | admin, manager, supervisor | Change enrollment status |
| POST | `/api/v1/students/{student}/wallet/top-up` | admin, manager, supervisor | Wallet top-up |
| POST | `/api/v1/students/{student}/credit/settle` | admin, manager | Settle outstanding credit |
| GET | `/api/v1/students/{student}/payments` | admin, manager, supervisor | Monthly payment records |
| PATCH | `/api/v1/students/{student}/payments/{month}` | admin, manager | Toggle month paid/unpaid |
| PUT | `/api/v1/branch-monthly-amounts/{month}` | admin | Override monthly amount per branch |

---

## Requirements

- [ ] `students` table with all fields including `student_type`, `points`, `total_spent`, `credit_balance`
- [ ] `student_contacts` table (one-to-many from student)
- [ ] `student_monthly_payments` table with UNIQUE KEY on `(student_id, school_month)`
- [ ] `branch_monthly_amounts` table with UNIQUE KEY on `(branch_id, school_month)`
- [ ] `credit_transactions` table with `type` enum (Charged/Settled/Voided — TitleCase)
- [ ] `HasBranch` trait on `Student` model
- [ ] `student_number` manually entered by staff — school-assigned ID; validated unique per branch
- [ ] Auto-generate unique `qr_code` on creation: `'SB-' . Str::random(12)` with collision retry loop
- [ ] QR regeneration: Admin/Manager/Supervisor; old token immediately invalidated; activity log entry (values not logged)
- [ ] Wallet created via `bavix/laravel-wallet` on student enrollment
- [ ] Points tracking: 1 point per ₱1,000 cumulative spend — incremented at POS checkout
- [ ] Enrollment form at `POST /api/v1/enrollment` — all validation, monthly payment seeding for subscription
- [ ] Enrollment API response includes: student_number, qr_code (for display/print) — no email/password credentials
- [ ] Student list endpoint with filters: search, grade, enrollment status, month+payment filter (subscription only), type
- [ ] Default sort: alphabetical by last_name then first_name
- [ ] Subscription student: month badges (Jun–Mar) with toggle paid/unpaid
- [ ] Non-subscription student: wallet-only info, no monthly payments
- [ ] Admin can override monthly amounts per branch via `branch_monthly_amounts`
- [ ] Student status change with role enforcement; reason required for `banned`/`unenrolled`
- [ ] Wallet top-up (Admin/Manager/Supervisor only): amount, payment method, reference number (alphanumeric max 50 chars if provided), live new-balance preview
- [ ] Student detail response with Profile, Wallet, Order History, Payment, and Logs tabs
- [ ] `credit_balance` is a cached aggregate — all credit changes go through `credit_transactions` insert + atomic `credit_balance` update in same `DB::transaction()`
- [ ] Admin/Manager only can settle credit (Supervisor excluded) — enforced in `StudentPolicy::settleCredit()`
- [ ] Mark as Settled: inserts `credit_transactions` (type=Settled), atomically sets `credit_balance = 0` with `lockForUpdate()`
- [ ] Student card shows red "₱X Credit Owed" badge when `credit_balance > 0`
- [ ] Freetext fields (notes, allergies): `strip_tags()` server-side before storage; rendered as plain text in UI
- [ ] GCash/Bank reference number: alphanumeric max 50 chars, server-side validation if provided
- [ ] Student photo upload: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation
- [ ] `CREDIT_LIMIT` constant (₱300) defined in `config/sunbites.php`
- [ ] Export students list to Excel (Admin/Manager only); explicit field allowlist — no government ID fields
- [ ] Soft delete with retention of order history
- [ ] `StudentPolicy` covering view, create, update, delete, top-up, settleCredit
- [ ] Log `students.enrolled` (properties: student_type, branch)
- [ ] Log `students.updated` (dirty-tracked via `LogsActivity` trait)
- [ ] Log `students.status_changed` (properties: old_status, new_status, reason)
- [ ] Log `students.deleted` on soft delete
- [ ] Log `wallet.topped_up` (properties: amount, payment_method, reference, new_balance)
- [ ] Log `wallet.inline_reload` on POS inline reload (properties: amount, payment_method, cashier, order context)
- [ ] Log `wallet.credit_settled` (properties: amount_settled, settled_by)
- [ ] Log `payments.recorded` (properties: school_month, status, amount, recorded_by)
