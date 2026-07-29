# Spec 14 — Credit Settlement · Implementation Handoff

**Read this entire file before touching a single line of code.** It exists to stop you guessing. Every decision that could reasonably go two ways has already been made, verified, and written down. Your job is to execute, not to redesign.

---

## 1. Non-negotiables

1. **One task at a time.** Take a single sub-task from [tasks.md](./tasks.md), finish it, verify it, stop. Wait for review. Never chain two tasks without approval.
2. **Never invent a decision.** If the spec does not tell you something you need, **stop and ask.** Do not pick "the reasonable option". Every ambiguity you resolve silently becomes a defect nobody reviewed.
3. **Never contradict the spec because the code looks different.** Where existing code and this spec disagree, the spec wins — it was written against the code deliberately. If the disagreement looks like a spec error, stop and ask.
4. **Do not expand scope.** The [Out of Scope](./requirements.md) section is binding. Finding adjacent problems is useful; fixing them in this feature is not. Report them.
5. **Do not claim completion without running the tests.** Paste the actual output. "Should pass" is not a result.

---

## 2. Required reading, in this order

| Order | File | Why |
|---|---|---|
| 1 | `.kiro/steering/product.md` | Users, roles, scope boundaries, business constraints |
| 2 | `.kiro/steering/tech.md` | Stack, patterns, PHP and testing standards |
| 3 | `.kiro/steering/structure.md` | Directory layout, naming, shared contracts, feature registry |
| 4 | `CLAUDE.md` (repo root) | Project rules; Sail command requirements |
| 5 | `.claude/rules/testing.md` | Testing standards for all three repos |
| 6 | `.claude/rules/frontend/frontend-standards.md` | Next.js rules — read before any frontend task |
| 7 | [requirements.md](./requirements.md) | What to build; the EARS criteria you must satisfy |
| 8 | [design.md](./design.md) | How to build it; every interface and rationale |
| 9 | [tasks.md](./tasks.md) | The ordered plan with test requirements per task |

Steering files declare `inclusion: always` — they apply to every task, not just the first.

---

## 3. Required tooling

### Backend tasks (1–6) — Laravel Boost is mandatory

Boost is an MCP server tuned for this application. Prefer its tools over shell equivalents.

| Before you… | Use |
|---|---|
| Write or change any Laravel code | **`search-docs`** — always, first. It returns version-correct docs for the installed packages. Do not answer Laravel questions from memory; this project is Laravel 13 / PHP 8.5 and your recollection may predate it |
| Write a migration or touch a model | **`database-schema`** — inspect the real table before assuming its shape |
| Check data shape or counts | **`database-query`** — read-only queries instead of raw SQL in tinker |
| Look up a config value | `vendor/bin/sail artisan config:show <key>` or read `config/` directly |
| Check a route's middleware | `vendor/bin/sail artisan route:list --path=api --except-vendor` |

**Also invoke the `laravel-best-practices` skill** for every backend task that writes or modifies PHP. It covers N+1 and query performance, caching, authorization, validation, error handling, and queue configuration — all of which this feature touches.

Use `search-docs` with multiple broad topical queries, not one narrow one. Example for task 2.3: `["wallet transactions", "database transactions locking", "lockForUpdate"]`. Do **not** put package names in the query — Boost already knows the installed set.

### Frontend tasks (7–8)

No Boost. Follow `.claude/rules/frontend/frontend-standards.md` and `.claude/rules/testing.md`. Both Next.js repos are **outside Docker** — run `npm` directly in the repo directory. Sail applies only to `~/sunbites-api`.

### After all backend tasks are complete

Once tasks **1 through 6** are done, verified, and reviewed — before starting task 7 — run a code-quality pass:

1. Review everything this feature added or changed in `~/sunbites-api`.
2. **Use the `laravel-simplifier` agent** on that changed code. It simplifies and refines PHP/Laravel for clarity, consistency, and maintainability **while preserving all functionality**.
3. Re-run the full backend suite afterwards. `laravel-simplifier` must not change behaviour — if any test moves from pass to fail, its change is wrong; revert that change, do not adjust the test to match.
4. Report what it changed and what you accepted or rejected, then stop for review.

Do not run the simplifier per-task. It needs the whole backend surface in view to spot duplication across the service, controllers, and reports.

---

## 4. Settled facts — verified, do not re-derive

