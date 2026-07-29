# Spec 14 — Credit Settlement · Implementation Plan

> **Implementers: read [HANDOFF.md](./HANDOFF.md) before starting.** It carries the required tooling, the verified facts you must not re-derive, and eleven documented traps where the obvious move is wrong.

Three repos are involved. Backend tasks (1–6) land in `~/sunbites-api`, POS tasks (7) in `~/sunbites-pos`, portal tasks (8) in `~/sunbites-portal`.

**Standing rules for every task**
- Run `vendor/bin/sail bin pint --dirty --format agent` after any PHP change
- Run the task's own tests plus existing suites covering the modified files before marking it done
- Backend tests: `RefreshDatabase`, factories, always `actingAs($user, 'sanctum')` or `actingAs($parent, 'parents')`
- Frontend tests: custom `render` from `__tests__/test-utils.tsx`, MSW at the network boundary, role and label queries

**Ordering constraint:** task 4.2 removes `wallet_transactions` from the student show payload. Task 7.4 replaces the POS consumer. These two must ship in the same release.

---

## 1. Data model foundation

- [ ] 1.1 Add credit enums
  - Add `case Waived = 'waived';` to `app/Enums/CreditTransactionType.php`
  - Create `app/Enums/CreditSettlementMethod.php` with `Cash`, `Gcash`, `BankTransfer`, `Wallet`, plus `label()` and `bringsInCash()` (false only for `Wallet`)
  - Create `app/Enums/LedgerEntryType.php` with the six entry types, plus `label()` and `direction()` returning `'debit'` or `'credit'`
  - Write unit tests in `tests/Unit/CreditEnumsTest.php`:
    - Every `CreditSettlementMethod` case returns a non-empty label; `bringsInCash()` is false only for `Wallet`
    - Every `LedgerEntryType` case returns the expected label and direction — `deposit`/`credit_settled`/`credit_waived`/`credit_voided` are `credit`; `withdraw`/`credit_charged` are `debit`
    - `CreditTransactionType::Waived` resolves from the string `'waived'`
  - _Requirements: 1.3, 3.6, 4.6, 6.3, 6.4, 12.4_

- [ ] 1.2 Migrate `credit_transactions` and update the model
  - Create migration `add_settlement_columns_to_credit_transactions` adding `branch_id` (nullable FK, `nullOnDelete`), `payment_method`, `reference_number` (50), `wallet_transaction_id`; widen `notes` to `text`; add indexes `(branch_id, type, created_at)` and `wallet_transaction_id`
  - Backfill `branch_id` from `students.branch_id` for existing rows
  - Update `app/Models/CreditTransaction.php`: add the four columns to `$fillable`, cast `payment_method` to `CreditSettlementMethod`, add the `branch()` relation. Do **not** add `HasBranch` — the global scope would break the portal ledger query
  - Create `database/factories/CreditTransactionFactory.php` with states `charged()`, `settled()`, `waived()`, `voided()`
  - Write integration tests in `tests/Feature/Kitchen/CreditTransactionSchemaTest.php`:
    - A row persists and reads back all four new columns with `payment_method` cast to the enum
    - `notes` accepts a 1000-character string
    - Backfill populated `branch_id` for a row created before the migration
    - Deleting a branch nulls `branch_id` and leaves the row intact
  - _Requirements: 1.3, 2.1, 3.2, 4.1, 10.7, 12.2_

---

## 2. CreditLedgerService

- [ ] 2.1 Create the service with `charge()` and `void()`, and route existing callers through it
  - Create `app/Services/CreditLedgerService.php` following the `app/Services/` convention
  - Implement `charge(Student, float $amount, string $receiptNumber, User)` and `void(Student, Order, User)`: `DB::transaction` + `Student::lockForUpdate()`, write `branch_id`/`performed_by`/explicit `created_at`, abort 422 rather than clamping if the balance would go negative
  - **`charge()` takes a receipt number, not an `Order`.** In `CheckoutController` the order is created at line 196, *after* credit is charged at line 172 — the order does not exist yet. `order_id` stays null on `charged` rows, exactly as today. Do not reorder checkout to create the order first; see the rationale in design.md
  - Replace the inline `CreditTransaction::create()` + `increment('credit_balance')` block in `app/Http/Controllers/Kitchen/CheckoutController.php` with `charge()`, passing the already-generated `$receiptNumber`
  - Replace the inline reversal + `max(0, …)` clamp in `app/Http/Controllers/Kitchen/TransactionController.php@void` with `void()`
  - Write integration tests in `tests/Feature/Kitchen/CreditVoidReversalTest.php`:
    - Checkout with credit still records the charge and increases `credit_balance` (no behaviour change)
    - Voiding a credit order writes a `voided` entry and reduces `credit_balance` by the order's `credit_amount`
    - Voiding a wallet order that used credit refunds only `total − credit_amount` to the wallet, as before
    - `branch_id` and `performed_by` are populated on both entry types
  - _Requirements: 1.1, 1.2, 1.3, 1.5, 1.6, 1.7_

