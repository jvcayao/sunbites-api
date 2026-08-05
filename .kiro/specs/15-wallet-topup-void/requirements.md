# Requirements Document

## Introduction

POS staff can add money to a student's wallet (`WalletController::topUp`, `POST /students/{student}/wallet/top-up`) and can also reload a wallet mid-checkout (`InlineReloadController::store`, `POST /pos/inline-reload`). Both call `$student->deposit()` and nothing else — there is no way to reverse either action today. If a cashier mistypes an amount (e.g. ₱1000 instead of ₱100), the only "fix" available is a second, manual, undocumented top-up or withdrawal with no audit trail tying it to the original mistake.

This feature adds a **wallet top-up void**: a staff-initiated reversal of a specific deposit, gated by role and time, resilient to the money having already been partially or fully spent, and fully visible in the unified student ledger introduced by Spec 14 (Credit Settlement) — not a parallel, disconnected mechanism.

**Depends on:** Spec 05 (Student Management — wallet top-up), Spec 06 (POS & Checkout — inline reload, order void precedent), Spec 08 (Reports & Dashboard — wallet report), Spec 09 (System Configuration — `credit_limit`), Spec 14 (Credit Settlement — `CreditLedgerService`, unified ledger, `LedgerEntryType`, `StudentLedgerQuery`, `LedgerEntryFormatter`).

**Note on Spec 14 status:** `.kiro/steering/structure.md`'s Feature Registry currently lists Spec 14 as "Not started" for both backend and frontend. This is stale — `git log` shows `2e502c5 feat: multi-channel credit settlement (spec 14)` merged, `app/Services/CreditLedgerService.php` has all five documented methods implemented, the `credit_transactions` table has the settlement columns migration applied, and `~/sunbites-pos` has `settle-credit-dialog.tsx`, `waive-credit-dialog.tsx`, and the unified ledger UI (`wallet-tab.tsx` via `useStudentLedger`) already built and tested. This spec is written against the **actual, merged Spec 14 implementation**, verified by reading the code directly. A steering correction is proposed separately (see conversation) and requires human approval before `structure.md` is edited.

---

## Requirements

### Requirement 1 — Void a wallet top-up transaction

**User Story:** As an admin, manager, or supervisor, I want to void a specific wallet top-up, so that a cashier's data-entry mistake can be corrected without a second, undocumented, offsetting transaction.

#### Acceptance Criteria

