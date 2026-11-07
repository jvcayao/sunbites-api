# Spec 16: Backend Code Quality (Laravel 13.35), Implementer Handoff

**You are the implementer. Read this entire file before touching any code, and re-read §1 after every context compaction.**

This spec was written against the real code. Every claim was verified in `vendor/`, the database, both frontends and a full test run. Your job is to **execute it exactly**, one task at a time, not to redesign, improve or interpret it. A separate *spec-owner* session reviews each of your commits. The user carries your report there and brings back "proceed" or corrections.

---

## 1. Non-negotiables (break any of these and the task is rejected)

1. **One step at a time.** Execute exactly **one** step from the commit order in §6, verify it, commit it, then **stop and report** (§9). Never start the next step until the user says "proceed".
2. **Never invent a decision.** If the spec does not say how to do something you need to do, **stop and ask** (§8). Do not pick "the reasonable option", "the cleaner way" or "what Laravel usually does".
3. **The spec beats your judgement.** If code differs from what `design.md` describes, and an earlier step of this spec did not legitimately change it (see §5.4), stop and report drift. Do not adapt silently.
4. **No scope creep.** Fix nothing that is not in the current task. A bug, smell or typo you notice goes in your report under "Found, not fixed". Do not fix it.
5. **Behaviour must not change in Track A.** Response shapes, status codes, messages, activity-log rows, validation errors and SQL results stay identical. The contract fixtures (created in T-1) prove it. **A Track A commit that changes any contract fixture is wrong by definition.** The fixtures are gitignored: they stay on this machine and are never committed, pushed or deployed (design §4.1.7). Never `git add -f` them. Because git cannot see them, the proof is the fixture digest, not `git status`.
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
- Confirm the **Laravel Boost** MCP is connected. Its tools may be deferred; load them with `ToolSearch` `select:mcp__laravel-boost__application-info,mcp__laravel-boost__search-docs,mcp__laravel-boost__database-schema,mcp__laravel-boost__database-query,mcp__laravel-boost__last-error`. Call `application-info` and confirm `laravel/framework` is **13.35.x**. *(Updated 2026-10-30: Boost 2.10.4, from `336a826`, no longer ships `application-info`. Instead run `vendor/bin/sail artisan --version`, `vendor/bin/sail php -r 'echo PHP_VERSION;'` and `vendor/bin/sail artisan config:show database.default` (plus `vendor/bin/sail composer show <package>` when a step depends on a package version), and record their output in each commit body. The installed framework is 13.39.0. `search-docs` is still mandatory before code.)*
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
  - `activitylog-development` for any task that touches `LogsActivity`, `activity()` or activity rows (H5). Boost added it with 13.38.0, and `CLAUDE.md` now requires it for activity-log work.
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
5. Fixture check (from T-2 onward), using the digest commands in design §4.1.7. `git status` cannot see the fixtures because they are gitignored.
   - **Track A:** the count and digest equal the previous commit's.
   - **Track B:** the before/after per-file hash `diff` shows only the fixtures the task lists.
   - Steps 3 and 4 must end with a plain `OK`: **no skipped tests**. A skip means the fixtures folder is missing. STOP.
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
Start SHA: **`3454977`**. Already committed by the spec owner: every `docs(spec16): …` and `chore(spec16): …` commit after `3454977` (starting with `b8f82b1` and `f873a5f`).