- [ ] 2.2 Implement `settleWithPayment()`
  - Accepts `CreditSettlementMethod`, `reference_number`, `note`; writes a `settled` row with the method and reference; reduces `credit_balance`
  - Re-check the outstanding amount under `lockForUpdate` and abort 422 if the amount now exceeds it
  - Write unit tests in `tests/Unit/CreditLedgerServiceTest.php`:
    - Full settle drives `credit_balance` to exactly `0.00`
    - Partial settle reduces by exactly the amount and leaves the remainder
    - Cash, GCash, and bank transfer each persist their own `payment_method`
    - Amount above the outstanding balance aborts and writes nothing
  - _Requirements: 1.1, 1.2, 2.1, 2.3, 2.4, 2.5_

- [ ] 2.3 Implement `settleFromWallet()`
  - Withdraw `amount` via the `bavix` wallet API, capture the returned transaction id into `wallet_transaction_id`, write a `settled` row with `payment_method = wallet`, reduce `credit_balance` — all in one transaction
  - Abort 422 when the wallet balance is short; never allow a negative wallet balance
  - Write unit tests in `tests/Unit/CreditLedgerServiceTest.php`:
    - Wallet is debited and `credit_balance` reduced by the same amount
    - `wallet_transaction_id` matches the created wallet transaction
    - Insufficient balance aborts with **both** the wallet balance and `credit_balance` unchanged
    - The wallet balance never goes below zero
  - _Requirements: 1.1, 1.2, 3.1, 3.2, 3.3, 3.5_

- [ ] 2.4 Implement `waive()`
  - Write a `waived` row with `payment_method = null` and the reason in `notes`; reduce `credit_balance`
  - Write unit tests in `tests/Unit/CreditLedgerServiceTest.php`:
    - Waive writes `type = waived` with a null `payment_method` and the reason persisted
    - `credit_balance` is reduced by the waived amount
    - Amount above the outstanding balance aborts and writes nothing
  - _Requirements: 1.1, 1.2, 4.1_

- [ ] 2.5 Prove the ledger invariant
  - Write unit tests in `tests/Unit/CreditLedgerInvariantTest.php`:
    - After charge ₱100 → charge ₱50 → settle ₱75 cash → waive ₱25 → void an order, `credit_balance` equals `Σcharged − Σsettled − Σwaived − Σvoided`
    - The invariant holds after settling from wallet as well as from cash
    - An operation that would drive the balance negative aborts and leaves the balance untouched
    - Concurrent-safety: a second settle attempt for the full amount after a full settle returns 422 rather than driving the balance negative
  - _Requirements: 1.4, 1.7_

---

## 3. Validation and endpoints

- [ ] 3.1 Build the Form Requests
  - Create `app/Http/Requests/Concerns/ValidatesOutstandingCredit.php` exposing `notExceedingOutstandingCredit(Student $student)`, returning a closure rule whose message names the outstanding figure
  - Create `app/Http/Requests/SettleCreditRequest.php`: `amount` required/numeric/min 0.01 + the outstanding-credit rule; `payment_method` required enum; `reference_number` `required_if:payment_method,gcash,bank_transfer` + `alpha_num` + max 50; `note` nullable max 255 with `strip_tags` applied in `prepareForValidation()`
  - Create `app/Http/Requests/WaiveCreditRequest.php`: `amount` as above; `reason` required string min 5 max 1000
  - Write integration tests in `tests/Feature/Kitchen/CreditSettlementValidationTest.php`:
    - Amount above outstanding returns 422 with the outstanding figure in the message
    - GCash or bank transfer without `reference_number` returns 422
    - Non-alphanumeric `reference_number` returns 422
    - `note` containing HTML is persisted stripped
    - `reason` shorter than 5 or longer than 1000 characters returns 422
  - _Requirements: 2.5, 2.7, 2.8, 2.9, 4.3, 4.4_