1. WHERE a new endpoint `POST /api/v1/students/{student}/wallet/top-ups/{transaction}/void` exists, `{transaction}` SHALL identify a row in bavix's `transactions` table (the wallet ledger table backing `bavix/laravel-wallet`), not a row in any app-owned table.
2. WHEN the endpoint is called THEN the system SHALL resolve `{transaction}` scoped to `transactions.wallet_id = $student->wallet->id` AND `transactions.type = 'deposit'`, and SHALL respond `404 Not Found` if no such row exists (including when the transaction id belongs to a different student's wallet, or belongs to this student but is a `withdraw`-type row).
3. WHERE the deposit being voided originated from `WalletController::topUp` OR from `InlineReloadController::store` THE system SHALL treat both identically — one endpoint covers both origins, since both write `type = 'deposit'` rows to the same `transactions` table.
4. WHEN the request body is validated THEN `reason` SHALL be `required|string|max:500`, matching the `void_reason` validation already used by `TransactionController::void` and `PaymentController::void`. A missing or empty reason SHALL respond `422 Unprocessable Entity`.

---

### Requirement 2 — Idempotency: a transaction can only be voided once

**User Story:** As the system, I want a top-up that has already been voided to be rejected on a second void attempt, so that the wallet cannot be double-refunded or double-debited by a repeated or racing request.

#### Acceptance Criteria

1. WHEN a void request targets a `{transaction}` that already has a corresponding row in `wallet_topup_voids` (matched by `wallet_topup_voids.wallet_transaction_id = transactions.id`) THEN the system SHALL respond `422 Unprocessable Entity` with a message stating the top-up was already voided, and SHALL NOT perform any wallet or credit mutation.
2. WHEN the idempotency check and the void-creating transaction run THEN the check SHALL occur inside the same row-locked database transaction as the mutation (not as a separate unlocked pre-check), so two concurrent void requests for the same transaction cannot both pass the check before either writes.

---

### Requirement 3 — A staff member cannot void their own top-up

**User Story:** As the system, I want to block the person who performed a top-up from being the person who voids it, so that a single staff member cannot pocket cash, deposit it to make the drawer look correct, and then erase the deposit themselves.

#### Acceptance Criteria

1. WHEN resolving who performed the original top-up THEN the system SHALL check the original transaction's `meta` JSON for `performed_by` (the key `WalletController::topUp` writes) first, then `cashier_id` (the key `InlineReloadController::store` writes) if `performed_by` is absent — mirroring the exact fallback order already implemented in `LedgerEntryFormatter::performerId()`.
2. IF the resolved original performer's user id equals the id of the staff member making the void request THEN the system SHALL respond `403 Forbidden` with a message directing them to have another admin, manager, or supervisor perform the void, and SHALL NOT perform any mutation.
3. IF the original transaction's `meta` contains neither `performed_by` nor `cashier_id` (a legacy or malformed row) THEN the system SHALL treat the original performer as unknown and SHALL allow the void to proceed (the self-void check cannot block on data it does not have) — this edge case SHALL be covered by an explicit test rather than left to accidental behavior.

---

### Requirement 4 — Void authorization is tiered by role and transaction age

**User Story:** As the school's operations lead, I want a manager or supervisor to self-serve same-day corrections but require admin sign-off for older ones, so that stale top-ups can't be quietly rewritten while same-day mistakes don't stall on someone with a narrower schedule.

> **Amendment (post-implementation correction, approved by spec owner):** this requirement originally stated the route would be gated by `role:admin|manager`, "identical in form to `PaymentController::void`'s route gate." That claim was based on a wrong premise: the existing `/students/{student}/wallet/top-up` route (which the void route was to sit beside, per design.md Component 5) does **not** live in a dedicated `role:admin|manager` group — it lives in the broader `role:admin|manager|supervisor` "Enrollment & Students" group. This was caught by the implementer's own role-gate test during task 3.2 (a supervisor unexpectedly succeeded in voiding a top-up). Rather than pulling the void route into a narrower group than its sibling, the spec owner decided to keep it in the existing group and correct this requirement to match: **supervisors are allowed** through the role gate, same as the pre-existing `/wallet/top-up` route, and are subject to the same same-day restriction as managers. Only `cashier` is blocked by the role gate itself.

#### Acceptance Criteria

1. WHERE the route is registered THEN it SHALL be gated by `role:admin|manager|supervisor` middleware, matching the existing `/students/{student}/wallet/top-up` route's role tier exactly (both routes share the "Enrollment & Students" route group) — cashiers SHALL receive `403 Forbidden` from the role middleware before the controller executes.
2. WHEN the requesting user does not have the `admin` role (i.e. has `manager` or `supervisor`) AND the target transaction's `created_at` date is not equal to the current date in the application's configured timezone THEN the system SHALL respond `403 Forbidden` with a message stating the top-up is outside the same-day window and must be voided by an admin.
3. WHEN the requesting user has the `admin` role THEN the system SHALL NOT apply the same-day restriction — an admin may void a top-up of any age.
4. IF a user holds the `admin` role together with any other role (`manager` and/or `supervisor`) THEN the `admin` unrestricted rule SHALL apply (an admin-inclusive check, not a manager/supervisor-restrictive one).

---

### Requirement 5 — Insufficient wallet balance converts the shortfall to credit debt

**User Story:** As an admin, manager, or supervisor, I want a top-up voided even if part of it has already been spent, so that the correction isn't blocked indefinitely by ordinary spending that happened in between.

#### Acceptance Criteria

1. WHEN a void is processed THEN the system SHALL compute `voided_amount = min(original_amount, current_wallet_balance)` and `shortfall_amount = original_amount − voided_amount`, reading `current_wallet_balance` at the moment of the row-locked mutation (not an earlier, potentially stale read).
2. WHEN `voided_amount > 0` THEN the system SHALL withdraw `voided_amount` from the student's wallet via `$student->withdraw()` (bavix API — never a direct `wallets` table write, per `tech.md`'s Student Wallet standard).
3. WHEN `voided_amount` is `0` THEN the system SHALL skip the `withdraw()` call entirely rather than calling it with a zero amount.
4. WHEN `shortfall_amount > 0` THEN the system SHALL call `App\Services\CreditLedgerService::charge()` for `shortfall_amount`, recording it as a `credit_charged` ledger entry against the student — `CreditLedgerService` remains the sole writer of `credit_transactions` and `students.credit_balance`; no other code path in this feature SHALL write to either.
5. WHEN the shortfall is charged to credit THEN this charge SHALL be **exempt** from the `credit_limit` check that gates ordinary checkout borrowing (`config('sunbites.credit_limit')`, default 300) — the same precedent `CreditLedgerService::void()` already establishes for order-void credit reversals (see `CreditLedgerService.php:150-153`), because refusing the void over a limit check would leave the wallet balance permanently wrong, which is the exact "indefinite delay" this feature exists to avoid.
6. WHEN `shortfall_amount` is `0` (the full original amount was recoverable) THEN the system SHALL NOT call `CreditLedgerService::charge()` at all — no zero-amount credit entries.
7. WHERE `CreditLedgerService::charge()` already sends a debounced `CreditChargedNotification` to every linked parent whenever it is called (existing behavior, unchanged by this feature — see `CreditLedgerService::notifyParentsOfCharge()`), a void with a shortfall SHALL trigger that same parent notification as an inherited side effect of reusing `charge()` unmodified. This is intentional, not an oversight to suppress: a parent whose child's outstanding credit increased because of a void deserves the same notification they would get from any other credit charge, and the existing per-parent-per-student-per-day debounce already prevents duplicate noise if the student also had an unrelated credit charge that same day.

