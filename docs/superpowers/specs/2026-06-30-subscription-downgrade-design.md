# Subscription → Non-Subscription Downgrade

**Date:** 2026-06-30
**Status:** Approved for implementation

---

## Problem

Switching a student from subscription to non-subscription currently only flips `student_type`. It leaves no-longer-valid monthly payment records in the database, causes stale UI in the student list (wrong type badge persists), and has no handling for payments that were made in advance. This spec covers the full fix across the API, POS, and portal.

---

## Business Rules

1. **Unpaid monthly payments** (past, current, or future) — **hard deleted** during the downgrade. They represent unfulfilled obligations that no longer apply.
2. **Paid past months** — **cannot be voided**. The student already consumed those meals; the payment is settled and historical only.
3. **Paid current month** — **can be voided** by staff after the downgrade (student may not have used the full month).
4. **Paid future months** — **can be voided** by staff after the downgrade (advance payments with no consumption yet).
5. Voiding a paid month does **not** automatically credit the wallet. Staff must separately use the existing wallet top-up to transfer the refund amount.
6. The reverse path (non-subscription → subscription) is unchanged: staff changes the type and uses the existing "add payment range" flow to seed monthly payments.

---

## Database Changes

**One new migration** on `student_monthly_payments`:

```php
$table->timestamp('voided_at')->nullable()->after('recorded_by');
$table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete()->after('voided_at');
$table->string('void_reason')->nullable()->after('voided_by');
```

`status` gains a third valid string value: `"voided"`. The column is already a plain string so no enum migration is needed — only validation rules need updating.

The unique constraint `(student_id, school_month, year)` is unchanged; voided records are still distinct per student per month per year.

---

## API Changes

### 1. Preview endpoint

**`GET /api/v1/students/{student}/subscription-downgrade-preview`**

Roles: `admin`, `manager`, `supervisor`

Returns a read-only impact summary. No side effects.

**Response shape:**
```json
{
  "paid_months_retained": [
    { "id": 1, "school_month": "may", "year": 2026, "amount": 2970, "label": "May 2026" }
  ],
  "paid_voidable_months": [
    { "id": 5, "school_month": "june", "year": 2026, "amount": 2970, "label": "June 2026" },
    { "id": 6, "school_month": "july", "year": 2026, "amount": 2970, "label": "July 2026" }
  ],
  "unpaid_months_to_delete": ["June 2026", "August 2026"],
  "unpaid_months_to_delete_count": 2,
  "wallet_balance": 500.00
}
```

- `paid_months_retained` — paid records for months before the current school month (cannot be voided)
- `paid_voidable_months` — paid records for the current school month and future months (can be voided later)
- `unpaid_months_to_delete` — list of month labels that will be hard deleted on downgrade
- `wallet_balance` — current wallet balance so staff can see context when deciding on refunds

"Current school month" is determined by `SchoolMonth::fromMonthNumber(now()->month)`.

---

### 2. Execute downgrade endpoint

**`POST /api/v1/students/{student}/downgrade-subscription`**

Roles: `admin`, `manager`

No request body required.

**Logic (single DB transaction):**
1. Validate the student's current `student_type` is `subscription`. Return `422` if already non-subscription.
2. Hard delete all `StudentMonthlyPayment` records where `status = "unpaid"`.
3. Leave all `status = "paid"` records untouched.
4. Update `student_type` to `non_subscription`.
5. Log activity `students.downgraded_to_non_subscription` with properties:
   ```json
   {
     "deleted_months": ["June 2026", "August 2026"],
     "deleted_count": 2,
     "paid_months_retained": ["May 2026", "June 2026", "July 2026"],
     "note": "Unpaid monthly payments were removed. Paid months are retained for history."
   }
   ```

**Response:** Updated `StudentResource`.

---

### 3. Void a paid payment

**`PATCH /api/v1/students/{student}/payments/{payment}/void`**

Roles: `admin`, `manager`

**Request body:**
```json
{ "reason": "Student switched to non-subscription mid-month. Refund issued separately." }
```
`reason` is required, string, max 500 characters.

**Validation:**
- The `payment` must belong to the `student` (or return `404`).
- `status` must be `"paid"` (cannot void an already-voided or unpaid record).
- The payment's `(school_month, year)` must be **>=** the current school month/year. If it's a past month, return:
  ```json
  { "message": "Cannot void a past month's payment — this subscription period has already been consumed." }
  ```
  HTTP `422`.

