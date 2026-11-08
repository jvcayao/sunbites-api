# Spec 17: Frontend Code Quality and Backend Compliance, Implementer Handoff

**You are the implementer. Read this entire file before touching any code, and re-read §1 after every context compaction.**

This spec was written against the real code of both frontends and the backend's contract fixtures. Your job is to **execute it exactly**, one task at a time, not to redesign, improve or interpret it. A separate *spec-owner* session reviews each commit. The two sessions talk directly (§10).

The goal, in the user's words: "screen look and behave exactly the same and only the code underneath changing", "make sure your FE must comply to BE. and always verify response from BE side", and "when working on code changes always align to the latest BE code changes side and laravel update".

**This spec was tested, not just written.** On 2026-11-07 its riskiest parts were built and run on clones of both apps (design §1.4). Where a task gives exact code or a reference file, that exact thing has run. Use it as given.

---

## 1. Non-negotiables (break any of these and the task is rejected)

1. **One commit at a time.** Run exactly one row of §6, verify it, commit it, then **stop and report** (§9). A task that runs in both apps is two steps: POS, report, wait, then portal. Never start the next step until the spec owner or the user says "proceed".
2. **Never invent a decision.** If the spec doesn't say how to do something you need to do, **stop and ask** (§8). Don't pick "the reasonable option" or "the cleaner way".
3. **The spec beats your judgement.** If the code differs from what `design.md` describes, and an earlier step of this spec didn't legitimately change it (§5.4), stop and report drift.
4. **No scope creep.** A bug, smell or typo outside the task goes in your report under "Found, not fixed". Don't fix it.
5. **Track A changes nothing a user can see.** Markup, classes, copy, requests, query keys, invalidation sets and role outcomes stay identical. Existing test assertions don't change (Req 4.3). The render fixtures prove it, so **a Track A refactor commit that changes the render digest is wrong by definition.**
6. **The backend is the truth, it is read-only, and it moves.** Check it before every step (§2): if a stop condition hits, you stop. For any code that reads a response or sends a request, read the fixture or backend file, call the endpoint directly (design §4.10.3), and cite both in the commit body (design §4.1). A frontend type or an MSW mock is never evidence of what the API does. Never edit anything in `~/sunbites-api`.
7. **Evidence, not claims.** Never say "tests pass" without the pasted output of the command. Never say "verified" about something you didn't run or read.
8. **Don't simplify what looks redundant.** Design §3 lists 28 hazards (H1–H28) where the "obviously equivalent, simpler" version is wrong or a test will mislead you. Read them before every step.

---

## 2. Session start checklist (run every time you start or resume)

Run these for the repo your step names, and include the output in your first message:

```bash
cd ~/sunbites-api                # backend: run these three before EVERY step, not only at session start (design §4.10.1)
git branch --show-current; git rev-parse --short HEAD
vendor/bin/sail artisan --version
git status --short -- app routes bootstrap config database/migrations composer.lock   # must be empty
echo "NEXT_PUBLIC_API_URL=[$NEXT_PUBLIC_API_URL]"   # must be empty (H22)
cd ~/sunbites-pos                # or ~/sunbites-portal
git branch --show-current        # the branch §6 names for your step
git status --short               # empty; tasks 1.1 and 1.3 expect exactly package.json and package-lock.json
git log --oneline -5
node --version
```

- **Backend `git status` lists a file:** another session is changing the backend. Stop and report. Don't check against code nobody has committed.
- **The backend sha differs from your previous commit's `Backend:` line:** run `git log --oneline <previous sha>..HEAD -- app routes bootstrap config database/migrations composer.lock`. If it lists commits, run the contract suite (design §4.10.2). If the suite fails, stop: the fixtures no longer describe the API. If it passes, put the commit list in your report and wait for the spec owner to say continue (design §4.10.5).
- **All clear:** the branch, sha and version give your commit body's line, `Backend: <branch> <sha>, Laravel <version>`.
- **Tree not clean, outside tasks 1.1 and 1.3:** stop and ask. Never stash, discard or carry the user's work.

**Before creating a branch** (first step of PR 2, PR 3 and PR 4, in each repo), confirm your `origin/staging` is current:

```bash
git fetch origin
git rev-parse origin/staging
gh api repos/jvcayao/<sunbites-pos|sunbites-portal>/branches/staging --jq .commit.sha
```

The two SHAs must match. On 2026-11-07 `git fetch` failed with `Permission denied (publickey)` in a Claude session, and the POS's local `origin/staging` was `cef599c` (2026-07-01) while GitHub's was `4141382` (2026-08-05). If the fetch fails or the SHAs differ, stop and ask the user to fetch. Never branch from a stale ref.

**Before your first step in each repo only,** run `npm ci` and the gates in §5.3. Baselines are in design §1.3. In the POS, only `feedback.test.tsx` and `settle-credit-dialog.test.tsx` may fail (task 1.2 fixes them). Any other failure before you change anything: stop and report.

---

## 3. Required reading, in this order

The spec lives in `~/sunbites-api/.kiro/specs/17-frontend-code-quality/`.

| # | File | Why |
|---|---|---|
| 1 | `~/sunbites-api/.kiro/steering/product.md`, `tech.md`, `structure.md` | Users, roles, stack, layout, shared contracts |
| 2 | The app's `AGENTS.md` and `CLAUDE.md` | Next.js rule: read the bundled docs before writing Next code |
| 3 | `~/sunbites-api/.claude/rules/testing.md` | Testing standards |
| 4 | `~/sunbites-api/.claude/rules/frontend/frontend-standards.md` | Frontend standards. Where it disagrees with the spec, **the spec wins** (§5.2) |
| 5 | `~/sunbites-api/.claude/agents/react-component-expert.md` | Component guidance (Req 13.4) |
| 6 | `requirements.md` | All 14 requirements, decisions Q1–Q8, out of scope |
| 7 | `design.md` **§1 (including §1.4), §3, §4.1–§4.4, §4.10, §16, §22, §23** in full, then the sections your step cites | Decisions, what was proved, hazards, backend compliance, gates |
| 8 | `tasks.md` **ground rules** in full, then your task block | Exact sub-tasks and tests |

Re-read #7 (§3 hazards) and #8 (ground rules) at the start of **every** step.

---

## 4. Mandatory tooling (Req 13; the user marked this non-negotiable)

| When | Use |
|---|---|
| Any response or request code | Fixture or backend file first (design §4.1). Cite it in the commit body |
| Next-specific code (shells, `error.tsx`, `PageProps`, config) | The matching guide in `node_modules/next/dist/docs/` |
| TanStack Query, Zod, Zustand, Base UI APIs | That package's installed `.d.ts` or bundled docs. Never memory |
| Any step that changes components | `frontend-design:frontend-design` skill as a **guard**: confirm the look is unchanged. For FB-02 it is the **design lead** |
| Writing or changing tests | `.claude/rules/testing.md` |
| Track B (PR 2 and PR 4) | `superpowers:test-driven-development`: failing test first |
| Task 1.2 | `superpowers:systematic-debugging` |
| Before claiming any step done | `superpowers:verification-before-completion` |

- **No sub-agents and no workflows.** The final `react-component-expert` review (task 8.1) is run by the spec owner, read-only.
- **The `simplify` skill is not used.** The user rejected it for this spec (Q8).

### Reading a fixture

Slug: lowercase method, `__`, then the URI with every run of characters outside `a-z0-9{}` replaced by `_` and the ends trimmed. For example, `GET /api/v1/reports/inventory` is `get__api_v1_reports_inventory@ok.json`, and other variants follow `@`. Design §4.2 explains the shape tokens (`string:decimal2`, `__list__`, and so on). Fixtures record shape only. For enum values read the backend code, for request keys read the Form Request, and for roles read `vendor/bin/sail artisan route:list --path=api --except-vendor --json` in `~/sunbites-api` (design §4.10.4). It shows roles as `RoleMiddleware:admin|manager`, so searching it for `role:` finds nothing. To see a real response, call the endpoint (next section).

### Calling the backend directly

Design §4.10.3 has the two commands, one for staff routes and one for parent routes, to copy. They run inside a transaction that is rolled back, so nothing stays in the database. **GET routes only:** a write route can send mail or store files, which a rollback doesn't undo, so for those use the fixture and the Form Request. Compare only `message` and `errors` in an error body: the local debug fields aren't sent in production.