---

### Requirement 6 — Every void is recorded in a dedicated, immutable audit ledger

**User Story:** As an admin reviewing wallet activity, I want every void to leave a permanent, queryable record separate from the original deposit, so that nothing about the correction can be silently edited or deleted after the fact.

#### Acceptance Criteria

1. WHEN a void completes THEN the system SHALL create exactly one row in a new `wallet_topup_voids` table recording: `student_id`, `branch_id` (a snapshot of the student's branch at void time), `wallet_transaction_id` (the original deposit), `refund_wallet_transaction_id` (the new withdraw transaction id, nullable — null only when `voided_amount` is 0), `credit_transaction_id` (nullable — set only when `shortfall_amount > 0`), `original_amount`, `voided_amount`, `shortfall_amount`, `void_reason`, `voided_by`, and `created_at`.
2. WHERE `wallet_topup_voids` is defined THE table SHALL have no `updated_at` column and no application code path that updates an existing row — it is written once, at creation, and never mutated afterward. This mirrors `CreditTransaction`'s `$timestamps = false` convention.
3. WHEN the original deposit transaction's `meta` JSON is available for annotation THEN the system SHALL add `voided_at` (ISO 8601 timestamp) and `wallet_topup_void_id` keys to it via a non-destructive update (the transaction's `amount`, `type`, and existing meta keys SHALL NOT be altered) — this makes the original row self-evident on direct inspection, while `wallet_topup_voids` remains the canonical source every query joins against.
4. WHERE reporting or ledger queries need to know whether a deposit has been voided THE system SHALL answer that question via a join against `wallet_topup_voids`, never by parsing the `meta` JSON with string matching — `WalletHistoryController::topups()`'s existing `LIKE` matching against JSON is a known-fragile pattern this feature SHALL NOT extend.

---

### Requirement 7 — The void appears correctly in the unified student ledger

**User Story:** As staff viewing a student's wallet activity, I want a voided top-up and its reversal to show up as distinct, correctly labeled entries in the same ledger used for every other wallet and credit event, so that the history reads as one coherent timeline instead of two disconnected views.

#### Acceptance Criteria