**Logic:**
1. Set `status = "voided"`, `voided_at = now()`, `voided_by = auth user id`, `void_reason = reason`.
2. Log activity `student_payment.voided` with properties:
   ```json
   {
     "school_month": "june",
     "year": 2026,
     "amount": 2970,
     "reason": "..."
   }
   ```

**Response:** Updated payment record.

---

### 4. Billing Report (`BillingReportController`)

`buildQuery()` adds `.where('status', '!=', 'voided')` by default so voided records are excluded from all billing summaries and exports.

The `status` filter validation expands from `'in:paid,unpaid'` to `'in:paid,unpaid,voided'`. Staff can explicitly filter by `status=voided` to audit voided records.

The summary card labels and collection rate calculation are unaffected since voided records are excluded from totals.

---

### 5. Subscription Report (`SubscriptionReportController`)

The response gains a second key `historical_data` alongside the existing paginated `data`.

`historical_data` is a non-paginated array of ex-subscription students (now `non_subscription`) who have a `paid` `StudentMonthlyPayment` record for the requested month/year. These are students who paid in advance and then switched.

**`historical_data` row shape:**
```json
{
  "id": 7,
  "full_name": "Erik Baumbach",
  "student_number": "SB-001",
  "grade_level": "Grade 3",
  "section": null,
  "payment_amount": 2970
}
```

Query: `Student` where `student_type = non_subscription` with a `whereHas('monthlyPayments')` for `status = paid`, `school_month = $monthEnum`, `year = $year`.

No meal usage columns — these students are no longer on subscription so allocation tracking doesn't apply.

---

### 6. Portal payment history (`StudentPaymentHistoryController`)

Remove the `abort_unless(StudentType::Subscription)` guard. Parents of ex-subscription students should still be able to see their paid history.

Add a filter to exclude voided records from the response: `.where('status', '!=', 'voided')`.

Add voided records as a separate optional fetch if needed in the future — out of scope for this spec.

---

## POS Frontend Changes (`~/sunbites-pos`)

### `lib/api/students.ts`

Add two new API methods:

```typescript
downgradeSubscriptionPreview: (id: number) =>
  apiClient.get<DowngradePreview>(`/students/${id}/subscription-downgrade-preview`),

downgradeSubscription: (id: number) =>
  apiClient.post<Student>(`/students/${id}/downgrade-subscription`),

voidPayment: (studentId: number, paymentId: number, reason: string) =>
  apiClient.patch<MonthlyPayment>(`/students/${studentId}/payments/${paymentId}/void`, { reason }),
```

### `types/student.ts`

Add types:

```typescript
export interface DowngradePreviewMonth {
  id: number;
  school_month: SchoolMonth;
  year: number;
  amount: number;
  label: string;
}

export interface DowngradePreview {
  paid_months_retained: DowngradePreviewMonth[];
  paid_voidable_months: DowngradePreviewMonth[];
  unpaid_months_to_delete: string[];
  unpaid_months_to_delete_count: number;
  wallet_balance: number;
}
```

Update `MonthlyPayment.status` type from `"paid" | "unpaid"` to `"paid" | "unpaid" | "voided"`.

### `app/(kitchen)/students/[id]/page.tsx` — Downgrade flow

Replace the existing `ChangeTypeDialog` behavior for the subscription → non-subscription direction:

1. Staff clicks "Change" next to the type badge.
2. If current type is `subscription` and new type is `non_subscription`: open a new `DowngradeConfirmDialog`.
3. `DowngradeConfirmDialog` immediately calls `downgradeSubscriptionPreview` and shows a loading skeleton while it fetches.
4. Dialog displays:
   - **"Months to be deleted"** — count of unpaid months, listed by name
   - **"Paid months that will be kept"** — list of retained paid records (non-voidable history)
   - **"Paid months you can void later"** — list of current/future paid months, with a note that staff can void them individually from the payments tab
   - **Current wallet balance**
   - A warning if `paid_voidable_months` is non-empty: *"These paid months can be voided individually from the Payments tab. Use the wallet top-up to issue any refund."*
5. Staff confirms. Calls `downgradeSubscription(studentId)`.
6. On success, invalidate **all four** query keys:
   - `["student", studentId]`
   - `["student-payments", studentId]`
   - `["students", "subscription"]`
   - `["students", "non_subscription"]`

