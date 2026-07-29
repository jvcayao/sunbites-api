# Spec 14 — Credit Settlement

## Introduction

Students may borrow against a credit limit at checkout when their wallet balance falls short. That debt is recorded in `students.credit_balance` and the `credit_transactions` ledger, but there is no working way to collect it back.

Today the only settlement path is `POST /students/{student}/credit/settle`, which zeroes the full balance in one shot with no amount input, no payment method, and no record of the money collected. Wallet top-up is entirely unaware that credit exists, so staff who top up a student expecting the debt to clear see nothing happen. Neither the POS student Wallet tab nor the parent portal displays outstanding credit at all.

This spec turns credit repayment into a first-class payment event with three deliberate channels (counter payment, deduction from existing wallet balance, and admin write-off), makes credit visible everywhere money is displayed in both apps, and puts collected cash into the reports that reconcile the drawer.

**Depends on:** Spec 05 (Student Management — wallet top-up, student detail), Spec 06 (POS & Checkout — credit charging at checkout, order void), Spec 07 (Parent Portal — student detail, wallet view), Spec 08 (Reports & Dashboard — credit report, wallet report, daily summary), Spec 09 (System Configuration — `credit_limit`), Spec 10 (Notifications — Reverb, `parents.{id}` private channel)

---

## Business Rules

### Credit is a receivable, not a wallet balance

- Credit remains a separate ledger from the `bavix/laravel-wallet` wallet. `students.credit_balance` holds the outstanding total; `credit_transactions` holds the entries.
- The invariant `credit_balance = Σcharged − Σsettled − Σwaived − Σvoided` must hold for every student at all times.
- `App\Services\CreditLedgerService` is the **only** writer of `credit_transactions` and `students.credit_balance`. No controller mutates either directly.

### Settlement channels

Credit is settled through exactly three channels. There is no automatic settlement anywhere in the system.

| Channel | Money movement | Ledger type | `payment_method` |
|---|---|---|---|
| Counter payment | Cash, GCash, or bank transfer received from the parent | `settled` | `cash` / `gcash` / `bank_transfer` |
| From wallet balance | Existing wallet balance withdrawn to cover the debt | `settled` | `wallet` |
| Waive | Nothing collected; debt written off | `waived` | `null` |

- **Wallet top-up never settles credit.** Top-up is a pure deposit. This is deliberate: it keeps the wallet ledger and the credit ledger independently auditable, and it lets a parent top up for next week's lunches without their money being consumed by an old debt.
- Partial settlement is allowed. Over-payment is rejected, never spilled into the wallet as a balance.
- `waived` is a distinct ledger type from `settled`. Mixing them would make the credit report's settled total a blend of money collected and money forgiven, and that total feeds cash reconciliation.

### Permissions

| Action | Roles |
|---|---|
| View outstanding credit and ledger | All staff |
| Settle credit (any channel) | All staff — admin, manager, supervisor, cashier |
| Waive credit | `admin` only |

Collection is open to all staff because the person physically receiving the money at the counter is usually a cashier. Every entry records `performed_by` and writes an activity-log entry, so the audit trail carries the accountability that the role restriction previously attempted.

> **Steering note:** [`product.md`](../../steering/product.md) has been amended (approved) so the Supervisor and Cashier role descriptions match this permission model. Supervisor's previous "no mutations" description was already inaccurate before this spec — supervisors can edit and soft-delete students and top up wallets via [`routes/kitchen-api.php:142-167`](../../../routes/kitchen-api.php#L142-L167). Cashiers were verified to be excluded from the top-up group, so they gain credit settlement only.

### Reconciliation

- Settlement revenue was already booked as sales on the date of the original order. Settlements must **never** be added to `total_revenue` or any sales report — doing so double-counts revenue.
- Credit collection is a receivable converting to cash. It appears as its own reported figure, separated by payment method.
- The `wallet` payment method brings in **no** cash and must be reported distinctly from `cash` / `gcash` / `bank_transfer` so the drawer expectation stays correct.

### Credit limit