- [ ] 3.2 Rewrite `CreditController` and register the routes
  - Rewrite `app/Http/Controllers/Kitchen/CreditController.php`: `settle(SettleCreditRequest, Student)` dispatching to `settleFromWallet()` or `settleWithPayment()` by method; `waive(WaiveCreditRequest, Student)`. Retain the `credit_balance <= 0` pre-check and its message. Both write an `activity('wallet')` entry recording amount, method or reason, performer, and resulting balance — but never the `reference_number`
  - In `routes/kitchen-api.php`: move `POST /students/{student}/credit/settle` out of the `role:admin|manager` group into the all-staff group; add `POST /students/{student}/credit/waive` under `role:admin`
  - Write integration tests in `tests/Feature/Kitchen/CreditSettlementTest.php` and `tests/Feature/Kitchen/CreditWaiveTest.php`:
    - 200 with `message`, `amount_settled`, `credit_balance`, `wallet_balance`, `transaction` for each of the four payment methods
    - Settling at zero outstanding returns 422 with "No outstanding credit to settle."
    - A cashier and a supervisor can both settle
    - A manager waiving returns 403; an admin succeeds
    - Unauthenticated requests return 401
    - An activity-log entry is written on settle and on waive, and contains no `reference_number`
    - A student outside the active branch returns 404
  - _Requirements: 2.1, 2.2, 2.6, 2.10, 2.11, 2.12, 3.1, 3.4, 4.1, 4.2, 4.5_

- [ ] 3.3 Lock in top-up / credit isolation
  - No production code changes — this task exists to encode the deliberate decision that top-up never settles credit
  - Write integration tests in `tests/Feature/Kitchen/WalletTopUpCreditIsolationTest.php`:
    - `POST /students/{student}/wallet/top-up` for a student owing credit deposits the full amount and leaves `credit_balance` unchanged
    - `POST /pos/inline-reload` behaves the same way
    - Neither endpoint creates any `credit_transactions` row
  - _Requirements: 5.1, 5.2, 5.3_

---

## 4. Unified ledger

- [ ] 4.1 Build the ledger query and formatter
  - Create `app/Services/StudentLedgerQuery.php` returning a query builder: `UNION ALL` of the wallet leg (`ABS(amount) / 100`, `confirmed = true`, `deleted_at IS NULL`) and the credit leg, wrapped in `fromSub`, ordered newest first. Skip the wallet leg entirely when the student has no wallet. Map the `entry_type` filter to `whereIn`
  - The wallet leg filters on `wallet_id`, not `payable_id`. `deleted_at` does exist on `transactions` — it comes from a bavix package migration, verified against a fresh migrate. Do not add a migration for it
  - Create `app/Services/LedgerEntryFormatter.php` deriving `entry_label` and `direction` from `LedgerEntryType`, decoding wallet `meta` for `note`, and batching performer names via one `User::whereIn`
  - **Not a `JsonResource`.** Union rows are `stdClass`, not models, and the formatter needs a batched name map that `JsonResource::collection()` cannot pass through. Follow the inline-mapping precedent in `CreditReportController` and `WalletHistoryController`
  - Write integration tests in `tests/Feature/Kitchen/StudentLedgerQueryTest.php`:
    - Wallet and credit rows interleave in correct date order
    - Wallet amounts convert from minor units to decimal pesos and are always positive
    - Unconfirmed and soft-deleted wallet rows are excluded
    - A student with no wallet returns credit rows only
    - `topup`, `purchase`, and `credit` filters each return only matching entry types
    - Performer names resolve in a single batched query, asserted by query count
  - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.9, 6.10_