If current type is `non_subscription` → `subscription`: keep existing simple `updateType` flow unchanged.

### `app/(kitchen)/students/[id]/page.tsx` — Payments tab

Payment rows currently show `"Paid"` or `"Unpaid"` badges. Add voided rendering:

```tsx
// Voided badge
<span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-muted text-muted-foreground border-border line-through">
  Voided
</span>
```

For voided rows, show `void_reason` as a muted sub-line below the badge. Hide the "Toggle" and "Edit Amount" actions for voided rows.

Add a "Void Payment" button on current/future paid rows (admin/manager only). Clicking opens a small confirmation dialog with a required reason textarea. Calls `voidPayment()`. On success invalidates `["student-payments", studentId]` and `["student", studentId]`.

### `app/(kitchen)/reports/subscription/page.tsx`

Below the existing paginated table, add a collapsible **"Former Subscribers"** section. Collapsed by default, shown as a button: *"Show X former subscribers with paid records for this month"* (hidden if `historical_data` is empty).

When expanded, renders a simplified table:

| Student | Grade | Amount |
|---------|-------|--------|
| Erik Baumbach (SB-001) | Grade 3 | ₱2,970 |

With a muted "Switched" pill badge in the student cell. No usage columns.

### `app/(kitchen)/reports/billing/page.tsx`

Add `"Voided"` to the status filter dropdown (alongside Paid / Unpaid). The API already filters out voided by default; selecting `status=voided` shows the voided audit records.

Billing payment rows add a voided rendering: muted text, strikethrough on amount, "Voided" badge.

---

## Portal Frontend Changes (`~/sunbites-portal`)

### `app/(portal)/students/[id]/_components/payment-history-tab.tsx`

Update the `PaymentHistoryEntry` type status to include `"voided"`.

Add a voided row style: light gray background, strikethrough amount, "Voided" badge. The paid date column shows the `voided_at` date for voided rows with a label *"Voided on"*.

### `app/(portal)/dashboard/_components/payment-history-timeline.tsx`

Filter voided payments from the last-5-months grid:
```typescript
const payments = data?.filter(p => p.status !== "voided") ?? [];
```
Voided months simply appear as "Not Recorded" (missing entry) in the timeline, which is accurate since the obligation was cancelled.

### `types/notification.ts` or `types/portal.ts`

Update `PaymentHistoryEntry.status` from `"paid" | "unpaid"` to `"paid" | "unpaid" | "voided"`.

---

## Out of Scope

- Automatic wallet credit on void — staff issues refunds manually via existing top-up.
- Proration calculation — staff decides the refund amount independently.
- Re-seeding payments when switching back to subscription — staff uses existing "add payment range" flow.
- Void history visible to parents — portal only shows non-voided paid history for now.

---

## Test Coverage

### Backend (PHPUnit)

- `test_admin_can_preview_subscription_downgrade` — preview returns correct paid/unpaid split
- `test_downgrade_deletes_unpaid_payments_and_changes_type` — all unpaid deleted, type changed, paid untouched
- `test_downgrade_logs_activity_with_deleted_months`
- `test_downgrade_fails_if_student_is_not_subscription`
- `test_admin_can_void_current_month_paid_payment`
- `test_admin_can_void_future_month_paid_payment`
- `test_cannot_void_past_month_paid_payment` — returns 422
- `test_cannot_void_unpaid_payment` — returns 422
- `test_billing_report_excludes_voided_by_default`
- `test_billing_report_can_filter_by_voided_status`
- `test_subscription_report_includes_historical_section_for_ex_subscribers`
- `test_portal_payment_history_accessible_after_type_switch`
- `test_portal_payment_history_excludes_voided_records`
- `test_supervisor_cannot_execute_downgrade` — 403

### Frontend (Jest + RTL)

- `DowngradeConfirmDialog` renders preview data correctly
- `DowngradeConfirmDialog` shows skeleton while loading preview
- Confirming downgrade calls `downgradeSubscription` and invalidates all four query keys
- Voided payment row renders with strikethrough and Voided badge
- Void Payment button hidden for past months (voided or paid)
- "Former Subscribers" section hidden when `historical_data` is empty
- "Former Subscribers" section renders correctly when populated