`credit_limit` (system configuration, default 300) continues to gate borrowing at checkout. Settling lowers `credit_balance`, which automatically restores borrowing headroom. This spec does not change the limit check.

---

## Data Model

### `credit_transactions` — columns added

```
credit_transactions
  id
  student_id              (FK → students.id)              existing
  order_id                (nullable, indexed)             existing
  type                    (string)                        existing — + 'waived'
  amount                  (decimal 10,2)                  existing
  notes                   (text)                          WIDENED from string
  performed_by            (FK → users.id)                 existing
  created_at              (timestamp, nullable)           existing

  branch_id               (FK → branches.id, nullable, indexed)   NEW
  payment_method          (string, nullable)                      NEW
  reference_number        (string 50, nullable)                   NEW
  wallet_transaction_id   (unsignedBigInteger, nullable)          NEW
```

| Column | Purpose |
|---|---|
| `branch_id` | Branch snapshot at write time. Required for branch-scoped reporting: the credit report currently derives branch via `Student::where('branch_id', …)->pluck('id')` then `whereIn(...)`, which grows unbounded and misattributes historical entries when a student transfers branch. |
| `payment_method` | `cash` / `gcash` / `bank_transfer` / `wallet`. `null` for `charged`, `waived`, and `voided`. Required for `settled`. This column is what makes drawer reconciliation possible. |
| `reference_number` | GCash or bank reference, mirroring what wallet top-up already captures. |
| `wallet_transaction_id` | Links the `bavix` withdrawal row when settling from wallet balance, so a settlement and its paired wallet movement are traceable to each other. |

`notes` widens from `string` (255) to `text` because waive reasons accept up to 1000 characters.

Backfill on migration: `branch_id` populated from `students.branch_id` for existing rows.

### Enums

```php
// app/Enums/CreditTransactionType.php — 'waived' added
case Charged = 'charged';
case Settled = 'settled';
case Waived  = 'waived';
case Voided  = 'voided';

// app/Enums/CreditSettlementMethod.php — new
case Cash         = 'cash';
case Gcash        = 'gcash';
case BankTransfer = 'bank_transfer';
case Wallet       = 'wallet';
```

`App\Enums\PaymentMethod` is deliberately **not** reused: it lacks `bank_transfer` and carries `subscription`, which is meaningless for credit settlement.

### Ledger entry types (API contract)

The unified ledger endpoint returns six `entry_type` values:

| `entry_type` | `direction` | Source | Label |
|---|---|---|---|
| `deposit` | `credit` | `transactions` | Top-up |
| `withdraw` | `debit` | `transactions` | Purchase |
| `credit_charged` | `debit` | `credit_transactions` | Credit Charged |
| `credit_settled` | `credit` | `credit_transactions` | Credit Paid |
| `credit_waived` | `credit` | `credit_transactions` | Credit Waived |
| `credit_voided` | `credit` | `credit_transactions` | Credit Reversed |

---

## Requirements

### Requirement 1 — Credit Ledger Service

**User Story:** As the system, I need a single writer for the credit ledger so that the outstanding balance can never drift from its entries.

#### Acceptance Criteria

