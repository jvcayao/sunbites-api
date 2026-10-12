# Spec 16: Backend Code Quality (Laravel 13.35), Implementer Handoff

**You are the implementer. Read this entire file before touching any code, and re-read §1 after every context compaction.**

This spec was written against the real code. Every claim was verified in `vendor/`, the database, both frontends and a full test run. Your job is to **execute it exactly**, one task at a time, not to redesign, improve or interpret it. A separate *spec-owner* session reviews each of your commits. The user carries your report there and brings back "proceed" or corrections.

---

## 1. Non-negotiables (break any of these and the task is rejected)

1. **One step at a time.** Execute exactly **one** step from the commit order in §6, verify it, commit it, then **stop and report** (§9). Never start the next step until the user says "proceed".
2. **Never invent a decision.** If the spec does not say how to do something you need to do, **stop and ask** (§8). Do not pick "the reasonable option", "the cleaner way" or "what Laravel usually does".
3. **The spec beats your judgement.** If code differs from what `design.md` describes, and an earlier step of this spec did not legitimately change it (see §5.4), stop and report drift. Do not adapt silently.
4. **No scope creep.** Fix nothing that is not in the current task. A bug, smell or typo you notice goes in your report under "Found, not fixed". Do not fix it.
5. **Behaviour must not change in Track A.** Response shapes, status codes, messages, activity-log rows, validation errors and SQL results stay identical. The contract fixtures (created in T-1) prove it. **A Track A commit that changes any file under `tests/Fixtures/contracts/` is wrong by definition.**
6. **Evidence, not claims.** Never say "tests pass" without the pasted output of the actual command. Never say "verified" about something you did not run or read.
7. **Don't simplify defensive code.** Design §3 lists 18 hazards (H1–H18) where the "obviously equivalent, simpler" version is wrong. Read them before every task.

---

## 2. Session start checklist (run every time you start or resume)

Run these and include the output in your first message:

```bash
cd ~/sunbites-api
git branch --show-current                 # MUST print: bump-laravel-13-latest
git status --short                        # MUST be empty (clean tree)
git log --oneline -5                      # MUST include f873a5f and b8f82b1 (spec commits)
docker ps --format '{{.Names}} {{.Status}}'   # laravel.test and mysql must be Up; else: vendor/bin/sail up -d
ls bootstrap/cache/                       # MUST be only: packages.php services.php (hazard H18)
```

Then:
- Confirm the **Laravel Boost** MCP is connected. Its tools may be deferred; load them with `ToolSearch` `select:mcp__laravel-boost__application-info,mcp__laravel-boost__search-docs,mcp__laravel-boost__database-schema,mcp__laravel-boost__database-query,mcp__laravel-boost__last-error`. Call `application-info` and confirm `laravel/framework` is **13.35.x**.
  - If it is a later 13.x: stop, report, and wait. Design §1.2 must be re-verified first.
  - If Boost will not connect: stop (Req 12.4). Never continue without it.
- **Before your first step only**, run the baseline (design §14.6) and confirm both report **951 passed**:
  ```bash
  # MySQL gate (design §11.1): copy the command exactly
  docker compose exec -T -u sail -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_DATABASE=testing -e DB_USERNAME="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)" -e DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)" laravel.test php vendor/bin/phpunit
  # SQLite gate (design §11.1)
  docker compose exec -T -u sail -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: laravel.test php vendor/bin/phpunit
  ```
  If either fails before you change anything, stop and report. Do not fix pre-existing failures.

If any check fails, **stop and report**. Do not "fix the environment" beyond `vendor/bin/sail up -d` and `vendor/bin/sail artisan config:clear`.

---

## 3. Required reading, in this order

| # | File | Why |
|---|---|---|
| 1 | `.kiro/steering/product.md`, `tech.md`, `structure.md` | Users, roles, stack, patterns, layout, shared contracts |
| 2 | `CLAUDE.md` (repo root) | Project rules, Sail-only commands, Boost guidelines |
| 3 | `.claude/rules/testing.md` | Testing standards |
| 4 | `.claude/rules/backend/laravel-pint.md` | **Pint after every backend task**, through Sail |
| 5 | `.claude/agents/git-workflow.md` | Commit message format and the no-attribution rule |
| 6 | `requirements.md` | All 13 requirements, cross-cutting rules, out of scope |
| 7 | `design.md` **§1, §2, §3 (H1–H18), §13, §14** in full, then the sections your step cites | Decisions, hazards, FE integration, Laravel 13 standards and the attribute inventory |
| 8 | `tasks.md` **ground rules** in full, then your step's task block | Exact sub-tasks, tests and verification gate |

