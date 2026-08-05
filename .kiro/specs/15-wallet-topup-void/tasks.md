# Spec 15 — Wallet Top-up Void · Implementation Plan

Backend tasks (1–5) live in `~/sunbites-api`. Task 6 is the **dedicated frontend section**, entirely in `~/sunbites-pos`, and does not begin until task 5 is complete and its endpoint/contract is stable — the frontend consumes the exact response/ledger shape task 4 and task 3 produce. Every task follows `testing.md`: real database, `RefreshDatabase`, `actingAs`, factories, and a test bullet is never optional.

---

## 1. Data model foundation

- [x] 1.1 Create the `wallet_topup_voids` migration
  - `database/migrations/2026_08_03_100000_create_wallet_topup_voids_table.php` — exact schema in design.md's Data Models section: `student_id` (FK, cascade delete), `branch_id` (nullable FK, null on delete), `wallet_transaction_id` (unsigned big int, **unique**, no FK — bavix-owned table), `refund_wallet_transaction_id` (nullable unsigned big int, indexed, no FK), `credit_transaction_id` (nullable FK to `credit_transactions`, null on delete), `original_amount`/`voided_amount`/`shortfall_amount` (decimal 10,2; `shortfall_amount` defaults 0), `void_reason` (string 500), `voided_by` (FK users), `created_at` (nullable timestamp, no `updated_at`)
  - Run `vendor/bin/sail artisan migrate` and confirm it applies cleanly against the current schema
  - Write a test asserting the migration's `down()` drops the table cleanly (a minimal `tests/Feature/Kitchen/WalletTopupVoidMigrationTest.php`, or fold this assertion into task 1.2's model test file if a separate migration test file feels redundant with what's already covered by the model factory succeeding)
  - _Requirements: 6.1, 6.2_

