# Spec 16: Contract gaps (T-1.7)

Recorded during T-1, after the contract fixtures in `tests/Fixtures/contracts/` were frozen (MySQL gate, then the SQLite override pass). Each row compares a recorded backend shape with the TypeScript type the frontend uses for that call.

**Nothing in this file is fixed in Track A.** A row whose last column names a Track B item is already planned; `new` means the gap was found during T-1 and has no item yet; `none: document only` means the gap has no visible effect today.

How the comparison was made:
- Backend: every `@ok*` fixture, flattened to `path: shape`.
- Frontend: every `apiClient.(get|post|put|patch|delete)<T>` call in `~/sunbites-pos/lib/api` and `~/sunbites-portal/lib/api` (155 typed calls), followed to `types/*.ts` or the inline interface. Where a gap could affect a page, the consuming component was opened and the impact below is what that code does with the value.
- Extra backend keys that the frontend type does not declare are **not** listed; TypeScript ignores them.

## 1. Pre-verified gaps (design §4.1.6)

| Route | Field | Backend shape | FE type (file:line) | UI impact | Proposed Track B item |
|---|---|---|---|---|---|
| `GET api/v1/reports/sales` | `data[].student.full_name` | absent: raw `Student` serialisation has no `full_name` | `SalesOrder.student.full_name: string` (`sunbites-pos/lib/api/reports.ts:89`) | Student column blank (`reports/sales/page.tsx:391`) | B-13 |
| `POST api/v1/pos/checkout` (+ `pos/transactions`, `pos/transactions/{order}/void`, `students/{student}/orders`) | `order.cashier.name` | always `null` (`User` has no `name` column) | `OrderCashier.name: string` (`sunbites-pos/types/order.ts:95`) | Receipt cashier blank (`components/pos/receipt-modal.tsx:72`) | B-07 |
| `GET api/v1/reports/sales`, `GET api/v1/reports/billing` | `cashier` / `recorder` / `student` | full models incl. `daily_rate`, `phone`, `birthday`, address and emergency-contact fields, `qr_code` | FE reads only names and ids | PII over-exposure | B-12 |
| `GET api/v1/references/inventory` | archived rows | never returned (`InventoryItem::active()`) | `references/inventory/page.tsx:894-895` expects them for the Archived section | Unarchive unreachable | B-16 |
| many | decimal fields | `"string:decimal2"` | typed `number` | none today (UI parses with `Number()`/`parseFloat`) | none: document only (see §4) |

## 2. Gaps found in T-1

### 2.1 Visible today

| Route | Field | Backend shape | FE type (file:line) | UI impact | Proposed Track B item |
|---|---|---|---|---|---|
| `GET api/v1/reports/inventory/export` | whole response | **500** for every request: `Excel::download()` requires `Maatwebsite\Excel\Concerns\Export`, and `App\Exports\InventoryReportExport` implements only `WithMultipleSheets`, which does not extend `Export` in the installed `maatwebsite/excel` 4.x-dev | `lib/api/reports.ts:16` (`exportReport`) | Inventory report "Export" fails. The only existing test covers the supervisor 403, so no test exercises a successful export. Recorded as `@ok` with status 500 (design §4.1.4 rule 7) | B-19 |
| `GET api/v1/reports/inventory` | current-stock rows | under `items` | `data: InventoryReportRow[]` (`sunbites-pos/lib/api/reports.ts:381`); page reads `data?.data` (`app/(kitchen)/reports/inventory/page.tsx:236`) | Current Stock table is always empty | Spec 17 F-1 (frontend) |
| `GET api/v1/reports/inventory` | `items[].status` | `"OUT"` / `"LOW"` / `"OK"` / `"OVER"` (uppercase, `InventoryItem::status`) | `InventoryReportStatus = "out" \| "low" \| "ok" \| "over"` (`sunbites-pos/lib/api/reports.ts:163`); compared at `reports/inventory/page.tsx:244, 395-396` | Status filter never matches; status colours never apply | Spec 17 F-1 (frontend) |
| `GET api/v1/pre-registrations/{preRegistration}`, `PATCH api/v1/pre-registrations/{preRegistration}` | `data.birthday` | `"string:iso8601_micro"` (`2016-02-14T00:00:00.000000Z`, `date` cast through `toArray()`) | `PreRegistrationDetail.birthday: string` (`sunbites-pos/types/pre-registration.ts:29`), bound to `<Input type="date">` (`app/(kitchen)/pre-registrations/[id]/page.tsx:497-499`) | Birthday field renders empty in the edit form (also after each save, `page.tsx:243-245` re-seeds the form from the PATCH response) | B-22 (backend cast, no FE change) |
| `GET api/v1/portal/dashboard` | `students[].credit_balance` | absent | `DashboardData.students: StudentSummary[]` (`sunbites-portal/types/portal.ts:52`), read at `app/(portal)/dashboard/page.tsx:78` | The dashboard's "credit owed" badge never shows | B-21 |
| `GET api/v1/portal/students/{student}/wallet` | `data[].amount` | `"string:decimal2"` (bavix `amountFloat`) | `Transaction.amount: number` (`sunbites-portal/types/portal.ts:85`); `formatPHP(amount: number)` calls `amount.toLocaleString(...)` (`lib/format.ts:5-9`) | Amounts of ₱1,000 or more lose the thousands separator (`PHP 1500.00`) | Spec 17 F-2 (frontend) |