- [ ] 4.2 Expose the staff ledger endpoint and drop the legacy payload
  - Create `app/Http/Controllers/Kitchen/StudentLedgerController.php` with `index()`, validating `entry_type`, `from`, `to`, `per_page`; paginate and return the standard `{ data, meta }` envelope
  - Register `GET /students/{student}/ledger` in `routes/kitchen-api.php`, available to all staff
  - Remove the `wallet_transactions` key and its `$student->wallet->transactions()->latest()->take(20)` query from `app/Http/Controllers/Kitchen/StudentController.php@show` (lines 75–76 and 103)
  - This breaks three POS consumers, all handled in tasks 7.1 and 7.4: `page.tsx` 3100–3121, `types/student.ts` 109, `__tests__/mocks/handlers.ts` 640
  - Write integration tests in `tests/Feature/Kitchen/StudentLedgerTest.php` and `tests/Feature/Kitchen/StudentShowPayloadTest.php`:
    - 200 with the paginated envelope and the documented row shape
    - `from`/`to` filters restrict the range
    - Pagination returns page 2 correctly
    - `GET /students/{student}` no longer contains `wallet_transactions`
    - Unauthenticated returns 401; a student outside the active branch returns 404
  - _Requirements: 6.1, 6.2, 6.8, 6.11, 6.12_

- [ ] 4.3 Expose the portal ledger and credit balance
  - Create `app/Http/Controllers/Portal/StudentLedgerController.php` — same shape as the kitchen controller plus `$this->authorize('view', $student)`; reuses `StudentLedgerQuery` rather than importing from the Kitchen namespace
  - Register `GET /portal/students/{student}/ledger` in `routes/portal-api.php`
  - Add `credit_balance` to the `GET /portal/students` list payload and to `Portal/WalletController@index`
  - Write integration tests in `tests/Feature/Portal/PortalCreditTest.php`:
    - `credit_balance` present on the linked-students list and on the wallet response
    - The portal ledger returns the same contract as the staff ledger for the same student
    - The `credit` filter returns only credit entry types
    - A parent requesting a student they are not linked to returns 403
    - A staff Sanctum token on the portal route is rejected
    - Waive `notes` are absent from every portal response
  - _Requirements: 8.1, 8.4, 8.6, 8.7, 8.8, 8.9_

---

## 5. Parent notifications

- [ ] 5.1 Build `CreditChargedNotification` with same-day debounce
  - Create `app/Notifications/CreditChargedNotification.php` modelled on `PaymentReminderNotification`: `ShouldQueue` + `ShouldBroadcast`, `$afterCommit = true`, `database` + `broadcast` channels, `PrivateChannel("parents.{id}")`. Payload carries `student_id`, student name, charged amount, and resulting outstanding balance
  - Dispatch from `CreditLedgerService::charge()` to every parent from `Student::parents()`, skipping any parent who already has a `CreditChargedNotification` for that `student_id` dated today
  - **Debounce must filter `type` and date in SQL, then match `student_id` in PHP.** Do not use `->where('data->student_id', $id)`: `notifications.data` is a `text` column, verified against the live schema, and `json_extract` comparison against an integer binding on TEXT is unreliable in MySQL. The candidate set is one parent's charge notifications for one day — a handful of rows at most
  - Write integration tests in `tests/Feature/Kitchen/CreditNotificationTest.php`:
    - A charge notifies every linked parent
    - A second charge the same day for the same student sends nothing further
    - A charge for a different student on the same day still notifies
    - A rolled-back checkout notifies nobody
    - The payload contains `student_id`, student name, amount, and outstanding balance
  - _Requirements: 9.1, 9.2, 9.4, 9.5, 9.6_

- [ ] 5.2 Build `CreditSettledNotification`
  - Create `app/Notifications/CreditSettledNotification.php` with the same channel setup and a `wasWaived` flag distinguishing the copy
  - Dispatch from `settleWithPayment()`, `settleFromWallet()`, and `waive()` to every linked parent, with no debounce
  - Write integration tests in `tests/Feature/Kitchen/CreditNotificationTest.php`:
    - Settling notifies every linked parent with the amount and the resulting balance
    - Two settlements the same day send two notifications
    - Waiving notifies with `wasWaived` true
    - A failed settlement (insufficient wallet balance) notifies nobody
  - _Requirements: 9.3, 9.4, 9.5, 9.6_

---

## 6. Reports