1. WHERE `App\Enums\LedgerEntryType` is defined THE system SHALL add a new case `TopupVoided = 'topup_voided'` with `label() => 'Top-up Voided'` and `direction() => 'debit'`.
2. WHEN `StudentLedgerQuery::walletLeg()` builds its subquery THEN it SHALL LEFT JOIN `wallet_topup_voids` twice — once aliased to match `wallet_transaction_id = transactions.id` (identifying the original, now-voided deposit) and once aliased to match `refund_wallet_transaction_id = transactions.id` (identifying the reversal row) — and SHALL compute `entry_type` as `'topup_voided'` when the transaction id matches a `refund_wallet_transaction_id`, otherwise `transactions.type` unchanged.
3. WHEN `StudentLedgerQuery::walletLeg()` is updated per criterion 2 THEN `StudentLedgerQuery::creditLeg()` SHALL be updated in the same change to select matching placeholder columns (`voided`, `wallet_transaction_id`) so the `UNION ALL` between the two legs continues to have identical column counts and order — a mismatch here breaks the union at the database level, not at the application level, and SHALL be caught by a test that filters `entry_type=all` and asserts no SQL error.
4. WHEN a deposit row has a matching `wallet_topup_voids.wallet_transaction_id` THEN its formatted ledger entry SHALL include `voided: true`; every other entry (including the `topup_voided` reversal row itself and all credit-ledger rows) SHALL include `voided: false`.
5. WHEN a deposit-type ledger entry is formatted THEN it SHALL include a `wallet_transaction_id` field set to the numeric bavix transaction id (not the composite `row_id` string like `"wallet-123"`) so a client can target the void endpoint without parsing `row_id`; every non-deposit entry SHALL have `wallet_transaction_id: null`.
6. WHEN the reversal (`topup_voided`) withdraw transaction is created THEN its bavix `meta` SHALL include `note` (set to the void reason) and `performed_by` (set to the voiding staff member's user id), so `LedgerEntryFormatter::resolveNote()` and `::performerId()` — both unchanged — surface the void reason and voider name using their existing, generic wallet-leg logic with no additional formatter code.
7. WHEN `entry_type=topup` is requested as a ledger filter THEN `LedgerEntryType::forFilter('topup')` SHALL return `[Deposit, TopupVoided]` so a voided top-up and its reversal both appear under the "Top-up" filter rather than the reversal being invisible or miscategorized as a purchase.

---

### Requirement 8 — Every void is captured in the activity log

**User Story:** As an admin auditing staff actions, I want every top-up void logged the same way every other financial mutation in this system is logged, so that void activity shows up wherever activity logs are already reviewed.

#### Acceptance Criteria

1. WHEN a void completes successfully THEN the system SHALL write an activity log entry on the `'wallet'` log channel (matching `WalletController::topUp`'s existing `activity('wallet')` call) with event name `wallet.topup_voided`, `performedOn($student)`, `causedBy($request->user())`, and properties: `original_amount`, `voided_amount`, `shortfall_amount`, `void_reason`, `original_performer_id` (resolved per Requirement 3.1, nullable per 3.3), and `voided_by`.
2. WHEN the void is blocked by any of the checks in Requirements 1–4 (not found, already voided, self-void, outside window, missing reason) THEN the system SHALL NOT write an activity log entry — only completed mutations are logged, matching the existing pattern where validation and authorization failures across this codebase do not produce activity rows.

---

### Requirement 9 — Reports reflect voided top-ups accurately

**User Story:** As a manager reconciling the day's wallet activity, I want voided top-ups clearly marked and excluded from top-up totals, so that a corrected mistake doesn't inflate the reported numbers.

#### Acceptance Criteria

1. WHEN `WalletHistoryController::topups()` returns deposit rows for a student THEN each row SHALL include a `voided` boolean (via a join against `wallet_topup_voids` on `wallet_transaction_id`, consistent with Requirement 6.4's prohibition on JSON parsing), and any row voided SHALL still appear in the list (never silently removed) so staff retain visibility into the correction.
2. WHERE `WalletReportController` computes aggregate deposit totals (the `total_credits` column in its branch-level `$walletSummary` query, and the `total_credited` column in its shared `buildTxStats()` helper — the same two SQL blocks named precisely in design.md) THE system SHALL subtract exactly `voided_amount` — never the full `original_amount`, and never `shortfall_amount` — for every voided deposit from those totals. A fully-recovered void (`voided_amount = original_amount`) removes that deposit from the total entirely, because none of it remains in the wallet. A partially-recovered void leaves `original_amount − voided_amount` (equivalently, `shortfall_amount`) counted in the total, because that portion was genuinely spent on real purchases before the void occurred and correctly remains "deposited and used" — it SHALL NOT be subtracted a second time merely because the unrecovered remainder also moved to the credit ledger as a separate receivable. Report queries SHALL be updated to subtract voided amounts, not to filter out voided rows wholesale, since the underlying `transactions` row still needs to reconcile against the wallet's actual running balance.
3. WHEN a void's reversal withdraw transaction exists (Requirement 6.1's `refund_wallet_transaction_id`) THEN `WalletReportController`'s purchase/spending aggregates (the `total_debits` column in `$walletSummary`, and `total_debited` in `buildTxStats()`) SHALL exclude it. A reversal withdrawal is a correction, not a purchase, and counting it as spending would inflate reported spending by exactly the amount this feature exists to correct away. This exclusion SHALL be implemented by joining `wallet_topup_voids` on `refund_wallet_transaction_id = transactions.id` — a different join condition than criterion 2's, which joins on `wallet_transaction_id` instead. Both joins are required; one does not substitute for the other, since they identify different rows (the original deposit vs. its reversal).

---

### Requirement 10 — Frontend: staff can void a top-up from the student wallet ledger

**User Story:** As an admin, manager, or supervisor using the POS app, I want a "Void" action directly on a top-up row in the student's wallet ledger, so that correcting a mistake doesn't require leaving the screen I'm already looking at.

#### Acceptance Criteria

1. WHERE the unified ledger table in `WalletTab` (`~/sunbites-pos/app/(kitchen)/students/[id]/_components/wallet-tab.tsx`) renders a row with `entry_type === "deposit"` AND `voided === false` THE system SHALL show a "Void" action for that row.
2. WHEN the current user's `roles` (from `useAuthStore`) includes none of `"admin"`, `"manager"`, or `"supervisor"` THEN the "Void" action SHALL NOT be rendered for any row — extending (not mirroring exactly, per Requirement 4's amendment) the `user?.roles.includes("admin") === true || user?.roles.includes("manager") === true` pattern already used for `canSettleCredit-equivalent` gating in `page.tsx`, with `|| user?.roles.includes("supervisor") === true` added to match this feature's broader, corrected role tier.
3. WHEN the current user's `roles` includes `"manager"` or `"supervisor"` and NOT `"admin"` AND the row's `date` is not today THEN the "Void" action SHALL be disabled (not hidden) with a tooltip or adjacent text explaining that only an admin can void a top-up from a previous day — this is a client-side convenience only; Requirement 4 (server-side enforcement) is authoritative regardless of what the client renders or fails to render.
4. WHEN "Void" is clicked THEN a confirmation dialog SHALL open requiring a non-empty reason (mirroring `SettleCreditDialog`'s Zod-validated form pattern: local component, `useMutation`, `z.object` schema, field-level error display, submit disabled while `mutation.isPending`) before the request can be submitted.
5. WHEN the void dialog is open THEN it SHALL display the top-up's original amount and the student's current wallet balance, and IF the current wallet balance is less than the original amount THEN it SHALL show a clear warning that some or all of the amount has already been spent and the unrecoverable portion will be added to the student's outstanding credit — so staff are not surprised by a resulting credit charge.
6. WHEN the void mutation succeeds THEN the dialog SHALL close and the client SHALL invalidate the `["student", studentId]` and `["student-ledger", studentId]` TanStack Query cache keys, matching `SettleCreditDialog`'s existing `onSuccess` invalidation pattern, so the ledger and wallet/credit balance figures refresh without a manual reload.
7. WHEN the void mutation fails THEN the dialog SHALL remain open and display the server's error message (matching the `mutation.isError` display pattern already used in `SettleCreditDialog`), covering at minimum: 404 (transaction not found), 422 (already voided / validation), and 403 (self-void or outside window).

---

### Requirement 11 — Frontend: the ledger visually distinguishes voided top-ups and their reversal

**User Story:** As staff scanning a student's wallet history, I want a voided top-up and the resulting reversal (and any resulting credit debt) to be visually obvious at a glance, so that I don't mistake a corrected mistake for two unrelated events.

#### Acceptance Criteria

1. WHEN a ledger row has `voided: true` THEN it SHALL render with a visual "Voided" indicator (e.g. a badge and/or strikethrough on the amount) distinguishing it from an active top-up, and SHALL NOT show a "Void" action.
2. WHEN a ledger row has `entry_type === "topup_voided"` THEN it SHALL render with its own distinct label (`entry_label` from the API, "Top-up Voided") and SHALL visually read as a debit (matching the existing red/minus styling already applied to `direction === "debit"` rows), consistent with how `entry_type === "credit_voided"` already renders today.
3. WHEN a void results in `shortfall_amount > 0` THEN the resulting `credit_charged` ledger entry (written by `CreditLedgerService::charge()`) SHALL appear in the ledger exactly as any other credit charge does today — no new UI is required for this row since Spec 14 already renders `credit_charged` entries; this criterion exists to confirm no additional frontend work is needed here, not to introduce new rendering.

---

## Cross-Cutting Requirements

**Security / Authorization:** Every acceptance criterion above that specifies a role or ownership check (Requirements 3, 4, 10.2) is enforced server-side; the frontend gating in Requirement 10 is a UX convenience, never the authorization boundary. The route MUST use `auth:sanctum` + `role:admin|manager|supervisor` middleware, matching the existing `/students/{student}/wallet/top-up` route it sits beside — a deliberate broadening from the `admin|manager`-only gate used by most other financial-mutation routes (e.g. `PaymentController::void`), approved by the spec owner after the discrepancy was caught during task 3.2 implementation (see Requirement 4's amendment note).

**Data Isolation (branch scoping):** `wallet_topup_voids.branch_id` is a snapshot column, not a `HasBranch`-scoped relation — this matches `CreditTransaction`'s documented rationale (the global `BranchScope` would break the ledger query in contexts with no active branch bound, such as the parent portal). Report queries that need branch filtering MUST filter explicitly on this snapshot column, the same way `WalletReportController` and `CreditReportController` already do for their respective tables. The void endpoint itself operates on `{student}`, which is already branch-scoped via `HasBranch` on `Student`, so an admin/manager cannot target a student outside their active branch through this endpoint.

**Performance:** The two additional `LEFT JOIN`s in `StudentLedgerQuery::walletLeg()` (Requirement 7.2) MUST be indexed — `wallet_topup_voids` needs indexes on both `wallet_transaction_id` and `refund_wallet_transaction_id` (see design.md for the exact migration). Without these indexes, every ledger page load for every student degrades as `wallet_topup_voids` grows, not just for students who have ever had a void.

**Error Handling:** All error responses follow the existing shared contract in `structure.md` (`{"message": "...", "errors": {...}}` for validation; a bare `{"message": "..."}` for 403/404/422 business-rule rejections) — no new error envelope shape is introduced.

**Observability:** Requirement 8 covers activity logging. No new logging channel, metric, or alert is introduced by this feature; the daily wallet report (Requirement 9) is the mechanism by which unusual void volume would surface to a manager reviewing operations, not a new automated alert.

---

## Out of Scope

- **Un-voiding a void.** A `wallet_topup_voids` row, once created, is permanent. If a void was itself performed in error, the correction is a brand-new top-up (with its own audit trail), not a reversal of the reversal. This keeps the void mechanism itself simple and terminal, avoiding an infinitely-recursive "void the void" feature.
- **Voiding purchases, checkout wallet deductions, or credit settlements.** Those already have their own reversal mechanisms (`TransactionController::void` for orders, `CreditLedgerService::void()` for credit tied to a voided order). This feature only touches `type = 'deposit'` transactions.
- **Payment-method-specific handling (cash vs. GCash vs. bank transfer).** The void treats all top-up payment methods identically. Differentiating risk controls by payment method (e.g. requiring extra approval for cash-only voids, since GCash/bank transfer leave an external paper trail cash doesn't) is a plausible future enhancement, not part of this spec.
- **Rate-limiting or anomaly detection on void frequency** (e.g. flagging a staff member who voids unusually often). Requirement 9's reporting makes this reviewable by a human; an automated control is future scope.
- **Parent portal changes.** Parents can already see wallet activity via their own `StudentLedgerController` (Portal). Because that controller reuses the same `StudentLedgerQuery` and `LedgerEntryFormatter` this spec modifies, the voided/reversal entries will appear to parents automatically — no separate portal work is required, and none is planned. Whether the `void_reason` note should be hidden from parents (the way `CreditWaived` notes already are, per `LedgerEntryFormatter`'s `$includeStaffOnlyNotes` parameter) is addressed in design.md as an explicit decision, not left as portal-side scope.
- **Changing `WalletController::topUp` or `InlineReloadController::store` behavior**, beyond nothing — this feature adds a new endpoint and does not modify either existing top-up code path's request/response contract.
