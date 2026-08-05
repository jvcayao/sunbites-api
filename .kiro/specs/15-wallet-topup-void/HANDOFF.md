# Spec 15 — Wallet Top-up Void · Implementation Handoff

**Read this entire file before touching a single line of code.** It exists to stop you guessing. Every decision that could reasonably go two ways has already been made, verified, and written down — several of them the hard way, by writing the wrong version first and catching it in review. Your job is to execute, not to redesign.

---

## 1. Non-negotiables

1. **One task at a time.** Take a single sub-task from [tasks.md](./tasks.md), finish it, verify it, stop. Wait for review. Never chain two tasks without approval.
2. **Never invent a decision.** If the spec does not tell you something you need, **stop and ask.** Do not pick "the reasonable option". Every ambiguity you resolve silently becomes a defect nobody reviewed.
3. **Never contradict the spec because the code looks different.** Where existing code and this spec disagree, the spec wins — it was written against the actual code, verified line by line. If the disagreement looks like a spec error, stop and ask; do not silently defer to what you see in the file.
4. **Do not expand scope.** The [Out of Scope](./requirements.md#out-of-scope) section is binding. Un-voiding a void, payment-method-specific rules, rate limiting, Parent Portal code changes — none of that is this feature. Finding adjacent problems is useful; fixing them here is not. Report them instead.
5. **Do not claim completion without running the tests.** Paste the actual output. "Should pass" is not a result.
6. **Do not simplify away a defensive check just because you can't immediately see how it fires.** Section 5 below lists thirteen places where the "obviously equivalent, simpler" version is actually wrong. Read it before you refactor anything the spec wrote out in full.

---

## 2. Required reading, in this order

| Order | File | Why |
|---|---|---|
| 1 | `.kiro/steering/product.md` | Users, roles, scope boundaries, business constraints |
| 2 | `.kiro/steering/tech.md` | Stack, patterns, PHP and testing standards |
| 3 | `.kiro/steering/structure.md` | Directory layout, naming, shared contracts, feature registry |
| 4 | `CLAUDE.md` (repo root) | Project rules; Sail command requirements |
| 5 | `.claude/rules/testing.md` | Testing standards for all three repos |
| 6 | `.claude/rules/frontend/frontend-standards.md` | Next.js rules — read before task 6 |
| 7 | [requirements.md](./requirements.md) | What to build; the EARS criteria you must satisfy |
| 8 | [design.md](./design.md) | How to build it; every interface, every query, written out in full with rationale |
| 9 | [tasks.md](./tasks.md) | The ordered plan, with test requirements per task and a requirement-coverage table |

Steering files declare `inclusion: always` — they apply to every task, not just the first. `design.md` is 845 lines because it writes out full PHP methods and full SQL, not pseudocode — match what it wrote, do not re-derive your own version from the prose summary alone.

---

## 3. Required tooling

### Backend tasks (1–5) — Laravel Boost is mandatory

Boost is an MCP server tuned for this application. Prefer its tools over shell equivalents.

| Before you… | Use |
|---|---|
| Write or change any Laravel code | **`search-docs`** — always, first. Version-correct docs for the installed packages (Laravel 13 / PHP 8.5). Do not answer from memory. |
| Touch the migration or any model | **`database-schema`** — inspect the real `transactions`, `credit_transactions`, `students` tables before assuming their shape. |
| Check data shape or counts while testing | **`database-query`** — read-only queries instead of raw SQL in tinker. |
| Check a route's middleware | `vendor/bin/sail artisan route:list --path=api --except-vendor` |

**Also invoke the `laravel-best-practices` skill** for every backend task that writes or modifies PHP — it covers N+1s, query performance, authorization, validation, and error handling, all of which this feature touches (locking, joins, credit-limit exemption logic).

Use `search-docs` with multiple broad topical queries. Example for task 3.2: `["database transactions locking", "eloquent lockForUpdate", "form request validation"]`. Do not put package names in the query — Boost already knows the installed set.

### Frontend task (6)

No Boost. Follow `.claude/rules/frontend/frontend-standards.md` and `.claude/rules/testing.md`. `~/sunbites-pos` is **outside Docker** — run `npm` directly in the repo directory, never Sail. Consider using the `react-component-expert` agent for the dialog/component work in task 6.2–6.3 — it understands this exact codebase's Server/Client split, TanStack Query, and Zod 4 conventions.

### After backend tasks 1–5 are complete, before starting task 6

Run a code-quality pass on the whole backend surface this feature touched:

1. Review everything added or changed in `~/sunbites-api` across tasks 1–5.
2. **Use the `laravel-simplifier` agent** on that changed code — it refines PHP for clarity and consistency **while preserving all functionality**.
3. Re-run the full backend suite afterward. The simplifier must not change behavior — if any test moves from pass to fail, its change is wrong; revert that specific change, do not adjust the test to match.
4. Report what it changed and what you accepted or rejected, then stop for review before starting task 6.

---

## 4. Settled facts — verified against the running environment, do not re-derive

These were checked directly, not assumed. Treat them as ground truth; contradicting them without new evidence is a defect.

| Fact | Evidence |
|---|---|
| `bavix` `deposit()` and `withdraw()` both return a `Transaction` model (has `->id`) and accept `?array $meta` as the second argument | `vendor/bavix/laravel-wallet/src/Traits/HasWallet.php:66` and `:307` — signatures read directly |
| `balanceFloat` is `@property string`; `balanceFloatNum` is `@property float` — **not interchangeable by name alone** | `vendor/bavix/laravel-wallet/src/Traits/HasWalletFloat.php:24-25`. `WalletController::topUp()`, the existing sibling method `voidTopUp()` is added next to, already uses `balanceFloatNum` |
| `User` has Spatie's `HasRoles` trait — `hasRole('admin')` is valid | `app/Models/User.php:16,21` |
| `CreditLedgerService::charge()` has **exactly one** existing caller in the entire application | Full-codebase grep for `->charge(` — only `CheckoutController.php:173` |
| Spec 14 (Credit Settlement) is fully merged on both backend and frontend, despite `structure.md` previously saying "Not started" — **already corrected** in this session | `git log` shows `2e502c5 feat: multi-channel credit settlement (spec 14)`; `CreditLedgerService.php` has all 5 documented methods implemented; `~/sunbites-pos` has `settle-credit-dialog.tsx` / `waive-credit-dialog.tsx` already built and tested |
| `transactions.amount` is signed minor units (centavos): deposits positive, withdrawals negative | Existing comment in `app/Services/StudentLedgerQuery.php`; `ABS(amount)` pattern throughout `WalletReportController.php` |
| `WalletReportController` has **exactly two** SQL blocks computing deposit/withdraw totals, not more, not fewer | `$walletSummary` inline in `index()` (lines 34-43, columns `total_credits`/`total_debits`) and the shared `buildTxStats()` (lines 145-168, columns `total_credited`/`total_debited`, called by both `index()` and `export()`) — full file read |
| `PaymentController.php` imports `use Carbon\Carbon;`, not `Illuminate\Support\Carbon` | Read directly — match this import in `WalletController.php` |
| Backend test baseline, captured moments before this handoff was written | **904 tests, 904 passed, 2464 assertions, exit 0.** Fully green. Re-verify this yourself before task 1.1 — time may have passed. |
| Both `~/sunbites-api` and `~/sunbites-pos` are **already checked out on `feat/wallet-topup-reversal`** | Confirmed via `git status` in both repos at handoff time. `~/sunbites-pos` has zero commits ahead of `main` — a clean starting point for task 6. `~/sunbites-api`'s only uncommitted changes right now are this spec's own planning files (`.kiro/specs/15-wallet-topup-void/`, `.kiro/steering/structure.md`) |
| Apps `~/sunbites-pos` / `~/sunbites-portal` run **outside Docker** | `npm` directly in the repo directory; Sail applies only to `~/sunbites-api` |

---

## 5. The thirteen traps

Each of these is a place where the obvious move is wrong. Several were caught only by writing the naive version first and then checking it against the requirement text — they are listed here specifically so you don't have to rediscover them the same way.

1. **Do not expose `wallet_transaction_id` unconditionally on every wallet-leg ledger row.** Condition it on `transactions.type = 'deposit'` in the SQL (design.md Component 8). Otherwise ordinary purchase rows and the `topup_voided` reversal row itself get a non-null id that wrongly implies they're voidable through this endpoint.
2. **`WalletReportController` needs four corrected `CASE` expressions across two query blocks, not two.** Both `$walletSummary` and `buildTxStats()` need a deposit-side fix (`total_credits`/`total_credited`) **and** a withdrawal-side fix (`total_debits`/`total_debited`). The deposit-side fix is the one that's obvious; the withdrawal-side fix — excluding the void's own reversal from "amount spent" — is the one that gets missed if you only think about deposits.
3. **When correcting deposit totals, subtract exactly `voided_amount`.** Never `original_amount` (over-subtracts — the portion that was genuinely spent before the void stays real spending), never `shortfall_amount` (that's the opposite of what needs subtracting).
4. **`StudentLedgerQuery::walletLeg()` and `::creditLeg()` must be edited together, in the same change.** They combine via `UNION ALL`, which is positional. A column-count mismatch breaks at the database level with an opaque error, not a PHP-level one you can catch by reading the diff.
5. **Only the "already voided?" check needs re-verification inside the row-locked transaction.** Self-void and the same-day window check read immutable data (who performed a past deposit, when it happened) — there is nothing for a second concurrent request to race against on those two checks. Adding a redundant re-check for them inside the lock is dead code, not extra safety — don't add it, and don't be surprised it's absent.
6. **`balanceFloat` (string) and `balanceFloatNum` (float) are genuinely different declared types**, not two names for the same accessor. Use `balanceFloatNum` throughout `voidTopUp()`, matching `topUp()`'s existing convention in the same controller file. (Other files in this codebase use `balanceFloat` instead and cast it — that's a pre-existing inconsistency across files, not something to fix here or to copy into this feature's own new code.)
7. **The self-void check must read both `performed_by` and `cashier_id` from the original transaction's meta**, in that fallback order. `WalletController::topUp()` writes `performed_by`; `InlineReloadController::store()` writes `cashier_id` — same concept, two different keys, for historical reasons this spec doesn't change. `LedgerEntryFormatter::performerId()` already implements this exact fallback — copy its order precisely, don't invent your own.
8. **`CreditLedgerService::charge()`'s signature rename and `CheckoutController`'s call-site update must ship in the same commit** (task 2.1). Splitting them across two changes doesn't crash anything — PHP doesn't enforce parameter names — it silently corrupts the `notes` text on every future checkout credit charge (`"Credit used for 12345."` instead of `"Credit used for order 12345."`). Run `CreditLedgerServiceTest.php` after this task specifically to catch it if it happens.
9. **Do not add a second parent notification for the shortfall-to-credit path.** `CreditLedgerService::charge()` already sends `CreditChargedNotification` internally, debounced. This is an intentional inherited side effect (Requirement 5.7) — write the test that proves it fires, don't write new code to make it fire.
10. **Do not build a `WalletTopupVoidPolicy` class.** This feature's target is a vendor-owned bavix `Transaction` row, not an app Eloquent model — follow `PaymentController::void`'s precedent (inline `abort_if()` in the controller), not `TransactionController::void`'s (`OrderPolicy` + `$this->authorize()`). The design document explains why in its "where enforcement logic lives" callout — read it before reaching for a Policy out of habit.
11. **`VoidTopUpDialog` is a new, separate component file** — a structural sibling of `settle-credit-dialog.tsx` and `waive-credit-dialog.tsx`. Do not follow the original top-up modal's precedent of living inline in `page.tsx` — that inline placement is itself flagged as a deliberate, narrow exception in spec 14's own `tasks.md` (task 7.2's "PARTIAL" note), not a pattern to extend.
12. **`wallet_topup_voids.wallet_transaction_id` must be a `UNIQUE` column**, not merely indexed. It's the database-level backstop against double-voiding, independent of and in addition to the application-level re-check inside the lock (trap 5). Both exist on purpose; neither substitutes for the other.
13. **Frontend role-gating and the same-day disabled-button hint are UX only.** The route's `role:admin|manager|supervisor` middleware and the controller's self-void/time-window checks are the actual authorization boundary. Do not treat "the button renders" or "the button is enabled" as evidence the request will succeed when writing backend tests — test the endpoint directly, independent of what the frontend would have shown.
    - **Post-implementation correction:** this trap (and Requirement 4.1 as originally written) claimed the route sits in a dedicated `role:admin|manager` group, "identical to `PaymentController::void`." That was wrong — it actually sits in the pre-existing `role:admin|manager|supervisor` "Enrollment & Students" group alongside `/wallet/top-up`. Caught by the implementer's own role-gate test during task 3.2; the spec owner chose to keep the route where it naturally sits rather than carve out a narrower group, and corrected requirements.md/design.md/tasks.md accordingly. `supervisor` is allowed through the gate and shares `manager`'s same-day restriction; only `cashier` is blocked.

---

## 6. Per-task workflow

For every sub-task, in order:

1. **Re-read** the task in [tasks.md](./tasks.md) and the requirement IDs it cites in [requirements.md](./requirements.md).
2. **Read the relevant design section** in [design.md](./design.md) in full. Interfaces, SQL, and method bodies are specified — match them exactly, including the inline comments explaining *why*, which tell you what not to "simplify."
3. **Read the actual files you're about to change**, in full where they're small (`WalletController.php`, `CreditLedgerService.php`, the enum). Never edit from memory of a grep result — re-read the current state first.
4. **Backend only:** `search-docs` for the patterns involved; invoke `laravel-best-practices`.
5. **Write the code** — minimal, matching design.md's interfaces exactly. No speculative features, no premature abstraction, no unrequested scaffolding. Minimal never means fewer tests, weaker validation, or a shortened enforcement sequence — those are the requirement, not gold-plating.
6. **Write every test scenario named in the task and cross-referenced in design.md's Testing Strategy.** Each one is required, not a suggestion. Arrange-Act-Assert. Test behavior, not implementation.
7. **Format:** `vendor/bin/sail bin pint --dirty --format agent` for PHP.
8. **Run tests** (section 7 below).
9. **Verify the gate:** new failures block completion — fix them. Pre-existing failures never block, but the baseline is fully green (904/904 at handoff time), so *any* failure after your change is yours to fix.
10. **Tick the checkbox** `[ ]` → `[x]` in tasks.md. It must reflect reality.
11. **If you deviated from design.md** — different approach, changed contract, new component — propose the design.md amendment as part of completion. A stale spec silently corrupts every task built on it afterward, including the requirement-coverage table.
12. **Stop.** Report what you did, what you ran, what passed. Wait for review.

---

## 7. Commands

### Backend — `~/sunbites-api` (all through Sail)

```bash
# Single test file — use this while developing
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/WalletTopUpVoidTest.php

# Single test by name
vendor/bin/sail artisan test --compact --filter=test_self_void_is_blocked

# Full suite — before declaring any task done
vendor/bin/sail artisan test --compact

# Format after every PHP change (required)
vendor/bin/sail bin pint --dirty --format agent

# Migration
vendor/bin/sail artisan migrate

# Generate a test file if one doesn't exist yet
vendor/bin/sail artisan make:test --phpunit Kitchen/WalletTopUpVoidTest

# Routes
vendor/bin/sail artisan route:list --path=api --except-vendor
```

### Frontend — `~/sunbites-pos`

```bash
npm test                      # jest
npm test -- path/to/file      # single file, e.g. void-topup-dialog.test.tsx
npm run type-check            # tsc --noEmit
npm run lint
npm run quality:validate      # type-check + lint — run before finishing task 6
npm run test:coverage         # this app targets 80% branches/functions/lines/statements
```

Never use Sail for the frontend. Never use `npm` for the API.

---

## 8. Git

Both `~/sunbites-api` and `~/sunbites-pos` are already on branch **`feat/wallet-topup-reversal`** — confirmed at handoff time. Stay on it. Do not create new branches, merge, or push unless asked.

`~/sunbites-api` currently has two uncommitted changes: the new `.kiro/specs/15-wallet-topup-void/` directory (this spec) and the one-line `.kiro/steering/structure.md` fix (Spec 14's feature-registry row, corrected during planning). These are planning artifacts, not implementation — commit them at the start of task 1 if they aren't already committed, so implementation work has a clean baseline to diff against.

`~/sunbites-pos` is clean with zero commits ahead of `main` — nothing to be careful around there.

Commit only when asked. When asked, end commit messages with:

```
Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
```

---

## 9. Release coupling — read before task 2.1

Task 2.1 renames `CreditLedgerService::charge()`'s third parameter. There is exactly one other place that must change in the same commit:

| File | What must change | Task |
|---|---|---|
| `app/Services/CreditLedgerService.php` | `charge()`'s 3rd param: `string $receiptNumber` → `string $description`; notes line updated to `"Credit used for {$description}."` | 2.1 |
| `app/Http/Controllers/Kitchen/CheckoutController.php:173` | Call site updated to pass `"order {$receiptNumber}"` so existing output is byte-for-byte unchanged | 2.1 |

Both are in the same task already — this section exists only to make sure you don't complete one half, mark 2.1 done, and move on. Run `CreditLedgerServiceTest.php` specifically after this task; its existing string assertions are the regression guard (trap 8).

---

## 10. When you are blocked

Stop and ask. Specifically, ask rather than decide when:

- The spec does not cover a case you've hit.
- Existing code contradicts the spec in a way that looks like a spec error.
- **Task 4.2 or task 5.2 turns out bigger than described** — these are the two flagged as highest-risk. Task 4.2 rewrites a shared, heavily-consumed query (`StudentLedgerQuery`) with a strict positional-column contract; task 5.2 requires four independent `CASE` corrections across two query blocks in `WalletReportController`. If the SQL doesn't behave as design.md describes on the first attempt, don't iterate by trial and error — re-read design.md's Component 8 or Component 11 in full and check your query against it line by line before guessing at a fix.
- A test fails and the fix would mean changing the test's expectation rather than the code.
- You're about to touch a file no task mentions.
- You want to install a package, add a base directory, or change a dependency.

When you ask, state: what you were doing, what you found, the options you see, and which you'd pick and why. Do not just ask "what should I do".

---

## 11. Definition of done, per task

- [ ] Code matches the interfaces in design.md exactly
- [ ] Every test scenario named in the task (and cross-referenced in design.md's Testing Strategy) is written and passing
- [ ] `pint --dirty` run (backend tasks) / `quality:validate` run (task 6)
- [ ] Full relevant suite run, output pasted, zero new failures
- [ ] Checkbox ticked in tasks.md
- [ ] Any design deviation proposed as a design.md amendment
- [ ] Stopped and reported — did not start the next task

---

## 12. Start here

**Task 1.1** — the `wallet_topup_voids` migration, plus its `down()` verification. Purely additive, nothing else depends on it yet except everything downstream reads its schema, so get it exactly right against design.md's Data Models section before moving to 1.2.

Before writing code: read the steering files, read requirements 6 and its Cross-Cutting Requirements section, read design.md's "Data Models" section in full (it explains the rationale for every column choice — `unique()` on `wallet_transaction_id`, `nullOnDelete()` vs `cascadeOnDelete()`, why `void_reason` is `string(500)` not `text`), then `search-docs` for migration/schema patterns and invoke `laravel-best-practices`.