- [ ] 6.1 Extend the credit collection report
  - In `app/Http/Controllers/Kitchen/CreditReportController.php`: replace the `whereIn(studentIds)` branch filter with `where('branch_id', $branchId)`; add `total_waived`; correct `net_outstanding` to `charged − settled − waived − voided`; add the `settled_by_method` breakdown; add a `payment_method` filter; add `payment_method` and `reference_number` to each row
  - Write integration tests in `tests/Feature/Kitchen/CreditReportTest.php`:
    - Summary returns all five totals plus `settled_by_method` with a value per method
    - `net_outstanding` subtracts waived amounts
    - `payment_method` filter returns only matching entries
    - `type=waived` filter works
    - A settlement in another branch is excluded from this branch's report
    - `performed_by` is returned as a string
  - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7_

- [ ] 6.2 Extend the wallet report
  - In `app/Http/Controllers/Kitchen/WalletReportController.php`: add `total_outstanding_credit` and `students_with_credit` to `summary`; add a `has_credit` filter and credit sort
  - **Compute both credit figures branch-wide, outside `walletActivityStudents()`.** That helper restricts the list to students with `balance > 0` or at least one wallet transaction. Deriving the KPI from the filtered set would let this page disagree with the credit report's `net_outstanding` — two screens giving different answers to "how much is owed". Use `Student::where('branch_id', …)->where('credit_balance', '>', 0)->selectRaw('SUM(credit_balance), COUNT(*)')`
  - In `app/Http/Controllers/Kitchen/WalletHistoryController.php`: add a `credit` branch to the `type` switch returning that student's credit charges and settlements
  - Write integration tests in `tests/Feature/Kitchen/WalletReportCreditTest.php`:
    - `summary` returns `total_outstanding_credit` and `students_with_credit`
    - `has_credit=1` excludes students with zero credit
    - Credit sort orders by `credit_balance`
    - `type=credit` history returns that student's credit entries, paginated
    - Another branch's students are excluded
    - The Excel export still contains the `Outstanding Credit` column
    - `total_outstanding_credit` equals the credit report's `net_outstanding` for the same branch — the cross-screen consistency guard
  - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.6, 11.7_

- [ ] 6.3 Add credit collections to the daily summary
  - In `app/Http/Controllers/Kitchen/DailySummaryController.php`: add a `credit_collections` block grouping `settled` entries for the branch and date by `payment_method`, exposing `total`, `count`, and per-method figures with `wallet` reported as `from_wallet`. Leave `total_revenue` and `payment_breakdown` untouched
  - Write integration tests in `tests/Feature/Kitchen/DailySummaryCreditTest.php`:
    - Cash, GCash, bank transfer, and wallet settlements each land in their own bucket
    - `total` and `count` reflect only the requested day
    - `total_revenue` and `payment_breakdown` are byte-identical before and after a settlement
    - A settlement in another branch is excluded
    - A day with no settlements returns `total: 0` and `count: 0`
  - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5_

---

## 7. POS app (`~/sunbites-pos`)

- [ ] 7.1 Add types, API service methods, and hooks
  - Add `LedgerEntry`, `LedgerEntryType`, `CreditSettlementMethod`, and the settle/waive request and response types to `types/`
  - Add `ledger()`, `settleCredit()`, `waiveCredit()` to `lib/api/students.ts`; extend the report types in `lib/api/reports.ts`
  - Add `lib/validation/credit.ts` with Zod 4 `settleCreditSchema` (amount positive and not exceeding outstanding; `reference_number` required when method is `gcash` or `bank_transfer`) and `waiveCreditSchema` (reason 5–1000 chars)
  - Add `hooks/use-student-ledger.ts`, `hooks/use-settle-credit.ts`, `hooks/use-waive-credit.ts`
  - Update MSW handlers in `__tests__/mocks/handlers.ts` for `/ledger`, `/credit/settle`, `/credit/waive`, and the extended report summaries
  - Write unit tests for `lib/validation/credit.ts`:
    - Valid payloads pass for each payment method
    - Amount above outstanding fails with a field error
    - GCash without a reference fails; with a reference passes
    - Reason shorter than 5 or longer than 1000 characters fails
  - _Requirements: 6.2, 7.6, 7.8_