1. WHERE `App\Services\CreditLedgerService` exists it SHALL expose `charge()`, `settleWithPayment()`, `settleFromWallet()`, `waive()`, and `void()`, and SHALL be the only code in the application that writes to `credit_transactions` or `students.credit_balance`.
2. WHEN any service method executes THEN it SHALL wrap all writes in a single `DB::transaction` and SHALL acquire `Student::lockForUpdate()` before reading `credit_balance`.
3. WHEN any service method writes a `credit_transactions` row THEN it SHALL set `branch_id` from the student's current `branch_id`, `performed_by` from the acting user, and `created_at` explicitly.
4. WHEN a sequence of charges, settlements, waives, and voids has been applied to a student THEN `students.credit_balance` SHALL equal `Σcharged − Σsettled − Σwaived − Σvoided` for that student.
5. WHEN `CheckoutController` charges credit at checkout THEN it SHALL call `CreditLedgerService::charge()` instead of writing `CreditTransaction` and incrementing `credit_balance` inline.
6. WHEN `TransactionController::void()` reverses a credit order THEN it SHALL call `CreditLedgerService::void()` instead of writing the reversal inline.
7. IF `settleWithPayment()`, `settleFromWallet()`, or `waive()` would drive `credit_balance` below zero THEN it SHALL abort the transaction with a 422 rather than clamping the value.
7a. WHERE `void()` is concerned it is **exempt** from criterion 7. WHEN a voided order's `credit_amount` exceeds the student's current `credit_balance` — which happens when the credit was already settled before the void — THEN `void()` SHALL reverse only `min(credit_amount, credit_balance)`, SHALL still complete the void, and SHALL record the unreversed remainder in the ledger entry's `notes` so the resulting overpayment is auditable rather than silently absorbed. Aborting here would block the entire void, including its inventory restock and wallet refund.
8. WHERE `charge()` is called it SHALL accept the order's receipt number as a string, not an `Order` instance, and SHALL leave `order_id` null on the resulting row — the order does not yet exist at the point checkout charges credit. Existing `charged` rows already carry a null `order_id` and reference the receipt in `notes`; this behaviour is preserved unchanged.

---

### Requirement 2 — Settle credit with a counter payment

**User Story:** As a staff member at the counter, I want to record a cash, GCash, or bank transfer payment against a student's outstanding credit, so that the debt clears and the money is accounted for.

#### Acceptance Criteria

1. WHEN a staff member sends `POST /api/v1/students/{student}/credit/settle` with a valid `amount` and a `payment_method` of `cash`, `gcash`, or `bank_transfer` THEN the system SHALL create a `credit_transactions` row with `type = settled` and the given `payment_method`, reduce `students.credit_balance` by `amount`, and return 200.
2. WHEN the settlement succeeds THEN the response SHALL include `message`, `amount_settled`, the student's new `credit_balance`, the student's `wallet_balance`, and the created ledger entry.
3. WHEN `amount` equals the full outstanding credit THEN `credit_balance` SHALL become exactly `0.00`.
4. WHEN `amount` is less than the outstanding credit THEN `credit_balance` SHALL be reduced by exactly `amount` and the remainder SHALL stay outstanding.
5. IF `amount` is greater than the current outstanding credit THEN the system SHALL return 422 with a message naming the outstanding amount, and SHALL NOT write any ledger entry.
6. IF the student's `credit_balance` is `0` or less THEN the system SHALL return 422 with "No outstanding credit to settle."
7. IF `payment_method` is `gcash` or `bank_transfer` AND `reference_number` is absent THEN the system SHALL return 422.
8. WHEN `reference_number` is supplied THEN it SHALL be validated as alphanumeric with a maximum of 50 characters.
9. WHEN `note` is supplied THEN the system SHALL apply `strip_tags` before persisting it, and SHALL cap it at 255 characters.
10. WHEN the settlement succeeds THEN the system SHALL write an activity-log entry on the `wallet` log naming the amount, payment method, performing user, and resulting credit balance.
11. IF the request is unauthenticated THEN the system SHALL return 401.
12. WHERE this endpoint is registered it SHALL be available to all authenticated staff regardless of role.

---

### Requirement 3 — Settle credit from existing wallet balance

**User Story:** As a staff member, I want to apply a student's existing wallet balance against their outstanding credit, so that a student who already has funds can clear their debt without handing over more money.

#### Acceptance Criteria

1. WHEN a staff member sends `POST /api/v1/students/{student}/credit/settle` with `payment_method = wallet` and a valid `amount` THEN the system SHALL withdraw `amount` from the student's wallet via the `bavix/laravel-wallet` API, create a `credit_transactions` row with `type = settled` and `payment_method = wallet`, and reduce `credit_balance` by `amount`, all within one database transaction.
2. WHEN the wallet withdrawal completes THEN the system SHALL store the resulting wallet transaction id in `credit_transactions.wallet_transaction_id`.
3. IF the student's wallet balance is less than `amount` THEN the system SHALL return 422 and SHALL leave both the wallet balance and `credit_balance` unchanged.
4. WHERE `payment_method = wallet` THEN `reference_number` SHALL NOT be required.
5. WHEN `payment_method = wallet` THEN the system SHALL NOT push the wallet balance below zero under any circumstance.
6. WHEN a `wallet` settlement is reported THEN it SHALL be distinguishable from `cash`, `gcash`, and `bank_transfer` settlements so that reports can exclude it from cash-received figures.