### 2.2 No visible effect today

| Route | Field | Backend shape | FE type (file:line) | UI impact | Proposed Track B item |
|---|---|---|---|---|---|
| `GET api/v1/pos/transactions` | `summary.total_revenue` | `number` | `TransactionSummary.total_revenue: string` (`sunbites-pos/types/order.ts:183`) | none (`parseFloat(...)` at `components/pos/transaction-history-tab.tsx:123`) | none: document only |
| `GET api/v1/students`, `GET api/v1/students/{student}` | `contacts[].email` | `"null"` for contacts without email | `StudentContact.email: string` (`sunbites-pos/types/student.ts:24`) | none found | none: document only |
| `GET api/v1/students/{student}` | `activity_logs[].properties` | `list:empty` (`[]`) when a log has no properties, else an object | `properties: Record<string, unknown>` (`sunbites-pos/types/student.ts:109`) | none found | none: document only |
| `GET api/v1/auth/user`, `POST api/v1/auth/login` | `full_name` / `user.full_name` | absent | `AuthUser.full_name: string` (`sunbites-pos/types/auth.ts:11`) | none (no reader of `AuthUser.full_name`; the `full_name` reads are on `StaffUser`) | none: document only |
| `PUT api/v1/branches/{branch}` | `staff_count`, `student_count`, `orders_today`, `deleted_at` | absent | `AdminBranch` (`sunbites-pos/types/branch.ts:7-10`) | none found (list is refetched) | none: document only |
| `GET api/v1/users`, `GET api/v1/users/{user}` (non-admin actor) | `daily_rate` | absent (`when($isAdmin)`) | `StaffUser.daily_rate: string \| null` (`sunbites-pos/types/user.ts:24`) | none (`user.daily_rate ?? ""` at `references/users/[id]/page.tsx:107`) | none: document only |
| `GET api/v1/references/inventory`, `GET api/v1/pos/inventory` (+ adjust/create/update responses) | `inventory_status` | absent (only menu items have it) | `InventoryItem.inventory_status` (`sunbites-pos/types/inventory.ts:14`) | none found | none: document only |
| `POST api/v1/students/{student}/contacts`, `PUT api/v1/students/{student}/contacts/{contact}` | `portal_status` | absent (raw `StudentContact` model) | `StudentContact.portal_status: PortalStatus` (`sunbites-pos/types/contact.ts:12`) | none found (contacts list is refetched) | none: document only |
| `GET api/v1/references/feedback` | `data[].student_id`, `data[].branch_id` | absent | `Feedback.student_id`, `Feedback.branch_id` (`sunbites-pos/types/feedback.ts:10-11`) | none found | none: document only |
| `GET api/v1/reminders/parents/{parent}` | `students[].payment_history[].status` | can be `"voided"` (only `paid` is excluded upstream) | `"paid" \| "unpaid"` (`sunbites-pos/types/reminder.ts:44`) | none found | none: document only |
| `PATCH api/v1/pre-registrations/{preRegistration}` | `data.duplicate_warning`, `data.existing_student_name`; `data.approved_by`/`rejected_by` | first two absent; the last two are user **ids** (raw model), not names | `PreRegistrationDetail` (`sunbites-pos/types/pre-registration.ts:45-49`) | none found (warning comes from the GET; the edit form does not show approver names) | none: document only |
| `PATCH api/v1/students/{student}/payments/{payment}`, `POST api/v1/students/{student}/payments`, `PATCH api/v1/students/{student}/payments/{payment}/void` | response body | partial objects (`{id, year, status, recorded_at}`, `{id, status, amount, recorded_at}`, `{id, status, voided_at, void_reason}`) | `MonthlyPayment` (`sunbites-pos/lib/api/students.ts:88, 155, 166`) | none found (callers refetch the payments query) | none: document only |
| `POST api/v1/students/{student}/payments/range` | `payments[]` | raw `StudentMonthlyPayment` models (no `school_month_label`, `recorded_at`, `voided_at`, `void_reason`) | `payments: MonthlyPayment[]` (`sunbites-pos/lib/api/students.ts:176`) | none (only `result.skipped` is read, `students/[id]/page.tsx:940-946`) | none: document only |
| `POST api/v1/branch-monthly-amounts`, `PUT api/v1/branch-monthly-amounts/{branchMonthlyAmount}` | response body | raw `BranchMonthlyAmount` (no `label`, `is_configured`; `amount` is `"string:decimal2"`) | `BranchMonthlyAmountConfig` (`sunbites-pos/lib/api/students.ts:190, 199`) | none found (list is refetched) | none: document only |
| `GET api/v1/branch-monthly-amounts` | `[].amount` | `"string:decimal2"` for configured months, `number` for defaults | `BranchMonthlyAmountConfig.amount: number` (`sunbites-pos/types/student.ts:167`) | none (`Number(row.amount)` at `references/subscription-config/page.tsx:364`) | none: document only |
| `GET api/v1/reports/sales` | `data[].items[].price`, `data[].discount_amount`, `data[].total` | `"string:decimal2"` | `number` (`sunbites-pos/lib/api/reports.ts:91, 93-94`) | covered by the decimal row in §1 | none: document only |
| `GET api/v1/reports/inventory` | `discrepancy[].net_change` | `number` | `net_change: string` (`sunbites-pos/lib/api/reports.ts:194`) | none found | none: document only |
| `GET api/v1/portal/profile`, `PATCH api/v1/portal/profile`, `POST api/v1/portal/auth/login` | `created_at` / `parent.created_at` | absent | `AuthParent.created_at: string` (`sunbites-portal/types/auth.ts:9`) | none found | none: document only |
| `GET api/v1/portal/dashboard` | `students[]`: `first_name`, `last_name`, `section`, `birthday`, `notes`, `qr_code`, `photo_url`, `allergies`, `subscription_monthly_status` | absent (the dashboard sends 9 fields) | `StudentSummary` (`sunbites-portal/types/portal.ts:18-39`) | none found apart from `credit_balance` (§2.1) | none: document only |
| `GET api/v1/portal/dashboard` | `recent_orders[].payment_method` | any `PaymentMethod` value (`gcash`, `subscription` too) | `"wallet" \| "cash"` (`sunbites-portal/types/portal.ts:47`) | none found | none: document only |
| `GET api/v1/portal/students` | `data[].enrollment_status` | can be `"unenrolled"` | `"enrolled" \| "paused" \| "graduated" \| "banned"` (`sunbites-portal/types/portal.ts:36`) | none found | none: document only |
| `POST api/v1/portal/feedback` | `admin_reply`, `replied_at` | absent in the 201 body | `FeedbackItem` (`sunbites-portal/lib/api/portal.ts:184`, type at `types/portal.ts:116`) | none found (list is refetched) | none: document only |