- [ ] 7.2 Extract the student detail page into components
  - `app/(kitchen)/students/[id]/page.tsx` is 3,265 lines and holds the header, all tabs, and every modal inline. Extract only the surfaces this spec touches into `app/(kitchen)/students/[id]/_components/`, mirroring the pattern already proven in the portal: `student-header.tsx`, `wallet-tab.tsx`, `top-up-dialog.tsx`
  - Pure extraction — no behaviour change in this task. Leave the other tabs in `page.tsx` untouched; this is not a wholesale refactor
  - Write component tests:
    - `student-header.test.tsx` — renders name, grade, status badges, wallet and QR chips
    - `wallet-tab.test.tsx` — renders the balance and the transaction table
    - `top-up-dialog.test.tsx` — renders amount, payment method, and note fields; submits with the entered values
    - Confirm `student-detail.test.tsx` still passes unchanged
  - _Requirements: 7.1, 7.3, 7.4_

- [ ] 7.3 Add the Credit Owed chip to the header
  - In `_components/student-header.tsx`, render a "Credit Owed" chip beside the Wallet and QR ID chips when `credit_balance > 0`, styled for attention
  - Write component tests in `student-header.test.tsx`:
    - Chip renders with the formatted amount when credit is above zero
    - Chip is absent when `credit_balance` is 0
  - _Requirements: 7.1, 7.2_

- [ ] 7.4 Rebuild the Wallet tab on the unified ledger
  - In `_components/wallet-tab.tsx`: render Current Balance and Outstanding Credit as side-by-side cards, with the credit card muted at zero so the layout does not shift; add a "Settle Credit" action beside "Top Up"; replace the transaction table's data source with `use-student-ledger`; add `All` / `Top-up` / `Purchases` / `Credit` filters and pagination
  - Write component tests in `wallet-tab.test.tsx`:
    - Both stat cards render with their amounts
    - Ledger rows render with type label, signed amount, note, and staff
    - Each filter narrows the rows to matching entry types
    - Loading renders a skeleton, error renders an error message, empty renders an empty state
    - Pagination advances to the next page
  - _Requirements: 7.3, 7.4, 7.5_

- [ ] 7.5 Build the Settle Credit modal
  - Create `_components/settle-credit-dialog.tsx`: shows outstanding credit and wallet balance; amount defaults to the full outstanding figure with a "Full amount" shortcut; Cash / GCash / Bank Transfer / From Wallet selector; From Wallet disabled with an inline reason whenever the wallet balance is below the entered amount, re-evaluating as the amount changes; Reference Number shown only for GCash and Bank Transfer; a projected "credit after settlement" line; submit disabled while pending
  - Write component tests in `settle-credit-dialog.test.tsx`:
    - Renders outstanding credit, wallet balance, and the pre-filled amount
    - From Wallet is disabled with a reason when the balance is short, and enabled once the amount drops below the balance
    - Reference field appears only for GCash and Bank Transfer
    - Invalid amount shows a field-level error and does not call the API
    - Successful submit calls the API with the entered values and closes the dialog
    - A 422 from the API shows the message inline and leaves the form populated
    - Submit is disabled while the mutation is pending
  - _Requirements: 7.6, 7.7, 7.8, 7.9_

- [ ] 7.6 Build the Waive Credit action and modal
  - Add "Waive Credit" to the student detail overflow menu, rendered only for users holding `admin`
  - Create `_components/waive-credit-dialog.tsx`: warns that the amount will not be collected, requires a reason, uses destructive styling on confirm, submit disabled while pending
  - Write component tests in `waive-credit-dialog.test.tsx`:
    - Overflow menu shows the action for an admin and hides it for a manager
    - Submitting without a reason shows a field-level error and does not call the API
    - A reason shorter than 5 characters shows an error
    - Successful submit calls the API and closes the dialog
    - Submit is disabled while pending
  - _Requirements: 7.9, 7.10, 7.11_

- [ ] 7.7 Add the credit warning to the Top Up modal
  - In `_components/top-up-dialog.tsx`, render a warning above the amount field when `credit_balance > 0` stating the outstanding amount and that topping up does not pay it, with a control that closes this dialog and opens the Settle Credit dialog
  - Write component tests in `top-up-dialog.test.tsx`:
    - Warning renders with the outstanding amount when credit is above zero
    - Warning is absent when credit is 0
    - Activating the control closes the top-up dialog and opens the settle dialog
  - _Requirements: 5.4_