---

### Requirement 4 — Waive credit

**User Story:** As an Admin, I want to write off credit that will not be collected, so that uncollectable debt does not sit in the outstanding total forever.

#### Acceptance Criteria

1. WHEN an Admin sends `POST /api/v1/students/{student}/credit/waive` with a valid `amount` and `reason` THEN the system SHALL create a `credit_transactions` row with `type = waived`, `payment_method = null`, the reason stored in `notes`, reduce `credit_balance` by `amount`, and return 200.
2. IF the authenticated staff member does not hold the `admin` role THEN the system SHALL return 403.
3. IF `reason` is absent, shorter than 5 characters, or longer than 1000 characters THEN the system SHALL return 422.
4. IF `amount` is greater than the current outstanding credit THEN the system SHALL return 422 and write nothing.
5. WHEN a waive succeeds THEN the system SHALL write an activity-log entry naming the amount, reason, and performing admin.
6. WHERE waived amounts appear in reports THEN they SHALL be totalled separately from settled amounts and SHALL NOT be counted as money received.

---

### Requirement 5 — Wallet top-up stays independent of credit

**User Story:** As a parent, I want money I add for upcoming meals to stay available for meals, so that a top-up is not silently consumed by an older debt.

#### Acceptance Criteria

1. WHEN `POST /api/v1/students/{student}/wallet/top-up` is called for a student with outstanding credit THEN the system SHALL deposit the full amount to the wallet and SHALL leave `students.credit_balance` unchanged.
2. WHEN `POST /api/v1/pos/inline-reload` is called for a student with outstanding credit THEN the system SHALL deposit the full amount to the wallet and SHALL leave `students.credit_balance` unchanged.
3. WHEN either top-up endpoint completes THEN the system SHALL NOT create any `credit_transactions` row.
4. WHERE the POS top-up modal is shown for a student with outstanding credit THEN it SHALL display the outstanding amount, SHALL state that topping up does not pay it, and SHALL offer a control that opens the Settle Credit modal.

---

### Requirement 6 — Unified student ledger (staff)

**User Story:** As a staff member, I want one chronological view of a student's wallet and credit activity, so that I can see why a balance is what it is.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/students/{student}/ledger` THEN the system SHALL return a paginated list combining `transactions` rows for the student's wallet and `credit_transactions` rows for the student, ordered newest first.
2. WHEN a ledger row is returned THEN it SHALL include `id`, `date`, `entry_type`, `entry_label`, `direction`, `amount`, `payment_method`, `reference_number`, `note`, and `performed_by`.
3. WHERE `entry_type` is returned it SHALL be one of `deposit`, `withdraw`, `credit_charged`, `credit_settled`, `credit_waived`, `credit_voided`.
4. WHERE `direction` is returned it SHALL be `debit` or `credit`, so that the frontend determines sign and colour without inspecting `entry_type`.
5. WHEN wallet amounts are returned THEN they SHALL be converted from the minor units stored by `bavix/laravel-wallet` to a decimal peso value.
6. WHEN wallet rows are selected THEN the system SHALL include only rows where `confirmed` is true and `deleted_at` is null.
7. WHEN `entry_type` filter is `topup`, `purchase`, or `credit` THEN the system SHALL return only matching rows; WHEN the filter is `all` or absent THEN it SHALL return every row.
8. WHEN `from` and/or `to` date filters are supplied THEN the system SHALL restrict rows to that range.
9. WHEN performer names are resolved THEN the system SHALL do so in a single batched query and SHALL NOT issue one query per row.
10. WHEN the ledger is queried across an unbounded date range THEN the system SHALL paginate at the database level and SHALL NOT load the full history into memory.
11. WHEN `GET /api/v1/students/{student}` is called THEN the response SHALL NO LONGER include the `wallet_transactions` array; the Wallet tab SHALL source its history from the ledger endpoint instead.
12. WHERE the response is paginated it SHALL use the project's standard `{ data, meta }` envelope.