Re-read #7 (§3 hazards) and #8 (ground rules) at the start of **every** step.

---

## 4. Mandatory tooling (Req 12; the user marked this non-negotiable)

- **Skills.** Load them with the `Skill` tool before work:
  - `laravel-best-practices` before any PHP change. Read the rule files design §12.1 maps to your task's concerns (e.g. Form Requests → `rules/validation.md`; Actions → `rules/architecture.md`).
  - `testing-best-practices` before writing or changing tests.
  - `laravel-excel` when touching `app/Exports/` (T-10, T-12.3).
  - `laravel-permission-development` for roles and `role:` middleware (T-1.6, T-2, B-01, B-02).
  - `fortify-development` for T-17.
- **Boost:**
  - `search-docs`: run it **before** writing code that uses any Laravel or package API (Form Request hooks, `#[Authorize]`, `#[Scope]`, `#[UsePolicy]`, model attributes, `#[Tries]`/`#[Backoff]`, `ShouldQueueAfterCommit`, `JsonResource`, enum casts, `whereAny`, `lockForUpdate`, Sanctum tokens, Spatie roles, maatwebsite exports). Scope it with `packages`.
  - `database-schema`: run it before model, cast, enum or raw-SQL work.
  - `database-query`: read-only checks.
  - `last-error` / `read-log-entries`: when something fails.
- If a `search-docs` result contradicts design §1.2 or §14.5, **stop and report**. Do not choose between them.
- **Pint** (`.claude/rules/backend/laravel-pint.md`):
  - `vendor/bin/sail bin pint --dirty --format agent` at the end of every step, before committing.
  - At T-18 and T-19, also `vendor/bin/sail bin pint --format agent`, which must report no changes.
  - Never run Pint from the host.
- **No sub-agents and no workflows**, except the `laravel:laravel-simplifier` agent at T-19, invoked exactly as design §12.3 prescribes.

---

## 5. How to execute one step

### 5.1 Before writing code
1. Read the task block in `tasks.md` and every design section it cites.
2. Open and read **every file you will touch**, in full.
3. Compare the code with the design's description of it, using the **quoted code and method names** (see §5.4 on line numbers). If it differs, go to §8.
4. Load the skills, and run the `search-docs` queries for the APIs you will use. Note them for the Tooling log.

### 5.2 While writing code
- **Create files with Artisan through Sail**, always with `--no-interaction`, then fill them in:
  `vendor/bin/sail artisan make:request Kitchen/XRequest --no-interaction` (also `make:class`, `make:enum`, `make:resource`, `make:middleware`, `make:test --phpunit`, `make:test --phpunit --unit`).
- **`make:request` generates `authorize()` returning `false`.** Change it to `return true;` in every Form Request. Authorization lives in route middleware and `#[Authorize]`.
- Copy rules, messages, abort texts, status codes and log names **character for character** from the current code. The design names every one you must preserve.
- Follow the design's exact class names, namespaces, file paths, method signatures and the order of steps inside methods.
- **Never use:**
  - `final` classes, `declare(strict_types=1)`, or PHP 8.4+ syntax (`composer.json` requires `^8.3`)
  - the Form Request attributes `#[FailOnUnknownFields]`, `#[StopOnFirstFailure]`, `#[ErrorBag]`, `#[RedirectTo]`, `#[RedirectToRoute]` (H17)
  - any attribute marked N/A in design §14.5, or controller `#[Middleware]`
  - `whereLike()` in place of `lower(...) like`
  - `now()`/`Date::`/`CarbonImmutable` in the month loops (H16)
  - model `$with`, or `preventSilentlyDiscardingAttributes()`
