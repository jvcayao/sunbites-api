# Spec 11 — Payment Reminders Tasks

## Task 1: Database & Configuration

- [x] 1.1 Migration: `parent_payment_reminders` table with columns `parent_user_id`, `branch_id`, `school_month`, `school_year`, `sent_at`, `sent_by_user_id`, `send_count`; UNIQUE constraint `(parent_user_id, branch_id, school_month, school_year)` with short index name `ppr_unique`
- [x] 1.2 `App\Models\ParentPaymentReminder` — `$fillable`, relationships to `ParentUser`, `Branch`, `User` (sentBy)
- [x] 1.3 Verify `payment_reminder_days` exists in `system_configurations` (seeded in Spec 09)
- [x] 1.4 Run Pint formatter; full suite green

---

## Task 2: PaymentReminderNotification

- [x] 2.1 Create `App\Notifications\PaymentReminderNotification` implementing `ShouldQueue` + `ShouldBroadcast`:
  - `via()` returns `['database', 'broadcast']`
  - `toDatabase()` payload: `school_month`, `school_year`, `due_date` (ISO 8601), `total_amount`, `students[]` (name + amount from `StudentMonthlyPayment.amount` — never recomputed)
  - `broadcastOn()` returns `PrivateChannel("parents.{$this->parent->id}")`
  - `broadcastAs()` returns `'PaymentReminderNotification'`
- [x] 2.2 Run Pint formatter

---

## Task 3: Backend — Reminder Endpoints

- [x] 3.1 Create `App\Http\Controllers\Kitchen\ReminderController`:
  - `bellCount()` — reads `payment_reminder_days` from `SystemConfiguration`; determines upcoming school month using calendar year logic; counts eligible unsent parents in active branch; returns `{ count: N }`
  - `eligibleParents()` — paginated, branch-scoped; subscription parents only; each entry includes `sent` flag for the upcoming month
  - `send()` — validates `parent_ids[]` array; optional `force` flag; creates `ParentPaymentReminder` records; dispatches `PaymentReminderNotification` per parent; returns `{ sent, skipped, skipped_names }`
  - `show()` — parent detail + linked subscription students in active branch + per-student `StudentMonthlyPayment` history
- [x] 3.2 Register 4 routes in `routes/kitchen-api.php` under `role:admin|manager|supervisor` (send: `role:admin|manager`)
- [x] 3.3 Run Pint formatter

---

## Task 4: Backend — Portal Payment History Endpoint

- [x] 4.1 Create `App\Http\Controllers\Portal\StudentPaymentHistoryController`:
  - `index(Student $student)` — validates parent owns student via `parent_student` pivot (404 if not); returns all `StudentMonthlyPayment` records ordered by year ASC then month ASC; returns empty array for non-subscription students
- [x] 4.2 Register `GET /api/v1/portal/students/{student}/payment-history` in `routes/portal-api.php`
- [x] 4.3 Run Pint formatter

---

## Task 5: Backend Tests

- [x] 5.1 `tests/Feature/Kitchen/ReminderTest.php` — 12 tests:
  - bell count with/without upcoming month in window
  - eligible parents list (subscription only, branch-scoped)
  - send happy path: ParentPaymentReminder created, notification dispatched
  - send with `force: false` — already-sent parents skipped; `skipped_names` returned
  - send with `force: true` — already-sent parents re-notified
  - supervisor cannot send (403)
  - parent show: returns correct students and payment history
- [x] 5.2 `tests/Feature/Portal/StudentPaymentHistoryTest.php` — 4 tests:
  - happy path subscription student
  - non-subscription student returns empty array
  - parent does not own student: 404
  - ordered by year then month
- [x] 5.3 Full suite green

---

## Task 6: Frontend POS — Reminders

- [x] 6.1 `types/reminder.ts` — `EligibleParent` (with `sent` flag), `ReminderSendResult`, `ReminderParentDetail` types
- [x] 6.2 `lib/api/reminders.ts` — `reminderApi.bellCount()`, `eligibleParents(params)`, `send(parentIds, force?)`, `parentDetail(id)`
- [x] 6.3 `components/reminder-bell.tsx` — reads `bellCount()` via `useQuery`; shows count badge; navigates to `/reminders`; visible to admin|manager|supervisor
- [x] 6.4 `ReminderBell` added as nav sidebar item in `kitchen-layout.tsx` (between Students and Reports group); NOT in the header; visible to admin|manager|supervisor
- [x] 6.5 `app/(kitchen)/reminders/page.tsx`:
  - Upcoming month label and days-until header
  - Table: checkbox | parent name | students | sent status
  - "Sent ✓" rows grayed, checkbox disabled
  - "Select all unsent" checkbox in header
  - `Send ({N})` button disabled when 0 selected; hidden for supervisor role
  - Duplicate warning dialog: lists already-sent names; [Cancel] / [Send to all anyway]
  - `useMutation` → `send(selectedIds, force)` → success toast with sent/skipped summary
- [x] 6.6 `app/(kitchen)/reminders/loading.tsx` — skeleton
- [x] 6.7 `app/(kitchen)/reminders/[parentId]/page.tsx` — parent contact card; subscription students accordion; payment history table per student (Month | Amount | Status | Paid Date)
- [x] 6.8 `app/(kitchen)/reminders/[parentId]/loading.tsx` — skeleton; POS lint 0 errors

---

## Task 7: Frontend Portal — Payment History Tab

- [x] 7.1 `types/notification.ts` — add `PaymentHistoryEntry` type (school_month, year, amount, status, recorded_at)
- [x] 7.2 `lib/api/portal.ts` — add `paymentHistory(studentId)` to `studentsApi`; calls `GET /portal/students/{id}/payment-history`
- [x] 7.3 Student detail page (`app/(portal)/students/[id]/page.tsx`) — add "Payment History" tab; visible only when `student.student_type === 'subscription'`; table: Month Year | Amount | Status badge (paid=green, unpaid=red) | Paid Date (or "—"); ordered by year then month
- [x] 7.4 Portal lint: 0 errors