---

### Requirement 7 — Credit visibility in the POS app

**User Story:** As a staff member, I want outstanding credit shown wherever I look at a student, so that I notice the debt before the parent leaves the counter.

#### Acceptance Criteria

1. WHERE the student detail header is rendered AND `credit_balance` is greater than zero THEN it SHALL display a "Credit Owed" chip beside the Wallet and QR ID chips, styled to signal attention.
2. IF `credit_balance` is zero THEN the header SHALL NOT render the Credit Owed chip.
3. WHERE the student Wallet tab is rendered it SHALL display a Current Balance card and an Outstanding Credit card side by side, and SHALL show a "Settle Credit" action alongside "Top Up".
4. WHERE the Wallet tab transaction table is rendered it SHALL source rows from the unified ledger endpoint and SHALL offer `All`, `Top-up`, `Purchases`, and `Credit` filters.
5. WHERE the Wallet tab table is rendered it SHALL paginate rather than truncate to a fixed number of rows.
6. WHERE the Settle Credit modal is rendered it SHALL show the outstanding credit and the current wallet balance, accept an amount defaulting to the full outstanding figure, and offer Cash, GCash, Bank Transfer, and From Wallet as payment methods.
7. IF the wallet balance is less than the entered amount THEN the From Wallet option SHALL be disabled with an inline reason, and SHALL re-evaluate when the amount changes.
8. WHERE the Settle Credit modal is rendered it SHALL show the Reference Number field only when GCash or Bank Transfer is selected.
9. WHILE a settle or waive mutation is pending THEN the submit control SHALL be disabled.
10. WHERE the Waive Credit action is rendered it SHALL appear in the student detail overflow menu and SHALL be rendered only for users holding the `admin` role.
11. WHERE the Waive Credit modal is rendered it SHALL require a reason, SHALL warn that the amount will not be collected, and SHALL use destructive styling on its confirm control.

---

### Requirement 8 — Credit visibility in the parent portal

**User Story:** As a parent, I want to see that my child owes credit and see when it has been paid, so that I know what I owe and have a record once I have settled it.

#### Acceptance Criteria

1. WHEN a parent requests `GET /api/v1/portal/students` THEN each linked student entry SHALL include `credit_balance`.
2. WHERE a dashboard student card is rendered AND `credit_balance` is greater than zero THEN it SHALL display a "Credit owed" line beneath the wallet balance, linking through to the student's Wallet tab.
3. IF `credit_balance` is zero THEN the dashboard card SHALL render exactly as it does today, with no credit line.
4. WHEN a parent requests `GET /api/v1/portal/students/{student}/wallet` THEN the response SHALL include `credit_balance`.
5. WHERE the portal student Wallet tab is rendered AND `credit_balance` is greater than zero THEN it SHALL display an Outstanding Credit card explaining that credit was used at the canteen and that it must be settled at the counter because it cannot be paid online.
6. WHEN a parent requests `GET /api/v1/portal/students/{student}/ledger` THEN the system SHALL return the same unified ledger contract as Requirement 6, restricted to that student.
7. WHERE the portal Wallet tab filter row is rendered it SHALL include a `Credit` pill alongside the existing `All`, `Top-up`, and `Deductions` pills.
8. IF a parent requests the ledger or wallet for a student they are not linked to THEN the system SHALL return 403.
9. WHERE portal credit endpoints exist they SHALL be registered under the `auth:parents` guard with the `parent` token ability, and SHALL NOT be reachable with a staff token.

---

### Requirement 9 — Parent notifications for credit activity