---

## 5. How to execute one step

### 5.1 Before writing code

1. Read the task block in `tasks.md` and every design section it cites.
2. Open and read **every file you will touch**, in full.
3. Compare the code with the design's description of it, using the **quoted code and names** (§5.4). If it differs, go to §8.
4. Read the docs in §4 for the APIs you will use. Note them for the tooling log.

### 5.2 While writing code

**Commands.** The frontends are plain Node projects: use `npm` and `npx`, not Sail. Sail only applies in `~/sunbites-api`, where you use it for the backend checks, the direct calls, and the routes.

**Verified scripts.** Design Appendix C holds the code that was run: `capture.ts`, `contract-check.mjs`, `render-fixture.ts`, `compile-report.cjs`, `css-diff.cjs` and the browser gate. Copy them where the task says, unchanged. Don't rewrite them from the design's description. Scripts that don't go into an app go in `~/spec17-gates/`, outside all three repos.

**Traps that cost real time when this was tested (design §3):**
- **Paths:** `npx jest "app/(kitchen)/x/[id]/a.test.tsx"` finds no tests, because Jest reads it as a regex. Use `npx jest --runTestsByPath "<path>"` (H23).
- **Mass test failure, about 100 tests:** `NEXT_PUBLIC_API_URL` is exported in your shell. Set it only on the `next build` line (H22).
- **Typing in a dialog test:** wait for focus first, or keystrokes vanish and the test flakes (H21).
- **Fixed dates:** `jest.useFakeTimers()` alone hangs `user-event`, `waitFor` and MSW. Use `DO_NOT_FAKE` (H10).
- **Build fails after a shell change:** `next build` type-checks tests, so fix them in the same commit (H24).
- **Money in mocks:** always decimal strings, like the API (H25).
- **Enum values come from `app/Enums/*`,** never from a frontend union, which can hide a value the backend sends. That's how an unenrolled child crashes the portal (H28, F-16).
- **Tailwind reads your tests too:** add `.render-fixtures/` and `.contract-capture/` to `.gitignore` before creating them, and let `css-diff.cjs` judge any CSS change (H27).
- **A red-first test that passes before the fix proves nothing:** check that the text you assert appears nowhere else on the screen. The first F-5 test passed before the fix because a summary card showed the same value.

**Never:**
- edit `~/sunbites-api`, including ticking `tasks.md`. The spec owner ticks boxes and edits spec docs
- run the backend test suite or any Artisan command. You don't need them, and the test database is shared with other sessions
- run `pkill` or `killall`, which could stop the user's dev servers or another session's. Start a server, keep its PID, and stop only that PID
- use `git stash`, including to get at old code. Use `git worktree`
- edit `components/ui/*` in Track A (H20), or import across repos (H16)
- edit the `AGENTS.md` managed block (Req 12.4)
- relax `onUnhandledRequest: "error"` (H18)
- re-record render fixtures in a refactor commit. `RENDER_FIXTURES=record` runs only in characterization commits (design §6.2)
- move or rename a test file that has render fixtures (design §6.2). The fixture path includes it
- change a query key's value or a mutation's invalidation set in Track A (H2, H13), even where the key looks inconsistent
- remove a `Number()` or `parseFloat` conversion (H1)
- commit `.render-fixtures/`, `.contract-capture/` or any contract fixture. Never `git add -f`
- add or bump a dependency other than `undici` (task 3.1) and `babel-plugin-react-compiler@1.0.0` (task 7). Never run `npm update`
- run two test runs at once in the same repo: `.render-fixtures/` and `.contract-capture/` are shared

**The spec wins over older standards text.** `frontend-standards.md` predates this spec, and task 10.1 updates it. It shows data fetching in a Server Component, but here the token lives in the browser, so data fetching stays client-side and `page.tsx` is a thin server shell (design §7.1). It also refers to `ErrorMessage` and `EmptyState` components that don't exist in either app. Don't create them. `contract-gaps.md` is older too: where it says a field has no effect, trust design Appendix B.1 instead.

### 5.3 After writing code (the verification gate, in this order)

Run in the app root. All must pass:

```bash
npm run type-check
npm run lint
npx jest --ci
NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1 npm run build     # on this line only (H22)
npm run test:contract                              # from task 4.1, every commit
TZ=Asia/Manila RENDER_FIXTURES=compare npx jest    # every Track A refactor commit (task 6.1 on)
```

Extra for tasks 6.1 and 7: the route table must be identical, and the server smoke must pass (design §7.1). Extra for task 7: `COMPILED_TESTS=1 TZ=Asia/Manila RENDER_FIXTURES=compare npx jest --no-cache` (design §12). Before each PR handover: `TZ=UTC npx jest --ci` and the faked-clock sweep (design §22). Before PR 3's handover: `css-diff.cjs` and `compare.mjs` (design §7.1, Appendix C.5 and C.6).

Then:
1. **Digests.**
   - Backend: run the three backend commands (§2) again just before committing. If a stop condition hits now, don't commit; report.
   - Render fixtures (from the first characterization commit), run from the app root:
     ```bash
     find .render-fixtures -type f | wc -l | tr -d ' '
     (cd .render-fixtures && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256) | shasum -a 256 | cut -c1-64
     ```
     A refactor commit's digest must equal the previous commit's.
2. **Flake gate.** Run the full suite 3 times in a row for the baselines (tasks 1.2, 1.3), for `undici` (task 3.1), and before each PR is handed over (design §22).
3. **Hazard review.** Re-read `git diff` against every hazard the task names. Write one line per hazard in your report.
4. **Size check** (tasks 6.2 and 8.3): the design §8 command prints none of the files the step covered.

**A new test failure blocks the commit.** Fix it within the task's scope. If the only fix would go outside the task, or would change behaviour, an assertion or a fixture, stop and report (§8).

### 5.4 Line numbers and earlier steps

Line numbers in `design.md` were taken on 2026-10-31, before any Spec 17 code change. After earlier steps edit a file, they drift. **Locate code by the quoted snippet or name, not by line number.**