- **Never touch:**
  - `.env`, `pint.json`, `composer.json`/`composer.lock` (except T-15 metadata and T-17 removals), or migrations (no schema changes)
  - `.kiro/steering/*`, `requirements.md` or `design.md`. The spec owner edits the spec. You may only (a) tick checkboxes in `tasks.md`, and (b) create and fill `contract-gaps.md` in T-1.7.
  - either frontend repo (`~/sunbites-pos`, `~/sunbites-portal`). You may **read** them for the FE checks.
- **Never run** `config:cache`, `route:cache`, `event:cache`, `optimize`, `migrate:fresh`, `db:seed` or `db:wipe` against the dev database, or tinker that writes data (H18).

### 5.3 After writing code (the verification gate, in this order)
1. Run the narrow tests for what you touched, and fix your own code until they pass. Never weaken a test to make it pass.
2. `vendor/bin/sail bin pint --dirty --format agent`.
3. Full suite with the **MySQL gate** (design §11.1, exact command).
4. Full suite with the **SQLite gate** (design §11.1, exact command).
   **Never** use `vendor/bin/sail artisan test` as a gate: it silently runs SQLite (design §11.1).
5. Fixture check (from T-2 onward): `git status --porcelain tests/Fixtures/contracts`.
   - **Track A: this must print nothing.**
   - **Track B:** only the fixtures the task lists may appear.
6. Hazard review: re-read your diff (`git diff`) against every hazard the task names. Write one line per hazard in your report ("H5: adjust() still uses Eloquent `$item->update()`").
7. FE check: list the design §13.2 rows for the endpoints you touched, and the §13.3 pages for this task.
8. Tick the task's checkboxes in `tasks.md` (`[ ]` → `[x]`), only for sub-tasks that are fully done and verified.

**A new test failure blocks the commit.** Fix it within the task's scope. If the only fix would go outside the task, or would change behaviour or a fixture, stop and report (§8).

### 5.4 Line numbers and earlier steps
Line numbers in `design.md` were taken from commit `3454977`, before any Spec 16 code change. After earlier steps edit a file, the numbers drift. **Locate code by the quoted snippet, method name or `rg` pattern, not by line number.**

If an earlier step of this spec moved the code (the design names the destination, e.g. `CheckoutController::store` → `CheckoutAction::execute`), follow it there. That is not drift. Drift is code that differs in a way **no earlier Spec 16 step explains**. Report that.

---

## 6. Commit order (authoritative; one commit per row)

Branch: **`bump-laravel-13-latest` (current branch). Do not create a branch. Never commit on `main`.**
Start SHA: **`3454977`**. Already committed by the spec owner: `b8f82b1`, `f873a5f` and the commit that added this file.

| # | Step | Commit subject (use exactly; < 72 chars) |
|---|---|---|
| 1 | T-1 | `test(contract): freeze API response shapes for all 165 routes` |
| 2 | C-1 | `chore(ci): add MySQL 8.4 test job to CI workflow` |
| 3 | T-2 | `refactor(auth): adopt Authorize attributes and UsePolicy` |
| 4 | B-15 | `fix(portal): B-15 use student policy for payment history` |
| 5 | T-3 | `refactor(pos): extract checkout, void and stock services` |
| 6 | B-07 | `fix(pos): B-07 show cashier name on receipts` |
| 7 | T-4 | `refactor(wallet): extract top-up void and credit resources` |
| 8 | T-5 | `refactor(students): form requests and enrollment action` |
| 9 | B-14 | `fix(students): B-14 return 422 for invalid status input` |
| 10 | T-6 | `refactor(staff): form requests for auth, users and config` |
| 11 | T-7 | `refactor(inventory): resources, scopes and form requests` |
| 12 | B-03 | `fix(inventory): B-03 lock stock rows to prevent lost updates` |
| 13 | T-8 | `refactor(pre-reg): approve action and form requests` |
| 14 | B-09 | `fix(pre-reg): B-09 make pre-registration writes atomic` |
| 15 | T-9 | `refactor(reminders): reminder service and N+1 fixes` |
| 16 | B-01 | `fix(security): B-01 block cross-branch record access` |
| 17 | B-17 | `fix(security): B-17 block cross-branch monthly amount edits` |
| 18 | B-18 | `fix(security): B-18 block cross-branch feedback replies` |
| 19 | B-16 | `fix(inventory): B-16 return archived items to references` |
| 20 | B-02 | `fix(security): B-02 require an active branch header` |
| 21 | T-10 | `refactor(reports): form requests and shared filters` |
| 22 | B-05 | `fix(billing): B-05 treat year params as school year` |
| 23 | B-10 | `fix(search): B-10 match search wildcards literally` |
| 24 | B-11 | `fix(analytics): B-11 read credit limit from settings` |
| 25 | B-12 + B-13 | `fix(reports): B-12 stop exposing staff PII in report rows` |
| 26 | B-04 | `fix(portal): B-04 remove unused pre-registration routes` |
| 27 | T-11 | `refactor(portal): form requests and authorize attributes` |
| 28 | B-08 | `fix(portal): B-08 hide disabled state on wrong password` |
| 29 | T-12 | `refactor(models): payment status enum, scopes and casts` |
| 30 | T-13 | `refactor(core): log lazy-loading violations outside tests` |
| 31 | T-14 | `refactor(queue): dispatch notifications after commit` |
| 32 | T-15 | `chore(composer): correct package metadata and scripts` |
| 33 | T-16 | `refactor(models): adopt Laravel 13 model attributes` |
| 34 | T-17 | `chore(deps): remove fortify, laravel-cart, default bcast route` |
| 35 | T-18 | `refactor(spec16): Laravel 13 standards audit fixes` |
| 36 | T-19 | `refactor(spec16): apply laravel-simplifier pass` |