**User Story:** As a parent, I want to be notified when my child uses credit and when a credit payment is recorded, so that I am not surprised by a debt and I have confirmation once it is settled.

#### Acceptance Criteria

1. WHEN credit is charged at checkout THEN the system SHALL dispatch `CreditChargedNotification` to every parent linked to that student via `parent_student`.
2. IF a `CreditChargedNotification` has already been sent to that parent for that student on the same calendar day THEN the system SHALL NOT send another.
3. WHEN credit is settled or waived THEN the system SHALL dispatch `CreditSettledNotification` to every linked parent, with no debounce.
4. WHERE either notification class is defined it SHALL implement `ShouldQueue` and `ShouldBroadcast`, SHALL use the `database` and `broadcast` channels, and SHALL broadcast on `PrivateChannel("parents.{id}")`.
5. WHEN a notification payload is built THEN it SHALL include the student name, the amount for the triggering event, and the student's resulting outstanding credit balance.
6. WHEN a notification is dispatched THEN it SHALL occur only after the enclosing database transaction has committed, so that a rolled-back charge never notifies a parent.
7. WHEN the portal notification bell receives either broadcast THEN it SHALL invalidate the unread-count query so the badge updates in real time.

---

### Requirement 10 — Credit Collection Report

**User Story:** As an Admin or Manager, I want the credit report to show how each settlement was paid, so that I can audit collections and reconcile them against cash.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reports/credits` THEN the summary SHALL include `total_charged`, `total_settled`, `total_waived`, `total_voided`, and `net_outstanding`.
2. WHERE `net_outstanding` is computed it SHALL equal `total_charged − total_settled − total_waived − total_voided`.
3. WHEN the summary is returned THEN it SHALL include a `settled_by_method` breakdown giving the settled total for each of `cash`, `gcash`, `bank_transfer`, and `wallet`.
4. WHEN a report row is returned THEN it SHALL include the entry's `payment_method` and `reference_number`.
5. WHEN a `payment_method` filter is supplied THEN the system SHALL return only entries matching it.
6. WHEN the `type` filter is supplied THEN it SHALL accept `waived` in addition to the existing `charged`, `settled`, and `voided`.
7. WHEN the report is scoped to a branch THEN it SHALL filter on `credit_transactions.branch_id` rather than resolving student ids for the branch first.
8. WHERE the report displays the acting staff member THEN the POS SHALL render the `performed_by` string returned by the API directly, correcting the current defect that renders "undefined undefined".
9. WHERE `wallet` settlements are displayed in the method breakdown they SHALL be visually separated from the cash-bearing methods, because they bring in no cash.

---

### Requirement 11 — Wallet Report credit surfacing

**User Story:** As an Admin or Manager, I want the wallet report to tell me who owes credit and how much is outstanding in total, so that I have a collection worklist.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reports/wallet` THEN the `summary` block SHALL include `total_outstanding_credit` and `students_with_credit`.
2. WHEN a `has_credit` filter is supplied THEN the system SHALL return only students whose `credit_balance` is greater than zero.
3. WHEN a sort on outstanding credit is requested THEN the system SHALL order results by `credit_balance`.
4. WHERE the wallet report expandable row is rendered it SHALL offer a `Credit` panel alongside the existing `Purchases` and `Top-Ups` panels, listing that student's credit charges and settlements.
5. WHERE the wallet report KPI cards are labelled in the POS they SHALL read "Total Deposits" and "Total Spent" rather than "Total Credits" and "Total Debits", so that "credit" on this screen means only debt owed.
6. WHERE the wallet report Excel export is generated it SHALL continue to include the existing `Outstanding Credit` column.
7. WHEN `total_outstanding_credit` and `students_with_credit` are computed THEN they SHALL be derived branch-wide from `students.credit_balance`, independently of the report's wallet-activity list filter, so that `total_outstanding_credit` always equals `net_outstanding` on the credit report for the same branch.

---

### Requirement 12 — Daily Summary credit collections