These were checked against the running environment. Treat them as ground truth. Re-verifying is wasted effort; contradicting them is a defect.

| Fact | Evidence |
|---|---|
`transactions.deleted_at` **exists** | Added by `vendor/bavix/laravel-wallet/database/2023_12_30_204610_soft_delete.php`, not by `database/migrations/2018_11_06_222923_create_transactions_table.php`. Confirmed by `migrate` on an empty DB then inspecting the table |
`transactions.amount` is **signed minor units**, `decimal(64,0)` | `ABS(amount) / 100` is the correct conversion. Precedent: `WalletHistoryController` line 140, `WalletReportController` line 140 |
The `UNION ALL` + `fromSub` + `whereIn` + `whereDate` + `paginate()` pattern **works** | Built in tinker against MySQL 8.4. Bindings resolve correctly; the `count(*)` aggregate `paginate()` issues also succeeds |
`notifications.data` is **`text`**, not `json` | `SHOW COLUMNS FROM notifications` |
`credit_transactions` currently has **no** `payment_method` | Task 1.2 adds it |
Wallet top-up is `role:admin\|manager\|supervisor` | `routes/kitchen-api.php` lines 142–167. **Cashiers cannot top up.** They gain settlement only |
bavix version | 12.0.3 (`composer.lock`) |
Backend test baseline | **742 tests, 742 passed, 1,952 assertions, exit 0.** Fully green |

---

## 5. The eleven traps

Each of these is a place where the obvious move is wrong. They are listed because a previous review pass caught several of them as real errors.

1. **`charge()` takes `string $receiptNumber`, NOT an `Order`.** In `CheckoutController` the order is created at line 196; credit is charged at line 172. The order does not exist yet. `order_id` stays `null` on `charged` rows, exactly as today.
2. **Do not reorder `CheckoutController` to create the order first.** `Order::create()` needs `is_credit` and `credit_amount`, which are only known after the credit decision. The reorder is not a simple move and is out of scope.
3. **Do not add a migration for `transactions.deleted_at`.** It already exists via the package migration. Adding one collides.
4. **Do not use `->where('data->student_id', $id)`** for the notification debounce. `data` is a TEXT column. Filter `type` and date in SQL, match `student_id` in PHP.
5. **Do not build the ledger formatter as a `JsonResource`.** Union rows are `stdClass`, and the batched performer-name map cannot be passed through `JsonResource::collection()`. Use `app/Services/LedgerEntryFormatter.php`.
6. **Do not compute the wallet report's credit KPI from `walletActivityStudents()`.** Compute it branch-wide from `students.credit_balance`, or the page will disagree with the credit report's `net_outstanding`.
7. **Do not add settlements to `total_revenue` or any sales figure.** The revenue was booked on the original order date. Adding it again double-counts.
8. **Do not add `HasBranch` to `CreditTransaction`.** The global scope would break the portal ledger query, where no active branch is bound. Filter branch explicitly in the report controllers.
9. **Do not create `app/Rules/`.** It does not exist and `CLAUDE.md` forbids new base directories without approval. Use the `ValidatesOutstandingCredit` trait under `app/Http/Requests/Concerns/`.
10. **Do not migrate the 47 legacy inline `$request->validate()` calls.** New code uses Form Requests; legacy stays. `tech.md` now states this explicitly.
11. **Do not make top-up settle credit.** This is the single most likely "helpful fix". It is deliberately forbidden — see requirement 5 and its regression test in task 3.3.

---

## 6. Per-task workflow

For every sub-task, in order:

1. **Re-read** the task in [tasks.md](./tasks.md) and the requirement IDs it cites in [requirements.md](./requirements.md).
2. **Read the relevant design section** in [design.md](./design.md). Interfaces are specified — match them exactly.
3. **Read the actual files you are about to change**, in full where they are small. Never edit from memory of a grep result.
4. **Backend only:** `search-docs` for the patterns involved; invoke `laravel-best-practices`.
5. **Write the code** — minimal. No speculative features, no premature abstraction, no unrequested scaffolding. Minimal never means fewer tests, weaker validation, or missing error handling; those are part of the requirement.
6. **Write the tests named in the task.** Every scenario listed is required, not a suggestion. Arrange-Act-Assert. Test behaviour, not implementation.
7. **Format:** `vendor/bin/sail bin pint --dirty --format agent` for PHP.
8. **Run tests** (section 7).
9. **Verify the gate:** new failures block completion — fix them. Pre-existing failures never block — but the baseline is fully green, so *any* failure is yours.
10. **Tick the checkbox** `[ ]` → `[x]` in tasks.md. It must reflect reality.
11. **If you deviated from design.md** — different approach, changed contract, new component — propose the design.md amendment as part of completion. A stale spec silently corrupts every task built on it afterwards.
12. **Stop.** Report what you did, what you ran, what passed. Wait for review.