- [ ] 7.8 Update the report pages
  - `app/(kitchen)/reports/credits/page.tsx` — add the Waived KPI card, `payment_method` column, method filter, and the `settled_by_method` breakdown with wallet visually separated from the cash-bearing methods
  - Fix the Staff column at **lines 378–379**, which currently reads `` `${row.performed_by.first_name} ${row.performed_by.last_name}` ``. The API sends `performed_by` as a plain string (`CreditReportController` line 70), so both property reads yield `undefined` — hence `undefined undefined` on screen. Render `row.performed_by` directly and update the row type to `string | null`
  - `app/(kitchen)/reports/wallet/` — add the Credit Owed KPI card, `has_credit` filter, credit sort, and a `Credit` panel in `wallet-history-panel.tsx` alongside Purchases and Top-Ups. Relabel the KPI cards to "Total Deposits" and "Total Spent"
  - Daily summary page — render the `credit_collections` section beneath the sales figures, with `from_wallet` marked as not contributing drawer cash
  - Write component tests:
    - Credits page renders the staff name as text, never `undefined undefined`
    - Credits page method filter narrows the rows
    - Credits page renders the Waived KPI and the method breakdown
    - Wallet page renders the Credit Owed KPI and filters to students with credit
    - Wallet history panel renders the Credit tab with credit entries
    - Daily summary renders the credit collections section, and hides or zeroes it when nothing was collected
  - _Requirements: 10.3, 10.4, 10.5, 10.8, 10.9, 11.1, 11.2, 11.3, 11.4, 11.5, 12.1, 12.4_

---

## 8. Parent portal (`~/sunbites-portal`)

- [ ] 8.1 Add types and API methods
  - Add `LedgerEntry` and `LedgerEntryType` to `types/portal.ts`; add `credit_balance` to the student and wallet types
  - Add `studentLedger()` to `lib/api/portal.ts`
  - Call `useQuery` inline in `wallet-tab.tsx` rather than adding a `hooks/` directory. This repo has no `hooks/` folder and all 12 of its query call sites define `useQuery` inline in the consuming component. `structure.md` lists `hooks/` for the portal, but the codebase does not have it — following the codebase. (Recorded as steering drift in the follow-ups below; the POS repo does have `hooks/`, so task 7.1 uses it correctly.)
  - Update MSW handlers in `__tests__/mocks/handlers.ts` for `/portal/students/{id}/ledger` and the `credit_balance` additions
  - Write unit tests for the API service module:
    - `studentLedger()` builds the correct URL with `entry_type`, `from`, `to`, and `page` params
  - _Requirements: 8.1, 8.4, 8.6_

- [ ] 8.2 Add the credit line to dashboard student cards
  - The card is inline in `app/(portal)/dashboard/page.tsx` — the "Wallet balance" label is at line 75. It is **not** in `_components/`, which holds only the spending-insights widgets
  - Render a "Credit owed" line beneath the wallet balance when `credit_balance > 0`, linking to that student's Wallet tab
  - Write component tests:
    - Credit line renders with the formatted amount when credit is above zero
    - Card renders unchanged with no credit line when `credit_balance` is 0
    - The link targets the student's wallet tab
  - _Requirements: 8.2, 8.3_

- [ ] 8.3 Add the credit card and Credit filter to the portal Wallet tab
  - In `app/(portal)/students/[id]/_components/wallet-tab.tsx`: render an Outstanding Credit card below Current Balance when `credit_balance > 0`, with copy explaining the credit was used at the canteen and that it must be settled at the counter because it cannot be paid online; hide the card entirely at zero
  - Add a `Credit` pill to `_components/filter-pills.tsx` alongside `All`, `Top-up`, and `Deductions`; switch the transaction table to the ledger endpoint
  - Write component tests in `wallet-tab.test.tsx` and `filter-pills.test.tsx`:
    - Outstanding Credit card renders with the amount and the counter-only copy above zero
    - Card is absent when `credit_balance` is 0
    - The `Credit` pill filters rows to credit entry types
    - Credit entries render with readable labels — "Credit used", "Credit paid"
    - Loading, error, and empty states each render for the ledger query
  - _Requirements: 8.5, 8.7_

- [ ] 8.4 Render the new notification types
  - Extend `components/notification-item.tsx` to render `CreditChargedNotification` and `CreditSettledNotification` payloads
  - Ensure the Echo listener invalidates the unread-count query when either broadcast arrives
  - Write component tests in `notification-item.test.tsx`:
    - A charged notification renders the student name, charged amount, and outstanding balance
    - A settled notification renders the paid amount and the resulting balance
    - A waived notification renders distinct copy from a settled one
    - Receiving either broadcast invalidates the unread-count query
  - _Requirements: 9.4, 9.5, 9.7_