- [x] 1.2 Create the `WalletTopupVoid` model and factory
  - `app/Models/WalletTopupVoid.php` per design.md Component 2 exactly: `$timestamps = false`, `$fillable` listing all ten writable columns, `decimal:2` casts on the three amount columns, `datetime` cast on `created_at`, `student()`/`branch()`/`creditTransaction()`/`voidedBy()` `BelongsTo` relations. Deliberately no `HasBranch` trait — see the class docblock rationale already specified in design.md, copy it verbatim into the model's docblock.
  - `database/factories/WalletTopupVoidFactory.php` — states for: a fully-recovered void (`shortfall_amount = 0`, `credit_transaction_id = null`), and a partial-shortfall void (`shortfall_amount > 0`, `credit_transaction_id` set to an associated `CreditTransaction::factory()`)
  - Write a unit test (`tests/Unit/Models/WalletTopupVoidTest.php` or fold into the Feature test in task 3 if this project's convention is to skip trivial model-only tests — check sibling model test files before deciding) confirming the casts produce `float`-comparable decimals and the four relations resolve
  - _Requirements: 6.1, 6.2_

---

## 2. Generalize `CreditLedgerService::charge()`

- [x] 2.1 Rename `charge()`'s third parameter and update its one call site
  - In `app/Services/CreditLedgerService.php`: rename `string $receiptNumber` → `string $description` in `charge()`'s signature and its inner closure's `use()` clause; change the notes line from `"Credit used for order {$receiptNumber}."` to `"Credit used for {$description}."`; update the method's docblock to describe `$description` generically (it documents "the order's receipt number" today — that phrasing must change since the caller is no longer always an order)
  - In `app/Http/Controllers/Kitchen/CheckoutController.php`, update the existing call site from `$this->creditLedger->charge($student, $creditAmount, $receiptNumber, $request->user())` to `$this->creditLedger->charge($student, $creditAmount, "order {$receiptNumber}", $request->user())` — this preserves the exact existing notes string. **This change must ship in the same commit/PR as the signature rename** — see design.md's Migration & Rollout note on why splitting these breaks `CheckoutController` silently (wrong string, not a runtime error).
  - Run the existing `tests/Feature/Kitchen/CreditLedgerServiceTest.php` and confirm every assertion of `"Credit used for order {receipt}."`-style text still passes unchanged. If any such assertion fails, the call-site update was done incorrectly — fix the call site, do not edit the test to match new output.
  - No new test is required for this task itself (it is a rename with preserved behavior); the regression run above is the verification.
  - _Requirements: 5.4 (prerequisite — the void flow in task 3 depends on this signature existing)_

---

## 3. Void endpoint — request, controller, route

- [x] 3.1 Create `VoidWalletTopUpRequest`
  - `app/Http/Requests/VoidWalletTopUpRequest.php` per design.md Component 3: `authorize()` returns `true` (route middleware is the gate), `rules()` returns `['reason' => ['required', 'string', 'max:500']]`, `prepareForValidation()` strips tags from `reason` when present — mirroring `WaiveCreditRequest`'s exact `strip_tags` pattern
  - Write a unit/feature test covering: missing `reason` fails validation; a `reason` over 500 characters fails; a `reason` containing HTML tags is stripped before reaching the controller (assert via a full request round-trip, not by calling the FormRequest in isolation, since `prepareForValidation()` only runs inside the HTTP validation lifecycle)
  - _Requirements: 1.4_

- [x] 3.2 Implement `WalletController::voidTopUp()`
  - Add the method to the existing `app/Http/Controllers/Kitchen/WalletController.php` (do not create a new controller), copying the exact enforcement sequence from design.md Component 4: resolve-scoped-to-wallet-and-type → idempotency pre-check → self-void check (checking `performed_by` then falling back to `cashier_id`) → time-tier check (`manager` blocked unless `created_at` is today; `admin` unrestricted) → row-locked transaction containing a **second** idempotency check, the `voided_amount`/`shortfall_amount` computation, the conditional `withdraw()`, the conditional `CreditLedgerService::charge()` call, the `WalletTopupVoid::create()`, and the original transaction's `meta` annotation → activity log → JSON response
  - Add required imports: `VoidWalletTopUpRequest`, `WalletTopupVoid`, `Illuminate\Support\Facades\DB`, `Carbon\Carbon` (match the existing `use Carbon\Carbon;` style already used in `PaymentController.php`, not `Illuminate\Support\Carbon`), `App\Services\CreditLedgerService`
  - Add `Route::post('/students/{student}/wallet/top-ups/{transaction}/void', [WalletController::class, 'voidTopUp']);` to `routes/kitchen-api.php`, immediately after the existing `wallet/top-up` route at line 164, inside the same `role:admin|manager|supervisor` middleware group (the "Enrollment & Students" group) — no new middleware group. **Note:** originally documented as a `role:admin|manager`-only group; corrected after the implementer's own role-gate test caught the discrepancy against the real routes file — see requirements.md Requirement 4's amendment note and design.md Component 5's amendment note. `supervisor` is allowed through the gate and is subject to the same same-day window as `manager`; only `cashier` is blocked.
  - Write the full `tests/Feature/Kitchen/WalletTopUpVoidTest.php` suite — every numbered scenario from design.md's Testing Strategy backend list (1 through 16, plus 20): happy-path full recovery, partial-spend shortfall-to-credit, full-spend shortfall-to-credit, credit-limit exemption, idempotency (double-void), self-void blocked (both `performed_by` and `cashier_id` origins), self-void not blocked when performer is unknown, same-day window blocking a manager, same-day window allowing an admin, a same-day cross-manager void succeeding, role gate blocking cashier only (supervisor allowed through the gate, subject to the manager-tier same-day window), 404 for another student's transaction, 404 for a `withdraw`-type transaction id, validation failure for missing reason, the meta-annotation assertion (`voided_at`/`wallet_topup_void_id` present, `amount`/`type` unchanged), and the `CreditChargedNotification` fired-on-shortfall / not-fired-on-full-recovery pair (scenario 20, using `Notification::fake()`)
  - _Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 6.1, 6.3, 8.1, 8.2_

---

## 4. Unified ledger integration

- [x] 4.1 Add `LedgerEntryType::TopupVoided`
  - In `app/Enums/LedgerEntryType.php`: add the `TopupVoided = 'topup_voided'` case; add its arm to `label()` (`'Top-up Voided'`), `direction()` (`'debit'`), `isCreditEntry()` (`false`); add it to the `'topup'` filter's result array in `forFilter()` alongside `Deposit`
  - Write a unit test asserting `TopupVoided->label()`, `->direction()`, `->isCreditEntry()`, and that `LedgerEntryType::forFilter('topup')` returns both `Deposit` and `TopupVoided` while `forFilter('purchase')` does not include `TopupVoided`
  - _Requirements: 7.1, 7.7_

- [x] 4.2 Update `StudentLedgerQuery`'s two legs together
  - In `app/Services/StudentLedgerQuery.php`: rewrite `walletLeg()` to LEFT JOIN `wallet_topup_voids` twice (aliased `original_void` on `wallet_transaction_id`, aliased `refund_void` on `refund_wallet_transaction_id`), add the `entry_type` `CASE WHEN refund_void.id IS NOT NULL THEN 'topup_voided' ELSE transactions.type END` expression, and add two trailing selected columns: `voided` (`CASE WHEN original_void.id IS NOT NULL THEN 1 ELSE 0 END`) and `wallet_transaction_id` (`transactions.id`)
  - In the **same change**, update `creditLeg()` to add matching placeholder columns `0 AS voided` and `NULL AS wallet_transaction_id` in the same trailing position — the two legs' `SELECT` lists must stay positionally identical for `unionAll()` to work. Do not ship one leg's change without the other.
  - Write a test that calls `StudentLedgerQuery::for($student, 'all')` end-to-end (via the `GET /students/{id}/ledger?entry_type=all` endpoint, exercising the full stack rather than the query builder in isolation) after seeding a mix of deposits, withdrawals, and credit entries, and asserts the query executes without a column-count/union error and returns the expected row count — this is the regression guard design.md calls out explicitly for the union-column-parity risk
  - _Requirements: 7.2, 7.3_

- [x] 4.3 Update `LedgerEntryFormatter`
  - In `app/Services/LedgerEntryFormatter.php`'s `format()`: add `'voided' => (bool) ($row->voided ?? false)` and `'wallet_transaction_id' => $row->wallet_transaction_id !== null ? (int) $row->wallet_transaction_id : null` to the returned array. No changes to `resolveNote()` or `performerId()` — confirm (via the test below) that a `topup_voided` row's `note`/`performed_by` already resolve correctly through the existing, unmodified logic once the controller (task 3.2) writes `note`/`performed_by` keys into the refund transaction's `meta`.
  - Write/extend a formatter test asserting: a plain (non-voided) deposit row has `voided: false` and a populated `wallet_transaction_id`; a voided deposit row has `voided: true` and still a populated `wallet_transaction_id`; a `credit_charged` row has `voided: false` and `wallet_transaction_id: null`; an ordinary purchase (`withdraw`, no associated void) row has `wallet_transaction_id: null` — **this specific case is the one most likely to regress**, since it is tempting to simplify the SQL to expose the id unconditionally on every wallet-leg row rather than gating it on `transactions.type = 'deposit'`; a `topup_voided` row also has `wallet_transaction_id: null` (same reasoning) and its `note` equals the void reason and `performed_by` equals the voiding staff member's resolved name
  - _Requirements: 7.4, 7.5, 7.6_

- [x] 4.4 End-to-end ledger integration test
  - New scenarios (or added to `WalletTopUpVoidTest.php` from task 3.2 — implementer's choice, but they must exist somewhere): after a successful void with a shortfall, `GET /students/{id}/ledger?entry_type=all` shows the original deposit with `voided: true`, a `topup_voided` row, and a `credit_charged` row, in that order or any order (assert presence and field values, not strict ordering unless `created_at` ordering already guarantees it); `entry_type=topup` includes the `topup_voided` row; `entry_type=purchase` excludes it (the specific regression this design's `CASE` expression exists to prevent — proves the reversal isn't miscategorized as a purchase)
  - Confirm the **Portal** `StudentLedgerController` (`app/Http/Controllers/Portal/StudentLedgerController.php`) surfaces the same `topup_voided`/`voided`/`wallet_transaction_id` fields with no code change required there (it shares `StudentLedgerQuery`/`LedgerEntryFormatter`) — write one parent-facing test (`actingAs($parent, 'parents')`) confirming this, and confirming the void reason **is** visible to the parent (per design.md's explicit decision not to add `TopupVoided` to the staff-only note suppression list)
  - _Requirements: 7.2–7.7 (integration confirmation, not new rules)_

---

## 5. Reporting integrity

- [x] 5.1 Add the `voided` flag to `WalletHistoryController::topups()`
  - In `app/Http/Controllers/Kitchen/WalletHistoryController.php`'s `topups()`: LEFT JOIN `wallet_topup_voids` on `wallet_transaction_id = transactions.id`, qualify the existing `select(['id', 'amount', 'meta', 'created_at'])` columns with the `transactions.` prefix (now required due to the join) and add `wallet_topup_voids.id as void_id`; in `formatTopup()`, add `'voided' => $tx->void_id !== null`
  - Write a test: a voided top-up still appears in the `type=topups` history list (not silently removed) and carries `voided: true`; a non-voided top-up carries `voided: false`; the existing `search` behavior (matching by staff name via `performed_by`/`cashier_id` in meta) is unaffected by the join — run the existing test file for this controller if one exists, or write coverage for this endpoint if none currently exists, before adding the new assertions
  - _Requirements: 9.1_

- [x] 5.2 Exclude voided amounts from `WalletReportController`'s deposit AND purchase totals
  - Fix exactly two SQL blocks in `app/Http/Controllers/Kitchen/WalletReportController.php`, per design.md Component 11's fully-written-out queries: the branch-level `$walletSummary` in `index()` (columns `total_credits`/`total_debits`) and the shared `buildTxStats()` helper (columns `total_credited`/`total_debited`, reused by both `index()` and `export()`)
  - Each block needs **two** joins against `wallet_topup_voids` (aliased `original_void` on `wallet_transaction_id`, and `refund_void` on `refund_wallet_transaction_id`) and **two** corrected `CASE` expressions: the deposit-side sum subtracts `original_void.voided_amount * 100`; the withdraw-side sum excludes any row where `refund_void.id IS NOT NULL` entirely (the void's own reversal withdrawal is not a purchase and must not count as one)
  - Write tests covering all four corrected columns: seed a top-up, void part of it (creating a reversal withdraw), and assert (a) `total_credits`/`total_credited` reflect only the unvoided remainder, and (b) `total_debits`/`total_debited` do **not** include the reversal withdrawal's amount (this second assertion is the one most likely to be skipped — the deposit-side fix is the "obvious" one; the withdrawal-side pollution from the reversal is easy to miss entirely)
  - _Requirements: 9.2, 9.3_

---

## 6. POS app (`~/sunbites-pos`) — Frontend

**This is the dedicated frontend task section.** Nothing here begins until tasks 1–5 are complete and the endpoint/ledger contract they produce is stable, since every task below consumes that exact contract (response shape, `LedgerEntry.voided`, `LedgerEntry.wallet_transaction_id`, `entry_type: "topup_voided"`).

- [x] 6.1 Add types and the API service method
  - In `types/student.ts`: add `"topup_voided"` to the `LedgerEntryType` union; add `voided: boolean` and `wallet_transaction_id: number | null` to `LedgerEntry`; add `VoidWalletTopUpPayload` (`{ reason: string }`) and `VoidWalletTopUpResponse` (`{ message, voided_amount, shortfall_amount, new_wallet_balance, new_credit_balance }`) interfaces — exact shapes per design.md Component 12
  - In `lib/api/students.ts`: add `voidTopUp: (studentId, walletTransactionId, payload) => apiClient.post<VoidWalletTopUpResponse>(...)` per design.md Component 13, importing the two new types
  - Update `__tests__/mocks/handlers.ts` with a mock handler for `POST /students/:id/wallet/top-ups/:transactionId/void` returning a representative `VoidWalletTopUpResponse`
  - Write a unit test for the new `studentApi.voidTopUp` service call if this project's convention tests service-layer functions directly (check whether `lib/api/students.ts`'s existing methods like `topUp` have direct unit tests, or are only covered indirectly through component tests — match whatever the existing convention is, do not introduce a new testing layer for this one function)
  - _Requirements: 10.4, 10.6, 10.7_

- [x] 6.2 Build `VoidTopUpDialog`
  - Create `app/(kitchen)/students/[id]/_components/void-topup-dialog.tsx` as a structural sibling of `settle-credit-dialog.tsx` (not a modification of it) per design.md Component 14: props `{ open, onClose, studentId, walletTransactionId, originalAmount, currentWalletBalance }`; local Zod schema requiring a non-empty `reason` up to 500 characters; displays original amount and current wallet balance; shows the "already spent → becomes credit debt" warning only when `currentWalletBalance < originalAmount`, with the exact shortfall amount; `useMutation` calling `studentApi.voidTopUp`; `onSuccess` invalidates `["student", studentId]` and `["student-ledger", studentId]` (the same keys `SettleCreditDialog` already invalidates — do not invent new query key shapes) then closes and resets; `onError` surfaces the server message the same way `SettleCreditDialog` does
  - Write `void-topup-dialog.test.tsx` per design.md's Testing Strategy frontend list: renders original amount and wallet balance; shows the shortfall warning only when balance is short (and not when it isn't); submit disabled with empty reason, enabled once typed; reason over 500 characters shows a field error and blocks submit; successful submission invalidates both query keys and closes; a mocked 403 self-void error keeps the dialog open and renders the server message; submit is disabled while `mutation.isPending`
  - _Requirements: 10.4, 10.5, 10.6, 10.7_

- [x] 6.3 Wire the Void action into `WalletTab`'s ledger rows
  - In `app/(kitchen)/students/[id]/_components/wallet-tab.tsx`: add `useState<LedgerEntry | null>(null)` (e.g. `voidTarget`) to track which row's dialog is open, carrying the row's data rather than a bare boolean, since the dialog needs `wallet_transaction_id`/`amount`; compute `canVoidTopUp` once via `useAuthStore` as `user?.roles.includes("admin") === true || user?.roles.includes("manager") === true || user?.roles.includes("supervisor") === true` — this extends, rather than exactly matches, the narrower `admin || manager` pattern at `page.tsx:2471-2472` (per Requirement 4's amendment, this feature's role tier is deliberately broader); thread `canVoidTopUp` (and the current user's role) into `LedgerRow`
  - In `LedgerRow`: render a "Void" action when `entry.entry_type === "deposit" && entry.voided === false && canVoidTopUp`; when the current user has `"manager"` or `"supervisor"` but not `"admin"` and `entry.date` is not today (string-compare `toDateString()`), render the action `disabled` with a `title` explaining the admin-only same-day-exception rule; when `entry.voided === true`, render a "Voided" badge (reusing the existing badge visual treatment already used for `entry_type.startsWith("credit_")`) and add a `line-through` class to the amount cell; `entry_type === "topup_voided"` rows need no new styling — confirm the existing `direction === "debit"` red/minus rendering and the API-provided `entry_label` already produce the correct look
  - Render `<VoidTopUpDialog>` at the bottom of `WalletTab`, controlled by `voidTarget`
  - Write/extend `wallet-tab.test.tsx` per design.md's Testing Strategy frontend list: "Void" renders for an admin/manager on a voidable row and not for a cashier; "Void" is absent (badge shown instead) on a `voided: true` row; for a manager-only user, the action is present but `disabled` on a non-today row with the explanatory title present; clicking "Void" opens `VoidTopUpDialog` with the correct row data (assert via the dialog's rendered content — the displayed amount — not via internal prop inspection)
  - _Requirements: 10.1, 10.2, 10.3, 11.1, 11.2_

- [x] 6.4 Confirm no change is needed for the `credit_charged` row rendering
  - Manually trace (no code change expected) that a `credit_charged` ledger entry created as a byproduct of a void (task 3.2's shortfall path) renders identically to any other `credit_charged` entry already handled by the Spec 14 `WalletTab` implementation — if it does not (e.g. a byproduct entry needs to look different from a checkout-originated one), stop and flag this back to the design rather than silently adding new frontend logic here
  - Write one test in `wallet-tab.test.tsx` confirming a `credit_charged` row sourced from a void response renders with the standard credit-charged styling — this is a confirmation test, not new functionality
  - _Requirements: 11.3_

---

## Requirement coverage

| Requirement | Covered by task(s) |
|---|---|
| 1.1–1.4 | 3.1, 3.2 |
| 2.1–2.2 | 3.2 |
| 3.1–3.3 | 3.2 |
| 4.1–4.4 | 3.2 |
| 5.1–5.7 | 2.1, 3.2 |
| 6.1–6.3 | 1.1, 1.2, 3.2 |
| 6.4 | 4.2, 5.1 (join-based, never JSON-parsing — enforced where the joins actually live) |
| 7.1–7.7 | 4.1, 4.2, 4.3, 4.4 |
| 8.1–8.2 | 3.2 |
| 9.1–9.3 | 5.1, 5.2 |
| 10.1–10.7 | 6.1, 6.2, 6.3 |
| 11.1–11.3 | 6.3, 6.4 |
| Cross-Cutting — Security/Authorization | 3.2, 6.3 |
| Cross-Cutting — Data Isolation | 1.1 (schema), 3.2 (inherits `Student`'s `HasBranch` scoping) |
| Cross-Cutting — Performance (indexes) | 1.1 |
| Cross-Cutting — Error Handling | 3.2, 6.2 |
| Cross-Cutting — Observability | 3.2 (activity log) |

Every acceptance criterion in `requirements.md` maps to at least one task above; no requirement is uncovered.

---

## Out of scope for this task list (per requirements.md)

No tasks exist for: un-voiding a void, voiding purchases/checkout deductions/credit settlements, payment-method-specific void rules, void-frequency rate limiting or anomaly detection, or any Parent Portal code change (Portal picks up the ledger changes automatically per task 4.4's confirmation test — it requires no Portal-side implementation task because none of its own code changes).