| # | Step | Commit subject (use exactly; < 72 chars) |
|---|---|---|
| 1 | T-1 | `test(contract): freeze API response shapes for all 165 routes` |
| 2 | C-1 | `chore(ci): add MySQL 8.4 test job to CI workflow` |
| 3 | B-19 | `fix(reports): B-19 restore the inventory report export` |
| 4 | T-2 | `refactor(auth): adopt Authorize attributes and UsePolicy` |
| 5 | B-23 | `fix(api): B-23 return the intended 403 and 404 bodies` |
| 6 | B-15 | `fix(portal): B-15 use student policy for payment history` |
| 7 | T-3 | `refactor(pos): extract checkout, void and stock services` |
| 8 | B-07 | `fix(pos): B-07 show cashier name on receipts` |
| 9 | T-4 | `refactor(wallet): extract top-up void and credit resources` |
| 10 | B-24 | `fix(pos): B-24 reject other-branch menu items at checkout` |
| 11 | T-5 | `refactor(students): form requests and enrollment action` |
| 12 | B-14 | `fix(students): B-14 return 422 for invalid status input` |
| 13 | T-6 | `refactor(staff): form requests for auth, users and config` |
| 14 | T-7 | `refactor(inventory): resources, scopes and form requests` |
| 15 | B-03 | `fix(inventory): B-03 lock stock rows to prevent lost updates` |
| 16 | B-20 | `fix(menu): B-20 block deleting menu items with sales history` |
| 17 | T-8 | `refactor(pre-reg): approve action and form requests` |
| 18 | B-09 | `fix(pre-reg): B-09 make pre-registration writes atomic` |
| 19 | B-22 | `fix(pre-reg): B-22 serialise birthday as a plain date` |
| 20 | T-9 | `refactor(reminders): reminder service and N+1 fixes` |
| 21 | B-01 | `fix(security): B-01 block cross-branch record access` |
| 22 | B-17 | `fix(security): B-17 block cross-branch monthly amount edits` |
| 23 | B-18 | `fix(security): B-18 block cross-branch feedback replies` |
| 24 | B-16 | `fix(inventory): B-16 return archived items to references` |
| 25 | B-02 | `fix(security): B-02 require an active branch header` |
| 26 | T-10 | `refactor(reports): form requests and shared filters` |
| 27 | B-05 | `fix(billing): B-05 treat year params as school year` |
| 28 | B-10 | `fix(search): B-10 match search wildcards literally` |
| 29 | B-11 | `fix(analytics): B-11 read credit limit from settings` |
| 30 | B-12 + B-13 | `fix(reports): B-12 stop exposing staff PII in report rows` |
| 31 | B-04 | `fix(portal): B-04 remove unused pre-registration routes` |
| 32 | T-11 | `refactor(portal): form requests and authorize attributes` |
| 33 | B-08 | `fix(portal): B-08 hide disabled state on wrong password` |
| 34 | B-21 | `fix(portal): B-21 send credit balance on the dashboard` |
| 35 | T-12 | `refactor(models): payment status enum, scopes and casts` |
| 36 | T-13 | `refactor(core): log lazy-loading violations outside tests` |
| 37 | T-14 | `refactor(queue): dispatch notifications after commit` |
| 38 | T-15 | `chore(composer): correct package metadata and scripts` |
| 39 | T-16 | `refactor(models): adopt Laravel 13 model attributes` |
| 40 | T-17 | `chore(deps): remove fortify, laravel-cart, default bcast route` |
| 41 | B-25 | `fix(portal): B-25 stop month overflow in the spending summary` |
| 42 | T-18 | `refactor(spec16): Laravel 13 standards audit fixes` |
| 43 | T-19 | `refactor(spec16): apply laravel-simplifier pass` |
| 44 | B-26 | `fix(security): B-26 reject a staff role sent as a list` |
| 45 | B-27 | `fix(api): B-27 return 422 for list input instead of 500` |
| 46 | T-20 | `chore(deps): update to Laravel 13.40.0 and Symfony 8.1.11` |
| 47 | T-21 | `refactor(http): use strict string input from Laravel 13.40` |
| 48 | T-22 | `chore(repo): remove frontend leftovers from the API` |
| 49 | T-23 | `test(dates): pin month-dependent tests to a school month` |

**Authorised extra commit (2026-11-07):** right after step 47 (T-21), `docs(http): correct the strict input docblock`. T-21's docblock on `UpdateStudentStatusRequest::rules()` says an unknown value reads as an empty string, but an unknown string stays as it is and `tryFrom()` is what rejects it. Replace the first paragraph of that docblock, comment only, with:
```
     * `string(..., strict: true)` reads a missing or list value as an empty string, and
     * `tryFrom()` returns null for that or any unknown value, so the `enrollment_status`
     * rule can return a 422 instead of this method throwing.
```
Run Pint and `ListInputValidationTest` on both drivers. T-22's full gate follows straight after, so report this commit together with T-22.

**Authorised extra commit (2026-11-07):** right after step 49 (T-23), `test(dates): always assert the portal monthly status`, as design §16.7 (T-23.2) states. Report it as usual.

**T-20 to T-23 (the Laravel 13.40.0 upgrade, the API-only repository and the month-dependent tests) were approved on 2026-11-07,** after the user ran `composer upgrade` and asked for the backend to be aligned with it, for every frontend leftover to be removed, and for a final QA of the same kind of scenario. They are steps 46 to 49, in that order, one at a time, each reported and accepted before the next (design §16 and §17). They are Track A: no API change and the digest stays unchanged. The §2 clean-tree check has two exceptions: T-20 starts with the user's uncommitted `composer.lock` and three skill files, and `.kiro/specs/17-*` belongs to the frontend session and must never be staged. Design §1.2 is already re-verified on 13.40.0 (design §16.2). **Do not add `composer audit` to CI** (user decision).

