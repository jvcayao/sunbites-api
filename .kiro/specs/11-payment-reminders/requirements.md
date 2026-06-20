# Spec 11 — Payment Reminders

## Introduction

Payment reminders allow Admin and Manager staff to manually send advance payment notifications to parents of subscription students before the start of each school month. Parents receive these reminders as in-app notifications via the notification system established in Spec 10.

This spec also covers the payment history view in the parent portal, which gives parents visibility into their children's monthly subscription payment status.

**Depends on:** Spec 09 (System Configuration — `payment_reminder_days`), Spec 10 (Notifications — Reverb, notifications table, `PaymentReminderNotification` broadcast channel)

---

## Business Rules

### Reminder Window

- The reminder window for a school month opens `payment_reminder_days` days before the 1st calendar date of that month (default: 14 days).
- Example: for August, if `payment_reminder_days = 14`, the window opens July 18.
- Only parents of **subscription students** in the active branch qualify.
- Non-subscription students' parents must never receive payment reminders.

### Sending

- Admin or Manager selects one or more eligible parents and clicks "Send Reminder".
- Supervisors can view the Reminders page but cannot send — the Send button is hidden for their role.
- If a selected parent was already sent a reminder for the current month, a duplicate warning is shown. Staff can proceed or cancel.
- One notification per parent per send — not one per student. The notification payload lists all subscription students enrolled in the branch for that parent, with each student's `StudentMonthlyPayment.amount` for that month (never recomputed from config).
- A `parent_payment_reminders` record is created per parent per send to track duplicate prevention.

### POS Bell

- The sidebar nav shows a `ReminderBell` with the count of eligible parents not yet sent a reminder for the upcoming school month.
- The `ReminderBell` lives in the sidebar nav (not in the header). The header has only one bell — the `NotificationBell` for inbound staff notifications (Spec 10).

---

## Data Model

### `parent_payment_reminders` table

```
parent_payment_reminders
  id
  parent_user_id    (FK → parents.id)
  branch_id         (FK → branches.id)
  school_month      (string)     — 'june' | 'july' | ... | 'march'
  school_year       (integer)    — calendar year when the month falls
  sent_at           (timestamp)
  sent_by_user_id   (FK → users.id)
  send_count        (integer)    — incremented on each resend
  created_at, updated_at

  UNIQUE (parent_user_id, branch_id, school_month, school_year)
```

### `system_configurations` addition

| Key | Default | Type | Label |
|---|---|---|---|
| `payment_reminder_days` | `14` | integer | Payment Reminder Days |

---

## Requirements

### Requirement 1 — Payment Reminder Notification Class

**User Story:** As the system, I need a `PaymentReminderNotification` class that broadcasts to parents and persists to the database.

#### Acceptance Criteria

1. WHEN `PaymentReminderNotification` is dispatched THEN it SHALL write to the `notifications` table (via `database` channel) and broadcast on `PrivateChannel("parents.{$parent->id}")` (via `broadcast` channel).
2. WHEN the notification payload is built THEN `toDatabase()` SHALL include: `school_month`, `school_year`, `due_date` (1st of the school month as ISO date), `total_amount` (sum of all student amounts), and `students` array (each entry: `name`, `amount` sourced exclusively from `StudentMonthlyPayment.amount` — never recomputed).
3. WHEN the portal bell receives a `PaymentReminderNotification` broadcast event THEN it SHALL invalidate the `unread-count` query, updating the badge in real time.

---

### Requirement 2 — Eligible Parent Detection (Bell Count)

**User Story:** As a staff member, I want to see how many parents still need a payment reminder so that I know my workload before the month starts.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reminders/bell-count` THEN the system SHALL return `{ count: N }` where N is the number of subscription parents in the active branch not yet sent a reminder for the upcoming school month.
2. WHEN the upcoming school month is determined THEN the system SHALL find the next school month whose 1st calendar date is within `payment_reminder_days` days and has not yet started.
3. IF no school month falls within the window THEN the system SHALL return `{ count: 0 }`.
4. WHEN a parent has already been sent a reminder for the upcoming month THEN they SHALL NOT be counted (even if they have unpaid balances).

---

### Requirement 3 — Eligible Parents List

**User Story:** As an Admin, Manager, or Supervisor, I want to see a list of parents who need a payment reminder so that I can select who to notify.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reminders/eligible-parents` THEN the system SHALL return a paginated list of parents in the active branch who have at least one subscription student.
2. WHEN returning eligible parents THEN each entry SHALL include: parent name, email, phone, linked subscription students (name, grade, amount for the upcoming month), and a `sent` flag indicating whether a reminder has already been sent for the upcoming month.
3. WHEN `sent = true` THEN the row SHALL be returned but shown as already-notified in the UI (not selectable by default).
4. WHERE a parent has students in multiple branches THEN only students in the **active branch** SHALL be included.

---

### Requirement 4 — Send Reminders

**User Story:** As an Admin or Manager, I want to send payment reminders to selected parents so that they are notified before the month starts.

#### Acceptance Criteria

1. WHEN `POST /api/v1/reminders/send` is called with `parent_ids[]` THEN the system SHALL create a `ParentPaymentReminder` record per parent and dispatch `PaymentReminderNotification` to each.
2. WHEN a parent in the selection was already sent a reminder for the current upcoming month THEN the system SHALL still process them if `force: true` is passed; otherwise skip them and include their names in a `skipped_names` array in the response.
3. WHEN the send completes THEN the response SHALL include `{ sent: N, skipped: M, skipped_names: [...] }`.
4. IF a `parent_id` in the list does not belong to the active branch THEN it SHALL be silently skipped.
5. IF the authenticated staff has role `supervisor` or `cashier` THEN the system SHALL return 403.