---

## Requirement coverage

| Requirement | Tasks |
|---|---|
| 1 — Credit Ledger Service | 1.1, 1.2, 2.1, 2.2, 2.3, 2.4, 2.5 |
| 2 — Settle with counter payment | 1.2, 2.2, 3.1, 3.2 |
| 3 — Settle from wallet | 1.1, 1.2, 2.3, 3.2 |
| 4 — Waive credit | 1.1, 1.2, 2.4, 3.1, 3.2 |
| 5 — Top-up independence | 3.3, 7.7 |
| 6 — Unified ledger | 1.1, 4.1, 4.2, 7.1 |
| 7 — POS credit visibility | 7.2, 7.3, 7.4, 7.5, 7.6 |
| 8 — Portal credit visibility | 4.3, 8.1, 8.2, 8.3 |
| 9 — Parent notifications | 5.1, 5.2, 8.4 |
| 10 — Credit Collection Report | 6.1, 7.8 |
| 11 — Wallet Report | 6.2, 7.8 |
| 12 — Daily Summary | 1.1, 6.3, 7.8 |

Every acceptance criterion in `requirements.md` maps to at least one task.

---

## Residual risk — what was NOT verified

Stated plainly so nobody mistakes this plan for fully de-risked.

1. ~~**The existing test suite was not run.**~~ **Resolved.** Baseline recorded before implementation started: `vendor/bin/sail artisan test --compact` → **742 tests, 742 passed, 1,952 assertions, 33.0s, exit 0**. The suite is fully green, so any failure appearing during implementation is caused by this feature — there is no pre-existing-failure ambiguity at the verification gates. Re-baseline if a long gap passes before work resumes.
2. **Task 7.2 is the least predictable task in the plan.** `app/(kitchen)/students/[id]/page.tsx` is 3,265 lines; the header, wallet tab, and top-up dialog were located but the file was not read end to end. Shared state, closures, or mutation handlers spanning the extracted boundaries could make this larger than one task. If extraction starts sprawling, stop and split it rather than pushing through — the surrounding tasks depend on it landing cleanly.
3. **Portal `wallet-tab.tsx` and the POS wallet and daily-summary report pages were located but not read in full.** Their task descriptions specify intent and file paths, not line-level edits. Expect to spend orientation time before editing.
4. **No load testing on the ledger union.** The query plan was verified as correct against an empty table; it was not measured against a student with thousands of entries. The composite index on `(branch_id, type, created_at)` targets the report queries, not the per-student ledger, which relies on `credit_transactions.student_id` and `transactions.wallet_id` — both already indexed.
5. **The local `laravel` database was empty and has now been migrated** as part of verifying the schema claims. No seed data was loaded, so a `db:seed` may be needed before manual testing.

## Steering amendments — applied

All four were approved and applied before implementation began.

| # | File | Change |
|---|---|---|
| 1 | `product.md` | Supervisor and Cashier role descriptions rewritten. Supervisor's was **already wrong** before this spec — it claimed "no mutations" while `routes/kitchen-api.php:142-167` lets supervisors edit students, soft-delete them, regenerate QR codes, and top up wallets. Now states actual capability plus credit settlement. Cashier gains credit settlement only; verified excluded from the top-up group. |
| 2 | `structure.md` | Feature registry row `\| 14 \| Credit Settlement \| Not started \| Not started \|`. Flip each column to Complete as that side lands. |
| 3 | `structure.md` | Removed the `hooks/` line from the portal directory tree — that directory does not exist and all 12 portal query call sites define `useQuery` inline. The POS entry stays; its `hooks/` is real. |
| 4 | `tech.md` | Form Request rule reworded from a false description of the codebase into a forward-looking rule with an explicit stance on the 47 legacy inline validators. |

### Still open — not approved, not applied

- **`product.md` scope boundaries** reads "Out of scope (as of specs 01–10)" while 14 specs now exist. Stale, but outside the approved diffs, so left untouched. Worth a separate decision.
- **`tech.md` `$request->validated()` only — never `$request->all()`** was not audited for compliance. Unknown whether the codebase honours it.