If an earlier step of this spec moved the code (for example, task 6.1 moves a page's body to `_components/x-screen.tsx`), follow it there. That isn't drift. Drift is code that differs in a way **no earlier Spec 17 step explains**. Report that.

---

## 6. Commit order (authoritative)

Each row is one commit per app, unless it says otherwise. "Both" means POS first, then portal, stopping after each. Subjects are exact, except that `<area>`, `<file>` and similar placeholders are filled in as `tasks.md` describes.

### PR 1: Upgrade and green baseline (branches already exist)

| Task | App | Branch | Commit subject |
|---|---|---|---|
| 1.1 | POS | `bump-latest-upgrade-pos` | `chore(deps): upgrade Next 16.4.3 and React 19.3.1` |
| 1.2 | POS | same | `test: make feedback toast and settle-credit tests deterministic` |
| 1.3 | Portal | `bump-latest-upgrade-portal` | `chore(deps): refresh lockfile and upgrade Next 16.4.3 and React 19.3.1` |
| 1.4 | both | | no commit. Report. The user pushes and opens PR 1 in each repo. **Wait for the merge** |

### PR 2: Track B1 (branch `fix/spec17-contract-fixes`, from `origin/staging` after PR 1 merges)

| Task | App | Commit subject |
|---|---|---|
| 2.1 | POS | `fix(reports): F-1 show inventory snapshot rows and statuses` |
| 2.2 | POS | `fix(pos): F-3 clear the cart when the branch changes` |
| 2.3 | Portal | `fix(wallet): F-2 format wallet amounts with separators` |
| 2.4 | POS | approved 2026-11-08: `fix(reports): F-4 send is_walk_in for the customer filter` |
| 2.5 | POS | approved 2026-11-08: `fix(reports): F-5 format sales and billing money with separators` |
| 2.6 | Portal | **done as a hotfix, no commit.** Check that `grep -n unenrolled components/enrollment-status-badge.tsx` prints a line; if not, stop and report |
| 2.7 | both | no commit. Report with the design §5.4 smoke steps, which the user runs locally (design §20.1). The user opens PR 2. **Wait for the merge** |

### PR 3: Track A (branch `refactor/spec17-code-quality`, from `origin/staging` after PR 2 merges)

| Task | App | Commit subject |
|---|---|---|
| 3.1 | both | `chore(test): upgrade undici to 6.29.1 in the Jest polyfill` |
| 3.2 | both | `chore: modernise tsconfig, add build to PR CI` |
| 3.3 | both | `test: count every source file in coverage; add render fixtures` |
| 4.1 | both | `test: add local contract check against backend fixtures` |
| 4.2 | both | `test: shape MSW data like the backend fixtures` |
| 4.3 | both | **no commit, no code change.** Report the Appendix B tables. **Wait:** the spec owner gets the user's decision on each proposed F-item |
| 4.4 | both | `refactor(types): match API response types to the backend` (only the rows the spec owner marks "A") |
| 5.1–5.6 | POS | `test(<area>): characterize <area> before refactor` |
| 5.7–5.9 | Portal | `test(<area>): characterize <area> before refactor` |
| 6.1 | both | `refactor(app): thin page shells and auth hydration hook` |
| 6.2.a–f | POS | `refactor(<area>): split <file> into components` |
| 6.2.g | Portal | `refactor(<area>): split <file> into components` |
| 6.3 | both, per area | `refactor(<area>): one copy of <helper\|component>` |
| 6.4 | both, per domain group | `refactor(queries): define <domain> query keys and options once` |
| 6.5 | POS | `refactor(auth): route UI role checks through one capability map` |
| 6.6 | both | `refactor(validation): Zod 4 APIs and shared schemas` |
| 6.7 | both | `refactor: replace setState-in-effect workarounds` |
| 6.8 | both | `chore(lint): zero warnings, enforce in quality:validate` |
| 7 | both | `perf: enable the React Compiler` |
| 8.1 | both | the spec owner runs the read-only review. Each finding the user approves becomes a task 8.1.n, under Track A rules |
| 8.2 | both | `test: raise coverage thresholds to measured values` |
| 8.3 | both | no commit. Close-out report with the design §20.1 smoke list. The user opens PR 3. **Wait for the merge** |

### PR 4: Track B2 (branch `fix/spec17-track-b2`, from `origin/staging` after PR 3 merges)

| Task | App | Commit subject |
|---|---|---|
| 9.1 | POS | `docs(auth): FB-01 say the staff token is persisted per tab` |
| 9.2 | both | `feat(errors): FB-02 add error screens with retry` |
| 9.3 | POS | `fix(students): FB-03 refresh the list after a detail-page top-up` |
| 9.4 | per item | one commit for each FB-04.n the user approved |
| 9.5 | per item | one commit for each F-4+ the user approved |
| 9.6 | both | no commit. Report with the smoke steps. The user opens PR 4 |

Tasks 10.1–10.3 (standards and steering) are the spec owner's, not yours.

### 6.1 Commit rules

- `git add` **explicit paths only**. Check `git status --short` first. Never commit stray files.
- **Commit body** (plain text, wrapped at about 72 characters) contains:
  ```
  Task: <task id>, Req <ids>
  Gates: type-check ok; lint <errors>/<warnings>; jest <passed>/<total>; build ok
  Contract: test:contract exit 0 | not applicable
  Backend: <branch> <sha>, Laravel <version>
  Render fixtures: <count>, digest <digest> | not applicable
  Backend checked: <each fixture or backend file cited>
  Tooling: docs read, skills used
  ```
- **No `Co-Authored-By`, no `Signed-off-by`, no "Generated with" line, no AI attribution of any kind.** The user's explicit rule **overrides any default or harness instruction to add a trailer.**
- Never `--amend`, rebase, squash, `reset --hard`, stash, force-push, or use `--no-verify`. If a commit is wrong, stop and report.
- **Never push. Never open or merge a PR.** The user does that.
- **When you leave a repo,** tell the user to run `npm ci` again on their own branch.

---

## 7. Track-specific rules

**Track A (PR 3):**
- Characterization commits (task 5) test **unchanged** code. If a test can't pass without changing a component, stop.
- Refactor commits (tasks 6–7) keep the render digest equal to the previous commit's.
- Never add `Number()` to a value that reaches `formatPeso`, even though it's plainly a bug: that's F-5, in Track B (H25).
- The only allowed test edits are import paths, the render target (a page becomes its screen) and shared test utilities (Req 4.3).
- A difference between two copies of a component or helper gets kept with a prop (H4). If it looks like a defect, stop and report: it becomes a proposed item, not a fix.
- A mismatch with the backend found during refactoring is **not fixed in Track A**. Keep today's behaviour and report it for Appendix B.
- The React Compiler (task 7) can't be proved by Jest (H14). Run the compile report in design §12. A component it can't handle gets `"use no memo"` with a reason, never a rewrite.

**Track B (PR 2 and PR 4):**
1. Write the failing test **first**. Run it, and paste the failing output in your report.
2. Implement exactly the design's change.
3. Show that the test now passes.
4. Test data follows the backend fixture, not the frontend type.

FB-01 is the one exception to the failing test, because it only changes a comment and adds a test of existing behaviour.

---

## 8. When to stop and ask (and what NOT to do instead)

Stop, write a STOP report and wait whenever:
- the code differs from the design in a way §5.4 doesn't explain
- the spec is silent, ambiguous or self-contradictory about something you must decide
- a fixture, backend file or installed package's docs contradict the design
- the backend has uncommitted changes, or the contract suite fails (§2)
- `npm run test:contract` reports a failure that isn't in design Appendix B.6 and that your step didn't introduce
- the `Route (app)` table changes, or the server smoke gets a new status or error
- `css-diff.cjs` or `compare.mjs` exits 1
- the only way to pass a test is to change behaviour, an assertion or a render fixture
- you would need a new dependency, directory or file location the spec doesn't name
- you find a bug the spec doesn't cover (report it; don't fix it)
- you are unsure whether something counts as a behaviour change