## 3. Driver divergence (`.sqlite.json` overrides)

None. The SQLite override pass (`CONTRACT_RECORD=1 CONTRACT_DRIVER_OVERRIDE=1`, SQLite gate) found no shape that differs from the MySQL fixtures, so no `*.sqlite.json` file exists. Every aggregate the controllers return is already cast (`(float)`, `(int)`, `round()`), which is what keeps H15 from showing up in shapes.

## 4. Decimal strings typed as `number` (document only)

`decimal:2` casts serialise as strings (H1). The frontends type many of these as `number` and convert before display. Examples: `OrderResource` money fields, `StudentResource.total_spent`/`credit_balance`, `InventoryItem` quantities, `BranchMonthlyAmount.amount`, raw report rows. They are listed here as one class rather than per field. A refactor must not "fix" them in Track A (H1).

## 5. Backend routes that no frontend page calls (design §13.1)

Listed so that nobody removes or "fixes" them by accident. Only B-04 removes any of them.

| Route | Note |
|---|---|
| `GET api/v1/portal/students/{student}/ledger` | Spec 14; the portal never calls it |
| `POST api/v1/users/{user}/photo` | POS sends photos through `POST/PUT users` |
| `POST api/v1/users/{user}/branches`, `DELETE api/v1/users/{user}/branches/{branch}` | POS sends `branch_ids` through `POST/PUT users` |
| `POST api/v1/auth/password/email` | POS has no forgot-password page |
| `POST api/v1/portal/pre-registrations`, `POST api/v1/portal/pre-registrations/check` | removed by B-04 |
| `GET api/health` | infrastructure probe |

## 6. Other defects found while recording (not contract gaps)

| Route | Finding | Effect | Proposed Track B item |
|---|---|---|---|
| `DELETE api/v1/pos/menu-items/{item}` | Deleting a menu item that has order lines throws `SQLSTATE[23000] … order_items_pos_menu_item_id_foreign` → 500 | Any item that was ever sold cannot be deleted; the POS gets a 500 instead of a message. The `ok` fixture deletes an unsold item | B-20 |
| `POST api/v1/users/{user}/photo` | The route group is `role:admin\|manager`, but `UserPolicy::update` requires `users.manage`, which only admin has | A manager always gets 403 (`403-not-allowed` fixture), listed as a role-matrix exception (design §4.1.5). The POS does not call this route (§5) | none: finding only (design §4.1.5) |
| `GET api/v1/reports/inventory` | Found by the spec owner (request side): the POS history query sends `log_type` and `history_page` (`app/(kitchen)/reports/inventory/page.tsx:228, 230`), but the API reads `type` and paginates on `page` | The History type filter is ignored, and Next/Previous always returns page 1 | Spec 17 F-1 (frontend) |
| `GET api/v1/references/meal-planner` | Found during T-7 (from reading): `?month=` with an empty value reaches `isWeekVisibleToParents(string $month, …)` as `null` and likely returns 500. T-7 keeps today's `input('month', 'june')`, so the behaviour is unchanged | Crafted requests only: the POS always sends a month (`sunbites-pos/lib/api/meal-planner.ts:12-14`) | none: finding only |