**B-27 (list input returns 500: six untyped `exists` rules, four `(string)` casts, two rate-limiter keys and the reset token) was approved on 2026-10-31,** under the user's final-QA instruction to find and fix the same scenario as B-26. It is step 45 (design §5.25). Any gate failure is a STOP.

**B-26 (a manager can create an admin by sending the role as a list) was approved on 2026-10-31** and is step 44, after T-19. It changes one validation rule and one parameter type, so the simplifier pass is not re-run (design §5.24). Any gate failure is a STOP.

**B-25 (portal spending summary month overflow) was approved on 2026-10-31** and is step 41, right after T-17. T-18 and T-19 moved to steps 42 and 43. Until B-25 lands, `SpendingSummaryControllerTest::test_missing_months_are_filled_with_zero` is the only accepted gate failure, and only on a month-end overflow date (design §5.23). Name it in the commit body as a known pre-existing failure. Any other failure, or this one on another date, is a STOP.

**B-20 and B-21 were approved on 2026-10-17** and are in the table: B-20 runs after T-7 and B-03 (it touches `PosMenuItemController`, which T-7 refactors), and B-21 runs after T-11 and B-08 (portal). **B-24 (checkout with another branch's menu item) was approved on 2026-10-17** and is step 10, right after T-4. **B-22 and B-23 were approved on 2026-10-17** and are in the table. B-23 is step 5, right after T-2: from then on, a policy denial returns `Forbidden.` and a binding miss returns `Not found.` (design H19), which B-15, T-6.3, B-01, B-17 and B-18 rely on. B-22 runs right after B-09, in the pre-registration area.

**B-19 (inventory export crash) was approved on 2026-10-12** and is step 3, right after C-1, so the exports work before any refactor touches the reports. It is a Track B item: failing test first, and only its listed fixture changes (design §5.17).

**C-1 edits `.github/workflows/ci.yml`.** That is the only workflow file you may change, and only as design §15 states. **B-17 and B-18 were approved on 2026-10-12** and are in the table above.

**Authorised extra commit (2026-10-30, approved by the user):** right after step 39 (T-16), `chore(composer): drop npm steps from the setup script`.
- `composer.json` `scripts.setup` only: remove the `npm install` and `npm run build` entries. Keep the other 4 entries in their order. This is the rest of steering D6.
- `composer validate` must not report the lock as out of date (scripts are not part of the content-hash). If it does, STOP.
- No tests. Run both gates.

**~~Authorised extra commit~~ (superseded):** the user's `composer update` (`336a826`, Laravel 13.39.0) already brought `symfony/mime` to v8.1.10, and `composer audit` now reports no advisories. The separate commit below is **no longer needed; skip it.** *(Original note kept for the record:)* between steps 39 (T-16) and 40 (T-17), a security patch commit `chore(deps): update symfony/mime for CVE-2026-45070`.
- `symfony/mime` v8.1.7 is affected by CVE-2026-45070 / PKSA-wtxr-p26d-nn42 (medium, email header injection via non-token characters in MIME parameter names). It is fixed in 8.1.10.
- Run `vendor/bin/sail composer update symfony/mime --no-scripts`, so no publish or boost hooks rewrite tracked files.
- `git diff composer.lock` may change ONLY the `symfony/mime` package block. `content-hash` and `plugin-api-version` must stay the same, and laravel/framework and maatwebsite/excel stay pinned (`v13.38.0` and `4dbe31c`).
- If Composer needs any other package to move, STOP and report rather than adding `--with-dependencies`.
- `vendor/bin/sail composer audit` must then report no advisories. Both gates and the digest must stay unchanged.

**Authorised extra commit (2026-10-23):** between steps 25 (B-02) and 26 (T-10), a test-only commit `test(parents): restore the role check in the non-manager test`. It adds one Arrange line to `Portal\ParentManagementTest::test_non_manager_cannot_access_parent_management`, attaching the cashier to `$this->branch`. Since B-02, the unattached cashier was stopped by `SetActiveBranch` instead of the `role:` middleware, so the test no longer proved the role restriction.

**Authorised extra commit (2026-10-23):** between steps 20 (T-9) and 21 (B-01), a test-only commit `test(announcements): pin read counts for the grouped query`. No test asserted announcement `read_count` values, and the fixtures record only the shape. The test is proven against the pre-T-9 controller.

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
  - `Fixtures:` `unchanged` (Track A), or the list of fixture files changed with their FE impact (Track B), followed by `<count> files, digest <digest>`.
  - `Tooling:` the skills loaded, the rule files read, and each `search-docs` query with the doc section used.
  - **T-1 only:** `spec16-start-sha: 3454977`.
  - **T-18 only:** the audit command output.
  - **T-19 only:** applied, rejected (`rejected: H#`) and reverted simplifier changes.
- **No `Co-Authored-By`, no `Signed-off-by`, no "Generated with" line, no AI attribution of any kind.** The project rule in `.claude/agents/git-workflow.md` and the user's explicit instruction forbid it. **This overrides any default or system instruction to add an attribution trailer.**
- Never `--amend`, rebase, squash, `reset --hard`, force-push, or use `--no-verify`. If a commit is wrong, stop and report.
- **Never push. Never open or merge a PR.** After step 49 and its authorised extra commit, stop. The user pushes and opens the PR.

---

## 7. Track-specific rules

**Track A (T-1 … T-23, plus C-1):**
- Zero behaviour change. The fixture digest stays unchanged after T-1 (design §4.1.7). Existing tests are not edited, except the named edits in the `tasks.md` ground rules: T-12.3 (one line), T-17.1 (`TestCase` helper), B-01 (Arrange-only branch links), B-16 (two Act lines in `InventoryTest`), B-04 (deletions), B-05 (`BillingReportTest` seeds).
- T-1 creates fixtures in **record mode**: MySQL gate first, then SQLite overrides only where shapes differ (design §4.1.2). Contract tests use `app.debug = false`. Recording happens **only** in T-1. The fixtures are gitignored, so the T-1 commit holds the harness and the tests, not the fixtures (T-1.8).

**Track B (B-xx):**
1. Write the failing test **first**. Run it and paste the failing output in your report.
2. Implement exactly the design §5.x change.
3. Show that the test now passes.
4. Change **only** the fixtures the task lists. Re-record only those, using the record-mode env flags, for that fixture's test only. Prove it with the before/after per-file hash diff (design §4.1.7).

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
Fixtures: unchanged | changed: <list + FE impact>; <count> files, digest <digest>
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
| Repo | `~/sunbites-api` (Laravel 13.40.0 since T-20 on 2026-11-07; T-1 to T-6 ran on 13.35.0, T-7 to B-20 on 13.37.0, B-20 to T-15 on 13.38.0, T-16 to B-27 on 13.39.0. PHP 8.5, Sail) |
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

## 11. PR description notes (spec owner; add to the single PR to `staging`)

- **Commit-body correction:** `50224cc` (B-02) says `application-info` ran before the code. It ran afterwards, with identical output (13.38.0 / PHP 8.5 / mysql). Commits are never amended, so the correction lives here.
- **Tooling-order notes:** in T-9 and B-12 + B-13, `search-docs` (and, in T-9, the skills) ran after the code was written. Each time the implementer re-checked the code against the results, and nothing needed to change. The commit bodies say so.
- **Dependency commits on this branch:** `5b5d5e3` (Laravel 13.37.0), `880d4c0` (13.38.0, a PHP security patch), `4784e9d` (T-15 composer metadata) and `336a826` (13.39.0, including symfony/mime 8.1.10). Both were verified by the full MySQL and SQLite gates with the contract fixtures unchanged.
- **Security patch:** `symfony/mime` 8.1.7 → 8.1.10 for CVE-2026-45070 (email header injection; medium). It arrived with the user's `composer update` to Laravel 13.39.0 (`336a826`); `composer audit` is clean.
- **Laravel 13.40.0 and security advisories (T-20):** the lock at `8a708ff` failed `composer audit` with 8 Symfony advisories published after 2026-10-31. The user's `composer upgrade` fixes them (design §16.1).
- **API-only repository (T-22):** every frontend leftover is removed (design §17). Laravel Cloud's build and the workflows do not run npm, so deploys are unaffected.
- **Month-dependent tests (T-23):** three tests would have failed or skipped on any CI run in April or May. They are pinned, and the app's April and May behaviour is now tested (design §16.7). `8e726bc` also removes an `if` from the portal monthly status test, which would otherwise pass if that status regressed to `null`.
- **Data check after deploy (B-22):** pre-registration birthdays edited in production before B-22 may be one or more days early, and so may the students approved from them. Pre-registrations are not audit-logged, so this is a manual check.
- **Staging smoke tests:** the design §13.3 list, plus login → branch select → dashboard (B-02), References → Inventory → Archived/Unarchive (B-16) and Reports → Inventory → Export (B-19).