| Situation | Do | Don't |
|---|---|---|
| Today's code has a bug and the spec says keep the behaviour | Keep it; add it to "Found, not fixed" | Fix it |
| The frontend type disagrees with the fixture | Trust the fixture; report it if the task doesn't cover it | Trust the type |
| A mock disagrees with the fixture and fixing it changes an assertion | Stop; list it as a proposed F-item (task 4.2) | Edit the assertion |
| A test you didn't write fails after your change | Find what you broke and fix your code | Edit the test |
| A render fixture differs after a refactor | Find what changed and fix your code | Re-record it |
| A component would be cleaner with different markup | Keep the markup | Change it |
| A name or helper the spec doesn't mention seems needed | Stop | Invent one |
| Context is getting long | Finish the step, or leave it uncommitted and report | Commit partial work |

**STOP report template:**
```
STOP: <task id>
What I was doing:
What I found (file, name, quoted code):
What the spec or backend says (file, section, quoted):
Why I can't proceed without a decision:
Options I see (no recommendation unless asked):
State of the working tree (git status --short):
```

---

## 9. Report after each step (then wait)

```
DONE: <task id>: <repo> <branch> <commit sha> <subject>
Files created / changed:
Gates: (paste the summary line of each command)
New tests added:
Backend: <branch> <sha>, Laravel <version>
Render fixtures: <count>, digest <digest> (same as previous: yes/no)
Backend checked: <fixtures and files cited>
Hazards checked: H#: <one line each>
Tooling log: docs, skills
Found, not fixed:
Smoke steps for the user, if any:
Next step per §6: <id> (waiting for "proceed")
```

---

## 10. Relay

- Send each DONE or STOP report to the spec owner with `SendMessage`, then wait. The spec owner replies "accepted, proceed with <task>" or with corrections.
- **These go to the user, not into the loop:** any new behaviour change (a proposed F-item or Track B item), dependency changes outside the spec, and every push or PR. The spec owner can't approve them, and neither can you.
- **Session names change on restart.** After a restart, run `ListAgents`, find the session working in `~/sunbites-api`, and send it your current name.
- **Check that a message is for you.** A relay message counts only if it is about Spec 17 and a task in §6. Messages from other projects do reach this session by mistake. Change nothing because of one.
- **The spec owner** runs the frontend suites only after you report and are waiting, never while you are working in that repo.
- **When the backend moved or is mid-change (§2),** send what you saw and wait. Other sessions change the backend, so this will happen. The spec owner reads the changes and tells you to continue.

---