**User Story:** As an Admin or Manager closing the day, I want credit collections shown on the daily summary, so that I can reconcile the cash drawer from one screen.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/reports/daily-summary` THEN the response SHALL include a `credit_collections` object containing `total`, `count`, and per-method totals for `cash`, `gcash`, `bank_transfer`, and `from_wallet`.
2. WHEN `credit_collections` is computed THEN it SHALL include only `credit_transactions` rows of type `settled` dated on the requested day and belonging to the active branch.
3. WHEN `credit_collections` is computed THEN `total_revenue` and `payment_breakdown` SHALL remain unchanged, because the revenue was already booked on the original order date.
4. WHERE `from_wallet` is reported it SHALL be presented so that it is not counted toward expected drawer cash.
5. IF no credit was settled on the requested day THEN `credit_collections.total` SHALL be `0` and `count` SHALL be `0`.

---

## Cross-Cutting Requirements

### Security & authorization

- Settle is available to all authenticated staff; waive requires `admin`. Both are enforced by route middleware, not by controller conditionals.
- Portal credit endpoints run under `auth:parents` with the `parent` ability and enforce `$this->authorize('view', $student)`. A staff token must never satisfy a portal route, and a parent must never read a student they are not linked to.
- `reference_number` and `note` are user-supplied strings surfaced in reports; `note` is passed through `strip_tags` and both are length-capped.
- Waive reasons may contain personal circumstances. They are stored in `credit_transactions.notes` and exposed only to staff, never to the portal.

### Data isolation (branch scoping)

- `credit_transactions.branch_id` is written on every entry and is the sole basis for branch filtering in the credit report and daily summary.
- A settlement recorded in one branch must never appear in another branch's credit report, wallet report, or daily summary.

### Performance

- The unified ledger paginates at the database level via a `UNION ALL` subquery; an in-memory merge is not acceptable because the portal exposes an "All time" filter.
- Performer name resolution is batched in a single `whereIn` query for both the ledger and the credit report.
- The credit report's branch filter drops the unbounded `whereIn(studentIds)` pattern in favour of the indexed `branch_id` column.

### Error handling

- All validation failures return the project's standard `{ message, errors }` shape with a 422 status.
- Business-rule rejections (no outstanding credit, amount over outstanding, insufficient wallet balance) return 422 with an actionable message naming the relevant figure.
- Any failure inside a settle, waive, charge, or void operation rolls back the whole database transaction, leaving both the wallet and the credit ledger untouched.

### Observability

- Every settle and waive writes a `spatie/laravel-activitylog` entry on the `wallet` log, caused by the acting user and performed on the student, recording amount, payment method or reason, and the resulting credit balance.
- Every `credit_transactions` row records `performed_by`, giving a per-entry audit trail independent of the activity log.

### Known accepted risk

Two concurrent partial settlements against the same debt are individually valid and will both succeed — indistinguishable from a genuine double collection. `lockForUpdate` and the amount cap prevent a duplicated *full* settlement, but not this case. Server-side idempotency keys are deliberately not implemented; the mitigation is the frontend disabling the submit control while the mutation is pending (Requirement 7, criterion 9). This is recorded as an accepted risk, not an oversight.

---

## Out of Scope

- **Changing the wallet-drain behaviour at checkout.** `CheckoutController` currently empties the wallet to zero whenever credit is used. That behaviour is retained; changing it would ripple into the void refund calculation. The unified ledger makes the existing behaviour legible without altering it.
- **Automatic settlement at checkout.** Deducting outstanding credit from a later wallet-paid order was considered and rejected in favour of deliberate settlement only.
- **Parents paying credit online.** Online payment collection is out of scope per `product.md`. The portal shows the debt and directs parents to the counter.
- **Per-order receivable aging or FIFO allocation.** The business tracks one outstanding total per student, not invoice-level debt.
- **A scheduled credit digest job.** Credit charge notifications fire inline with a same-day debounce so parents learn within seconds rather than up to a day later.
- **Migrating existing inline `$request->validate()` calls to Form Requests.** New credit endpoints use Form Requests per `tech.md`; the 47 existing controllers that validate inline are left alone.