---

### Requirement 5 — Reminder Detail Page

**User Story:** As an Admin, Manager, or Supervisor, I want to view a parent's full detail including their subscription students' payment history so that I can confirm their payment status before or after sending a reminder.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reminders/parents/{parent}` THEN the system SHALL return: parent contact info, all linked subscription students in the active branch, and each student's full `StudentMonthlyPayment` history (month, year, amount, status, paid date).
2. IF the parent has no students in the active branch THEN the system SHALL return an empty students array.
3. WHERE branch scoping is applied THEN the response SHALL contain only students enrolled in the active branch.

---

### Requirement 6 — Portal Payment History

**User Story:** As a parent of a subscription student, I want to see my child's monthly payment history in the portal so that I can confirm payments have been recorded.

#### Acceptance Criteria

1. WHEN a parent requests `GET /api/v1/portal/students/{student}/payment-history` THEN the system SHALL return all `StudentMonthlyPayment` records for that student, ordered by year ASC then month order ASC.
2. WHEN returning payment records THEN each entry SHALL include: `school_month`, `year`, `amount`, `status` (paid/unpaid), `recorded_at` (nullable).
3. IF the student is not a subscription student THEN the system SHALL return an empty `data` array.
4. IF the authenticated parent does not have the student in their `parent_student` pivot THEN the system SHALL return 404.
5. WHERE a portal student detail page shows a "Payment History" tab THEN it SHALL be visible only for subscription students.

---

## API Routes

### Kitchen (`auth:sanctum` + ability `staff`)

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/reminders/bell-count` | all staff | Count of eligible unsent parents in active branch |
| GET | `/api/v1/reminders/eligible-parents` | admin\|manager\|supervisor | Paginated eligible parent list, branch-scoped |
| POST | `/api/v1/reminders/send` | admin\|manager | Send reminders; returns sent/skipped counts |
| GET | `/api/v1/reminders/parents/{parent}` | admin\|manager\|supervisor | Parent detail + student payment history |

### Portal (`auth:parents` + ability `parent`)

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/portal/students/{student}/payment-history` | Subscription payment history for a student |

---

## Requirements Checklist

### Backend

- [x] Migration: `parent_payment_reminders` table with UNIQUE constraint on `(parent_user_id, branch_id, school_month, school_year)` and short index name `ppr_unique`
- [x] `App\Models\ParentPaymentReminder` model with `$fillable` and relationships
- [x] `payment_reminder_days` (integer, 14) added to `SystemConfigurationSeeder`
- [x] `App\Notifications\PaymentReminderNotification` implementing `ShouldQueue` + `ShouldBroadcast`:
  - `via()` returns `['database', 'broadcast']`
  - `toDatabase()` includes `school_month`, `school_year`, `due_date`, `total_amount`, `students[]` (name + amount from `StudentMonthlyPayment.amount`)
  - `broadcastOn()` returns `PrivateChannel("parents.{$this->parent->id}")`
  - `broadcastAs()` returns `'PaymentReminderNotification'`
- [x] `App\Http\Controllers\Kitchen\ReminderController`:
  - `bellCount()` — reads `payment_reminder_days`, determines upcoming school month, counts eligible unsent parents
  - `eligibleParents()` — paginated, branch-scoped, subscription parents; each entry includes `sent` flag
  - `send()` — validates `parent_ids[]` and optional `force`; creates `ParentPaymentReminder` + dispatches notification; returns sent/skipped
  - `show()` — parent detail + linked subscription students + payment history; branch-scoped
- [x] `App\Http\Controllers\Portal\StudentPaymentHistoryController` — validates parent owns student via `parent_student` pivot; returns ordered payments
- [x] 4 reminder routes registered in `routes/kitchen-api.php`
- [x] 1 payment history route registered in `routes/portal-api.php`
- [x] Feature tests: `ReminderTest` (kitchen, 12 tests pass)
- [x] Feature tests: `StudentPaymentHistoryTest` (portal, 4 tests pass)
- [x] Full suite green

### Frontend POS (`~/sunbites-pos`)

- [x] `types/reminder.ts` — `EligibleParent`, `ReminderSendResult`, `ReminderParentDetail` types
- [x] `lib/api/reminders.ts` — `reminderApi.bellCount()`, `eligibleParents(params)`, `send(parentIds, force?)`, `parentDetail(id)`
- [x] `components/reminder-bell.tsx` — shows count from `bellCount()`; navigates to `/reminders` on click; visible to admin|manager|supervisor
- [x] `ReminderBell` added as nav item in `kitchen-layout.tsx` sidebar (NOT in the header); visible to admin|manager|supervisor only
- [x] `app/(kitchen)/reminders/page.tsx` — eligible parents list with checkboxes, `sent` rows grayed/disabled, "Select all unsent" checkbox, `Send ({N})` button, duplicate warning dialog, success toast with sent/skipped counts
- [x] `app/(kitchen)/reminders/loading.tsx` — skeleton
- [x] `app/(kitchen)/reminders/[parentId]/page.tsx` — parent contact info + linked subscription students + payment history table per student
- [x] `app/(kitchen)/reminders/[parentId]/loading.tsx` — skeleton

### Frontend Portal (`~/sunbites-portal`)

- [x] `types/notification.ts` — `PaymentHistoryEntry` type (school_month, year, amount, status, recorded_at)
- [x] `lib/api/portal.ts` — `studentsApi.paymentHistory(studentId)` calling `GET /portal/students/{id}/payment-history`
- [x] Student detail page (`app/(portal)/students/[id]/page.tsx`) — "Payment History" tab visible only for subscription students; table: Month | Amount | Status badge | Paid Date; ordered by year then month