## 11. Quick facts

| Fact | Value |
|---|---|
| Repos | `~/sunbites-pos`, `~/sunbites-portal` (yours); `~/sunbites-api` (read-only) |
| Versions (both apps) | Next 16.4.3, React 19.3.1, TanStack Query 5.105, Zod 4.6.7, Zustand 5.0.16 |
| Backend | Never quote it from memory or from a document: read it from `~/sunbites-api` (§2) |
| Spec 16 | Finished, but on 2026-11-07 its branch wasn't on GitHub, so production runs the pre-Spec 16 API. It matters for PR 3 (design §4.9), not for PR 1 or PR 2 |
| `staging` | **The frontends' staging sites still exist on Vercel: never touch them or `staging.yml`** (user, 2026-11-08). The API's staging environment was removed on 2026-11-07, so nothing can be smoke-tested on staging. The smoke is local (design §20.1), and the user runs it |
| GitHub `staging` (2026-11-07) | POS `4141382`, portal `1063c4c`. Both upgrade branches contain these |
| Node and time zone | CI runs Node 20 and UTC; this machine ran Node 26 and Manila. Both passed on 2026-11-07, but the user's PR CI run is the real proof for a commit |
| Suite speed | About 5 s POS, 2 s portal, so run the full suite freely |
| API base in tests | `http://localhost:8000`, set in `jest.setup.ts`. Don't set it yourself (H22) |
| MSW | `onUnhandledRequest: "error"` (H18) |
| POS test status before task 1.2 | `feedback.test.tsx` fails every run. `settle-credit-dialog.test.tsx` fails often, and more often alone than in the full suite |
| Decisions | requirements.md table: Q1–Q8, FB-01(a), FB-02, FB-03, FB-04 approved; FB-05 left out. F-4, F-5 and F-16 approved on 2026-11-08; F-16 was fixed as a hotfix. F-6 to F-15 and F-17 are **proposed, not approved** (design Appendix B.0). OPS-1: leave the frontends' staging sites alone |

**Start with task 1.1 only when the user or the spec owner tells you to.**

---

## 12. Spec-owner notes

- **Review each commit:** read the diff, re-run the gates while the implementer waits, tick `tasks.md`, and reply. Check `git diff <path>` and use a pathspec commit for spec docs (design §16).
- **When the implementer reports backend commits:** follow design §4.10.5. Read each listed commit, call the affected endpoints directly (§4.10.3), update the design and Appendix B, ask the user about any visible effect, then tell the implementer to continue. The contract suite uses SQLite in memory, so it's safe while another session runs the MySQL gate.
- **After Spec 16 merges:** the branch name in the `Backend:` line changes. That's expected; read the commits it brings like any other backend change (design §4.10.5).
- **No backend snapshot and no script files live in this repo** (user, 2026-11-08; API-only rule). The backend is checked directly, and the verified scripts are in design Appendix C.
- **F-16 hotfix** (`81c204e`, design §5.6) is committed, not pushed. The user pushes it, opens the PR to `main`, merges `main` back into `staging`, and then the worktree is removed. **After task 4.3:** update Appendix B, and get the user's decision on any new F-item before task 4.4. **Before PR 4 starts:** get decisions on F-9 to F-15 and F-17. F-6 to F-8 need backend work, so they're the user's call to raise separately.
- **After tasks 6.3–6.4:** take the refresh gaps recorded in Appendix B §B.5 to the user (FB-04).
- **Task 8.1:** run the `react-component-expert` agent read-only over each app's Track A diff. The user decides on each finding.
- **Tasks 10.1–10.3:** the user approves the exact text first. Steering versions change once PR 1 is merged, and D1 once FB-01 is merged.
- **Spec 16 ordering:** before any frontend deploy to production that includes a commit relying on design §4.9, confirm Spec 16 is deployed to production. Tell the user at PR 1 that this dependency exists.
- **OPS-1, decided 2026-11-08:** leave the frontends' staging sites and `staging.yml` deploy jobs alone. They still exist on Vercel (user). Don't propose removing them again.
- **PR descriptions:** list any exceptions in `__tests__/contract/exceptions.json`, the design §20 smoke list and any rejected review findings. No AI attribution.