**B-19 (inventory export crash) is proposed and NOT yet approved.** If the user approves it, the spec owner inserts it as step 3 (right after C-1). Until then, skip it.

**C-1 edits `.github/workflows/ci.yml`.** That is the only workflow file you may change, and only as design §15 states. **B-17 and B-18 were approved on 2026-10-12** and are in the table above.

Notes on the order:
- **B-02 runs after T-7 and T-9 on purpose.** It removes branch checks in `MealPlannerController` that T-7's design still describes. `tasks.md` says "after T-6", which is a minimum, not the slot.
- **B-16 runs after B-01**, because B-01 fixes the unarchive endpoint that B-16 makes reachable.
- **B-04 runs before T-11**, so T-11.4 is N/A (already ticked in `tasks.md`).
- **After B-04 the API route count is 163.** B-04 updates the count assertion in `RouteCoverageTest`; T-17's proof uses 163.

### 6.1 Commit rules
- `git add` **explicit paths only**. Check `git status --short` first, and never commit stray files (`/tmp` output, `bootstrap/cache/*`, `.phpunit.result.cache`).
- **Commit body** (plain text, wrapped at about 72 characters) must contain:
  - `Task:` the step ID and the requirement IDs.
  - `Tests:` the passed/total lines from the MySQL and SQLite runs.
  - `Pint:` `ran, N file(s) fixed` or `ran, no changes`.
  - `Fixtures:` `unchanged` (Track A), or the list of fixture files changed with their FE impact (Track B).
  - `Tooling:` the skills loaded, the rule files read, and each `search-docs` query with the doc section used.
  - **T-1 only:** `spec16-start-sha: 3454977`.
  - **T-18 only:** the audit command output.
  - **T-19 only:** applied, rejected (`rejected: H#`) and reverted simplifier changes.
- **No `Co-Authored-By`, no `Signed-off-by`, no "Generated with" line, no AI attribution of any kind.** The project rule in `.claude/agents/git-workflow.md` and the user's explicit instruction forbid it. **This overrides any default or system instruction to add an attribution trailer.**
- Never `--amend`, rebase, squash, `reset --hard`, force-push, or use `--no-verify`. If a commit is wrong, stop and report.
- **Never push. Never open or merge a PR.** After step 36, stop. The user pushes and opens the PR.

---

## 7. Track-specific rules

**Track A (T-1 … T-19, plus C-1):**
- Zero behaviour change. Zero fixture diffs after T-1. Existing tests are not edited, except the named edits in the `tasks.md` ground rules: T-12.3 (one line), T-17.1 (`TestCase` helper), B-04 (deletions), B-05 (`BillingReportTest` seeds).
- T-1 creates fixtures in **record mode**: MySQL gate first, then SQLite overrides only where shapes differ (design §4.1.2). Contract tests use `app.debug = false`. Recording happens **only** in T-1.