---

## 7. Commands

### Backend — `~/sunbites-api` (all through Sail)

```bash
# Single test file — use this while developing
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/CreditSettlementTest.php

# Single test by name
vendor/bin/sail artisan test --compact --filter=test_top_up_does_not_change_credit_balance

# Full suite — before declaring any task group done
vendor/bin/sail artisan test --compact

# Format after every PHP change (required)
vendor/bin/sail bin pint --dirty --format agent

# Migrations
vendor/bin/sail artisan migrate
vendor/bin/sail artisan make:test --phpunit Kitchen/CreditSettlementTest

# Routes
vendor/bin/sail artisan route:list --path=api --except-vendor
```

### Frontend — `~/sunbites-pos` and `~/sunbites-portal`

```bash
npm test                      # jest
npm test -- path/to/file      # single file
npm run type-check            # tsc --noEmit
npm run lint
npm run quality:validate      # type-check + lint, run before finishing a frontend task
npm run test:coverage         # both apps target 80% branches/functions/lines/statements
```

Never use Sail for the frontends. Never use `npm` for the API.

---

## 8. Git

All three repos are on branch **`fix/system-update`**. Stay on it. Do not create branches, merge, or push unless asked.

`~/sunbites-pos` has **unrelated uncommitted work**: `components/pos/student-search-input.tsx` (modified) and `components/pos/student-search-input.test.tsx` (new). **Do not stage, commit, revert, or modify those two files.** They belong to someone else's work in progress.

Commit only when asked. When asked, end commit messages with:

```
Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

---

## 9. Release coupling — read before task 4.2

Task 4.2 removes `wallet_transactions` from `GET /api/v1/students/{student}`. That breaks three POS consumers:

| File | Line | Fix in task |
|---|---|---|
`app/(kitchen)/students/[id]/page.tsx` | 3100–3121 | 7.4 |
`types/student.ts` | 109 | 7.1 |
`__tests__/mocks/handlers.ts` | 640 | 7.1 |

The type and the MSW fixture are the ones that slip through silently — the type keeps TypeScript quiet about a field the API no longer sends, and a stale fixture makes tests pass against a contract that no longer exists. **The minimum shippable release is tasks 1–6 plus 7.1–7.4.** Do not deploy 4.2 without them.

---

## 10. When you are blocked

Stop and ask. Specifically, ask rather than decide when:

- The spec does not cover a case you have hit
- Existing code contradicts the spec in a way that looks like a spec error
- A task turns out substantially larger than described — **task 7.2 is flagged as the likely one**; `app/(kitchen)/students/[id]/page.tsx` is 3,265 lines and was not read end to end. If extraction starts sprawling, stop and propose a split rather than pushing through
- A test fails and the fix would mean changing the test's expectation
- You are about to touch a file no task mentions
- You want to install a package, add a base directory, or change a dependency

When you ask, state: what you were doing, what you found, the options you see, and which you would pick and why. Do not just ask "what should I do".

---

## 11. Definition of done, per task

- [ ] Code matches the interfaces in design.md exactly
- [ ] Every test scenario named in the task is written and passing
- [ ] `pint --dirty` run (PHP) / `quality:validate` run (frontend)
- [ ] Full relevant suite run, output pasted, zero new failures
- [ ] Checkbox ticked in tasks.md
- [ ] Any design deviation proposed as a design.md amendment
- [ ] Stopped and reported — did not start the next task

---

## 12. Start here

**Task 1.1** — the three enums plus `tests/Unit/CreditEnumsTest.php`. Purely additive, nothing depends on it yet, and everything later reads from it.

Before writing code: read the steering files, read requirements 1/3/4/6/12, read the *Enums* and *Data Models* sections of design.md, then `search-docs` for enum patterns and invoke `laravel-best-practices`.