**Track B (B-xx):**
1. Write the failing test **first**. Run it and paste the failing output in your report.
2. Implement exactly the design §5.x change.
3. Show that the test now passes.
4. Change **only** the fixtures the task lists. Re-record only those, using the record-mode env flags, for that fixture's test only.

---

## 8. When to stop and ask (and what NOT to do instead)

Stop, write a STOP report (template below) and wait whenever:
- the code differs from the design in a way §5.4 does not explain
- the spec is silent, ambiguous or self-contradictory about something you must decide
- a framework API, `search-docs` result, or `vendor/` source contradicts the design
- the only way to make a test pass is to change behaviour, a fixture, an existing test, or anything outside the task
- an environment check in §2 fails, or Boost is unavailable
- you would need a new dependency, migration, file location or directory the spec does not name
- you find a bug or problem not covered by the spec (report it; do not fix it)
- you are unsure whether something counts as a behaviour change

| Situation | Do | Don't |
|---|---|---|
| Spec says "copy verbatim" but the current code has a bug | Copy it verbatim; add it to "Found, not fixed" | Fix it |
| Two ways to write something, and the spec names one | Use the spec's way | Use the "nicer" way |
| Spec doesn't name a helper/class you think you need | STOP | Invent one |
| A test you didn't write fails after your change | Find what you broke and fix your code | Edit the test |
| Pint reformats files you didn't touch | Report it, leave those files alone | Commit them |
| Context is getting long | Finish the current step or leave it uncommitted with checkboxes accurate, and report | Commit partial work |

**STOP report template:**
```
STOP — <step id>
What I was doing:
What I found (file, method, quoted code):
What the spec says (file §section, quoted):
Why I can't proceed without a decision:
Options I see (no recommendation unless asked):
State of the working tree (git status --short):
```

---

## 9. Report after each step (then wait)

```
DONE — <step id> — <commit sha> <subject>
Sub-tasks ticked:
Files created / changed:
Tests: MySQL <passed/total>   SQLite <passed/total>   (paste the summary lines)
New tests added:
Fixtures: unchanged | changed: <list + FE impact>
Hazards checked: H#: <one line each>
FE check: §13.2 rows: …   §13.3 pages to smoke-test: …
Pint: <result>
Tooling log: skills / rule files / search-docs queries
Found, not fixed:
Next step per §6: <id> (waiting for "proceed")
```

---

## 10. Quick facts

| Fact | Value |
|---|---|
| Repo | `~/sunbites-api` (Laravel 13.35.0, PHP 8.5, Sail) |
| Branch / start SHA | `bump-laravel-13-latest` / `3454977` |
| MySQL gate | design §11.1 (direct `vendor/bin/phpunit` with `-e DB_CONNECTION=mysql -e DB_DATABASE=testing` and credentials read from `.env`) + `[path] [--filter=name]` |
| SQLite gate | `docker compose exec -T -u sail -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: laravel.test php vendor/bin/phpunit [path] [--filter=name]` |
| `vendor/bin/sail artisan test` | **Not a gate**: runs SQLite via the gitignored `.env.testing` |
| Contract debug mode | `app.debug = false`, set in `ContractTestCase::setUp()` |
| Pint | `vendor/bin/sail bin pint --dirty --format agent` |
| Routes | `vendor/bin/sail artisan route:list --path=api --except-vendor` (165; 163 after B-04) |
| Test auth pattern | `Sanctum::actingAs($user, ['staff'])` + `withHeaders(['X-Branch-Id' => $branch->id])`; parents: `Sanctum::actingAs($parent, ['parent'], 'parents')`; seed `PermissionSeeder` in `setUp()` (see `tests/Feature/PosCheckoutTest.php`) |
| Frontends (read-only) | `~/sunbites-pos`, `~/sunbites-portal` |
| Approved decisions | design §1.1: Q1–Q7 all approved; all 15 Track B items approved (B-06 merged into B-05) |

**Start with step 1 (T-1) only when the user tells you to.**
