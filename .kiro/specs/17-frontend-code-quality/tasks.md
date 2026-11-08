# Spec 17: Frontend Code Quality and Backend Compliance · Implementation Plan

## Ground rules (read first, every session)

- **Read, in order:**
  1. `.kiro/steering/` (product, tech, structure);
  2. `requirements.md`;
  3. `design.md` (**§3 hazards before every task**);
  4. `HANDOFF.md`;
  5. the app's `AGENTS.md`.
- **One task at a time.** Run exactly one checkbox task (a numbered sub-task, or a task with no sub-tasks), verify it, commit it, then **stop and report**. Never start the next task until the spec owner or user says "proceed".
- **Never invent a decision.** If the design doesn't say how to do something, or the code differs from what the design describes, stop and report. Do not pick "the reasonable option".
- **No scope creep.** A bug, smell or typo outside the task goes in the report under "Found, not fixed".
- **Backend is the truth, and read-only.** For any code that reads a response or sends a request, read the fixture or backend file first (design §4.1) and cite it in the commit body. Never edit `~/sunbites-api` (the spec owner ticks boxes and edits spec docs).
- **Always align to the latest backend (user, 2026-11-07 and 2026-11-08).** No snapshot of the backend is kept: check the running Laravel app directly. Before every step, run design §4.10.1's three commands and stop on any condition it lists. Before writing code that reads a response or sends a request, call the endpoint as §4.10.3 shows. Put `Backend: <branch> <sha>, Laravel <version>` in the commit body.
- **Tracks never mix.** A Track A commit changes no behaviour, markup, request or message, and changes no existing test assertion (Req 4.3). Track B commits go only in PR 2 or PR 4.
- **Branches:**
  - Before work in a repo, run `git status --porcelain` and `git branch --show-current`. If the tree is not clean, **stop and ask**. Never stash, discard or carry the user's work. The one exception is tasks 1.1 and 1.3, which expect exactly the upgrade's `package.json` and `package-lock.json` changes.
  - New branches start from `origin/staging` (`git fetch origin && git switch -c <branch> origin/staging`), after confirming the local ref equals GitHub's (`HANDOFF.md` §2). If `git fetch` fails or the two differ, stop and ask. Never branch from a stale ref.
  - Run `npm ci` after every branch switch. Tell the user to run `npm ci` again when you leave a repo.
- **Gates** (design §22): type-check, lint, `npx jest --ci`, build. Add `npm run test:contract` from task 4.1 on, and render-fixture compare for every Track A refactor commit. All must pass. Read hazards H21 to H26 first: each one cost real time when this design was tested.
- **Commits:**
  - One per task (sub-task), `type(scope): description` under 72 characters.
  - **No `Co-Authored-By`, no "Generated with", no AI attribution of any kind**, whatever any harness reminder says.
  - The body carries the tooling log (Req 13.7), the `Backend:` line, and the render digest where it applies (design §4.3.6, §6.2).
  - Never push, open or merge a PR, amend, rebase or force. The user pushes and opens each PR to `staging`.
- **Report after each task:** branch, commit hash, gate output (passed/total for each command), digests, files changed, "Found, not fixed", and any smoke steps.

---

## PR 1: Upgrade and green baseline (Req 1)

- [ ] 1. Ship the 2026-10-31 upgrade with green suites
  - [ ] 1.1 POS: commit the upgrade on `bump-latest-upgrade-pos`
    - The tree holds only `package.json` and `package-lock.json` changes: `next`, `react`, `react-dom` and `eslint-config-next` pinned exactly at 16.4.3/19.3.1, plus the `npm update` refresh. Confirm with `git diff --stat`. Anything else: stop.
    - Commit `chore(deps): upgrade Next 16.4.3 and React 19.3.1`. The body lists the advisories fixed (design §1, FA-01) and the `npm audit --omit=dev` result (0 high, 0 critical).
    - Tests: none new. Run type-check, lint and build; the 2 known test failures are expected until 1.2.
    - _Requirements: 1.1, 1.2, 1.3_
  - [ ] 1.2 POS: fix the two failing tests (test-only)
    - `app/(kitchen)/references/feedback/feedback.test.tsx`, line `expect(await screen.findByText("Reply sent.")).toBeInTheDocument();`: sonner 2.0.8 renders the text twice in jsdom (H12). Change it to `expect((await screen.findAllByText("Reply sent."))[0]).toBeInTheDocument();`. Verified.
    - `app/(kitchen)/students/[id]/_components/settle-credit-dialog.test.tsx`: Base UI moves focus just after the dialog opens and steals keystrokes (H21). Add this helper just below the `const API = …` line (`waitFor` is already imported), exactly as Prettier formats it. Then add `await focusSettled();` on the line before each of the 5 `await user.clear(amount);` lines. Proven: 8 of 12 failed before, 15 of 15 passed after; re-proven on 2026-11-08 with the upgrade (15 of 15 solo, 325/325 three runs).
      ```ts
      const focusSettled = () =>
        waitFor(() => expect(document.activeElement).not.toBe(document.body));
      ```
    - No component changes. Neither cause is visible to users, because nobody can type inside that first tick (Req 1.4). IF the tests still fail after this THEN stop and report.
    - Tests: the settle-credit file passes 15 solo runs with `--runTestsByPath` (it fails more often alone than in the suite), and the full suite passes 3 consecutive runs (Req 1.5).
    - Commit `test: make feedback toast and settle-credit tests deterministic`.
    - _Requirements: 1.4, 1.5_
  - [ ] 1.3 Portal: commit the upgrade on `bump-latest-upgrade-portal`
    - Same check as 1.1 (`package.json` + `package-lock.json` only). Commit `chore(deps): refresh lockfile and upgrade Next 16.4.3 and React 19.3.1`.
    - Tests: all gates; the full suite passes 3 consecutive runs.
    - _Requirements: 1.1, 1.2, 1.3, 1.5_
  - [ ] 1.4 Report both commits. The user pushes and opens PR 1 in each repo.

## PR 2: Track B1 contract fixes (Req 3; design §5)

- [ ] 2. Fix F-1, F-2, F-3, F-4, F-5 and F-16 (after PR 1 merges)
  - [ ] 2.1 F-1: POS inventory report (branch `fix/spec17-contract-fixes` from `origin/staging`)
    - Re-verify design §5.1 against `get__api_v1_reports_inventory@ok.json` and `InventoryItem::status`, and cite both in the commit body.
    - Write `app/(kitchen)/reports/inventory/page.test.tsx` by copying design Appendix D.1 (7 tests). Run it, and confirm tests 1, 2, 3, 6 and 7 fail and 4 and 5 pass. IF the result differs THEN stop and report.
    - Apply the design §5.1 edits to `lib/api/reports.ts` and `page.tsx`. Leave `dashboard/page.tsx` unchanged.
    - Tests: the new file passes; all gates pass.
    - Commit `fix(reports): F-1 show inventory snapshot rows and statuses`.
    - _Requirements: 3.1, 3.4, 3.5, 2.3_
  - [ ] 2.2 F-3: clear the POS cart on a branch switch (same branch)
    - Extend `components/branch-cache-sync.test.tsx` with design Appendix D.2 and confirm the two emptying tests fail and the null → B test passes.
    - Apply the design §5.3 edit to `components/branch-cache-sync.tsx`.
    - Tests: the extended file passes; all gates pass.
    - Commit `fix(pos): F-3 clear the cart when the branch changes`.
    - _Requirements: 3.3, 3.4_
  - [ ] 2.3 F-2: portal wallet amounts (branch `fix/spec17-contract-fixes` in the portal)
    - Re-verify against `get__api_v1_portal_students_{student}_wallet@ok.json`.
    - Update `wallet-tab.test.tsx` (design §5.2) and confirm the `+PHP 1,500.00` assertion fails.
    - Apply the design §5.2 edits to `types/portal.ts` and `wallet-tab.tsx`. Leave `lib/format.ts` unchanged.
    - Tests: all gates pass.
    - Commit `fix(wallet): F-2 format wallet amounts with separators`.
    - _Requirements: 3.2, 3.4, 3.5, 2.2_
  - [ ] 2.4 F-4, approved 2026-11-08: Sales report Customer filter (POS; design §5.5)
    - Write `app/(kitchen)/reports/sales/page.test.tsx` by copying design Appendix D.3 **without** the test `shows order money with thousands separators (F-5)`, which task 2.5 adds; otherwise the F-4 commit would carry a failing test. Format the new file with Prettier. Confirm the Walk-In and Registered tests fail and "All Customers" passes. Apply the edit in both places (in `exportReport` the old key spans two lines; design §5.5). Don't run Prettier on this file (design §22).
    - Tests: Walk-In sends `is_walk_in=1`, Registered sends `0`, All Customers sends neither, `customer_type` is never sent. All gates pass.
    - Commit `fix(reports): F-4 send is_walk_in for the customer filter`.
    - _Requirements: 3.6, 2.3_
  - [ ] 2.5 F-5, approved 2026-11-08: Sales and Billing money format (POS; design §5.5)
    - Add the `(F-5)` test from design Appendix D.3 to the Sales test file, as the first test in `describe("SalesReportPage")`, exactly where D.3 has it. Add `app/(kitchen)/reports/billing/page.test.tsx` by copying design Appendix D.4. Confirm both money tests fail. They use values no summary card shows (design §5.5), because a test using a summary value passes before the fix. Apply the edits.
    - Tests: `-₱1,250.00` and `₱2,750.50` in Sales, and a ₱1,000+ amount in Billing. All gates pass.
    - Commit `fix(reports): F-5 format sales and billing money with separators`.
    - _Requirements: 3.6, 2.2_
  - [ ] 2.6 F-16, approved 2026-11-08: show an unenrolled student instead of crashing (portal, same branch as 2.3; design §5.6)
    - Re-verify that the backend's `app/Enums/EnrollmentStatus.php` still has exactly `enrolled`, `paused`, `unenrolled`, `banned` and `graduated`. IF it has changed THEN stop and report.
    - Write `components/enrollment-status-badge.test.tsx` and add the unenrolled case to `app/(portal)/students/page.test.tsx`, copying the code in design §5.6. Confirm the two unenrolled cases fail with `Cannot read properties of undefined (reading 'className')`, and `npm run type-check` fails with TS2820. IF anything else fails THEN stop and report.
    - Apply the design §5.6 edits to `types/portal.ts` (the two-line, Prettier-formatted form) and `components/enrollment-status-badge.tsx`.
    - Tests: the new tests pass; all gates pass, including `npm run format:check`. The suite had 108 tests in the 2026-11-08 rehearsal.
    - Commit `fix(portal): F-16 show unenrolled students instead of crashing`. Write it new: never push, merge or cherry-pick the local branch `hotfix/portal-unenrolled-child-crash`.
    - _Requirements: 3.6, 2.2_
  - [ ] 2.7 Report the commits and the design §5.4 smoke steps, which the user runs locally (design §20.1). The user opens PR 2 in each repo.

## PR 3: Track A (after PR 2 merges; branch `refactor/spec17-code-quality` in each repo)

Unless a sub-task names one app, it runs in both: POS first, then portal, one commit per app. Each app's commit is its own stop-and-report step.

- [ ] 3. Foundations
  - [ ] 3.1 Upgrade `undici` in the Jest polyfill (design §13.4, D-5)
    - devDependency `undici` `^5.29.0` → `^6.29.1`. `jest.polyfills.ts` stays unchanged. Verified on Node 26 and Node 20.
    - IF MSW or any test fails on undici 6 THEN stop and report.
    - Tests: the full suite passes 3 consecutive runs; full `npm audit` reports no high or critical.
    - Commit `chore(test): upgrade undici to 6.29.1 in the Jest polyfill`.
    - _Requirements: 11.4_
  - [ ] 3.2 TypeScript, ESLint and CI (design §13.1–§13.3, D-8)
    - `tsconfig.json` options per design §13.2. `"type-check": "next typegen && tsc --noEmit"`. Portal `eslint.config.mjs` ignores `coverage/**`. `pr.yml` gains the Build step.
    - Tests: type-check, lint and build pass; the suite passes unchanged.
    - Commit `chore: modernise tsconfig, add build to PR CI`.
    - _Requirements: 11.1, 11.2, 11.3_
  - [ ] 3.3 Coverage and render-fixture tooling (design §6.2, §6.3)
    - `collectCoverageFrom` per design §6.3. Run `npm run test:coverage`, and re-baseline the thresholds once to the new measurement, rounded down (record the old and new numbers in the commit body).
    - Add `.render-fixtures/` to `.gitignore` first (H27). Copy design Appendix C.3 to `__tests__/render-fixture.ts`. Add `__tests__/clock.ts` and the `fakeTimers` switch in `jest.config.ts` (design §6.2).
    - Tests: unit-test `normaliseRenderedHtml` in `__tests__/render-fixture.test.ts`: ids and every referencing attribute; `data-starting-style`, `data-ending-style`, `data-rootownerid`; `transition: none` and an emptied `style`; `blob:`; and that classes and other attributes stay untouched. Also: no-op when `RENDER_FIXTURES` is unset, and the missing-fixture message in compare mode.
    - Commit `test: count every source file in coverage; add render fixtures`.
    - _Requirements: 4.4, 4.5_

- [ ] 4. Backend compliance (design §4)
  - [ ] 4.1 Contract check tooling (design §4.3)
    - Copy design Appendix C.1 to `__tests__/contract/capture.ts` (imported from `jest.setup.ts`) and Appendix C.2 to `scripts/contract-check.mjs`. Sail must be up in `~/sunbites-api`: the check reads the routes from Laravel. Add the capture `globalSetup`, `__tests__/contract/exceptions.json` (empty `[]`), the `test:contract` script, and `.contract-capture/` in `.gitignore`.
    - Tests:
      - `__tests__/contract/contract-check.test.ts`, one test per K-rule against small inline fixtures, including that K4, K7 and K8 don't fail the check;
      - one each for the CI skip (exit 0) and the local missing-directory failure (exit 1);
      - one for a stale exception (exit 1);
      - one that `/students/5/nonexistent` is K1 and `/students/deleted` picks the literal route over `students/{student}`;
      - one that a generic 404 with no recorded variant passes when the body has `message`.
    - Commit `test: add local contract check against backend fixtures`. The body carries the backend fixture line (§4.3.6).
    - _Requirements: 2.4, 2.5_
  - [ ] 4.2 Typed mocks and first contract run (design §4.3.4, §4.4)
    - Add `satisfies <FEType>` to every JSON body in `__tests__/mocks/handlers.ts` and every per-test `server.use`. Run `npm run test:contract`.
    - Design Appendix B.6 lists every expected failure and its resolution (POS 31, portal 11 after F-2). Work through that table. A failure that isn't in it: stop and report.
    - For each finding:
      - fix the mock data, only if no existing assertion changes;
      - or add the exception B.6 names, with its `backendRef`;
      - or, if fixing the data would change an assertion, stop and list it as a proposed F-item.
    - Tests: the suite passes with unchanged assertions; `test:contract` exits 0.
    - Commit `test: shape MSW data like the backend fixtures`. The body lists every finding and how it was resolved.
    - _Requirements: 2.2, 2.4, 2.5, 2.8_
  - [ ] 4.3 Compliance audit (read-only; produces the report)
    - Most of design Appendix B was filled in by the 2026-11-07 audit. This task checks it against today's backend and adds what the audit couldn't see:
      - B.1 to B.4: confirm against the current backend, by reading the code and calling GET endpoints directly (design §4.10.3). Report any row that's wrong, with `file:line`. Don't re-derive them from scratch;
      - B.2: trace the one opaque call, `preRegistrationApi.update` (`pre-registrations/[id]/page.tsx:298`), against `UpdatePreRegistrationRequest`;
      - error bodies (design §4.8): each reader of `ApiError.message`.
    - Report to the spec owner, who updates Appendix B and asks the user about any new F-item.
    - No commit in the app repos. No code changes.
    - _Requirements: 2.1, 2.2, 2.3, 2.6, 2.7, 2.8, 2.11_
  - [ ] 4.4 Runtime-neutral type corrections (only the Appendix B rows the spec owner marks "A")
    - Correct `types/*.ts` and `lib/api/*.ts` per design §4.5. A forced explicit conversion must give identical output (cite the code path). Includes `ApiError` (`message?`, portal `error` literals) where Appendix B marks it A.
    - Tests: the suite passes with unchanged assertions; `test:contract` exits 0; render fixtures are unchanged (compare mode, for any screen already characterized).
    - Commit `refactor(types): match API response types to the backend`. The body lists each row and the fixture cited.
    - _Requirements: 2.2, 2.7, 2.8_

- [ ] 5. Characterization tests and render fixtures (design §6.1; one commit per sub-task)
  - Each sub-task:
    - writes or extends the screen test files listed;
    - loads every Appendix A file in its area;
    - covers the states, requests, actions and roles in design §6.1;
    - calls `expectRenderFixture` at each settled state;
    - records fixtures (`TZ=Asia/Manila RENDER_FIXTURES=record`).
  - Tests must pass on **unchanged** code. Commit `test(<area>): characterize <area> before refactor`, with the render digest.
  - [ ] 5.1 POS students: list (`students/page.tsx`, including batch QR print and deleted students) and detail (`students/[id]/page.tsx`, every dialog and tab, the camera modal mocked at `getUserMedia`)
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.2 POS enrollment and pre-registrations (`enrollment`, `pre-registrations`, `pre-registrations/[id]`)
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.3 POS references (inventory, users list, create and detail, branches, meal-planner, parents list and detail, subscription-config, system-settings, feedback)
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.4 POS reports (activity, analytics and its `components/analytics/*`, billing, credits, daily-summary, inventory, sales, students, subscription, wallet and `wallet-history-panel`) and each `loading.tsx`
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.5 POS checkout (`(pos)/pos/page.tsx`, `components/pos/*`: menu grid, cart panel, student search, menu management, inventory tab, transaction history, receipt, void modal; `hooks/use-pos-keyboard-shortcuts.ts`)
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.6 POS shell and the rest:
    - `(kitchen)` and `(pos)` layouts (design §7.2 cases), `(kiosk)` layout and kiosk;
    - dashboard, notifications, announcements (list, create, detail), reminders (list and `[parentId]`);
    - `(auth)` login, branch, reset-password;
    - navigation, `reminder-bell`, `echo-provider`, `providers`, root layout and page;
    - `hooks/*` and `lib/api/*` in Appendix A.
    - _Requirements: 4.1, 4.2, 4.4, 4.6, 5.3_
  - [ ] 5.7 Portal auth (login, activate, forgot-password, reset-password) and the `(portal)` layout
    - _Requirements: 4.1, 4.2, 4.4, 4.6, 5.3_
  - [ ] 5.8 Portal dashboard, students list, student detail (shell, header, QR actions, all four tabs)
    - _Requirements: 4.1, 4.2, 4.4, 4.6_
  - [ ] 5.9 Portal feedback, profile, notifications, meal plan, public pre-registration (form and `lib/validation/pre-registration.ts` messages, reCAPTCHA provider mocked), `echo-provider`, `providers`, root layout and page
    - _Requirements: 4.1, 4.2, 4.4, 4.6_

- [ ] 6. Refactor (each commit: all gates, render fixtures compare unchanged, digest equal to the previous commit)
  - [ ] 6.1 Route shells and auth hydration (design §7.1, §7.2)
    - The 38 POS and 9 portal `page.tsx` that start with `"use client"` become server shells rendering `XScreen` from `_components/`. The other 6 stay as they are (design §7.1). The 7 dynamic routes follow the table in design §7.1 exactly. Fix relative imports. Add `lib/store/use-auth-hydrated.ts`, and use it in the `(kitchen)`, `(pos)` and `(portal)` layouts.
    - Before the commit, save the `Route (app)` table from `next build`. After it, the table must be identical, and the server smoke (design §7.1) must pass.
    - Tests: unit-test `useAuthHydrated`: `renderToString` gives empty HTML, and `hydrateRoot` gives no mismatch and then shows the children (set `IS_REACT_ACT_ENVIRONMENT`). Static-route tests don't change. Dynamic-route tests switch their render target to the screen in the same commit, because `next build` type-checks them (H24), and their assertions don't change.
    - Commit per app `refactor(app): thin page shells and auth hydration hook`.
    - _Requirements: 5.1, 5.2, 5.3, 9.3_
  - [ ] 6.2 Split files over 400 lines (design §8; verbatim moves only)
    - One commit per sub-task:
      - [ ] 6.2.a POS `students/[id]` (design §8 map);
      - [ ] 6.2.b POS `students` list;
      - [ ] 6.2.c POS enrollment and pre-registrations;
      - [ ] 6.2.d POS references;
      - [ ] 6.2.e POS reports and `lib/api/reports.ts`;
      - [ ] 6.2.f POS checkout components, `notification-bell`, notifications, reminders, dashboard;
      - [ ] 6.2.g portal `pre-registration-form`, notifications page, `notification-bell`.
    - Tests: existing tests unchanged; render fixtures unchanged; the size check (design §8) prints none of the files the sub-task covered.
    - Commit `refactor(<area>): split <file> into components`.
    - _Requirements: 6.1, 6.2, 6.3, 6.4_
  - [ ] 6.3 One copy per helper and component (design §9)
    - POS `lib/format.ts`; the shared components (identical copies merged; same-named different components renamed); `WalletTopUpModal` and `StatusPickerDialog` merged with per-caller props (H4, H13); `lib/api/photos.ts` (H15). Portal `groupByDate` and `NotificationDetailSheet`.
    - Before each merge, diff the copies. The commit body records each difference and how it was kept. A difference that looks like a defect: stop and report (FB-04 / new F-item).
    - Tests: unit tests for `lib/format.ts` (null, 0, negatives, thousands, every copy's former call pattern) and `fetchAuthorizedImageUrl` (headers sent, object URL returned); render fixtures unchanged.
    - Commit per area `refactor(<area>): one copy of <helper|component>`.
    - _Requirements: 7.1, 7.2, 7.3, 7.4_
  - [ ] 6.4 Data layer (design §10)
    - `lib/queries/{domain}.ts` per the design §10.1 table; every `useQuery`, `invalidateQueries`, `setQueryData` and `removeQueries` uses the factories; shared mutations in `hooks/`. One commit per domain group.
    - The commit body carries the before/after key and invalidation table (design §10.2), and it must be identical.
    - Any mutation found that leaves another screen stale is recorded for Appendix B §B.5 (FB-04), not fixed.
    - Tests: a unit test per domain file asserting each key factory equals today's literal; characterization request counts unchanged.
    - Commit `refactor(queries): define <domain> query keys and options once`.
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_
  - [ ] 6.5 POS role gates through `lib/permissions.ts` (design §4.7)
    - One capability per distinct gate, each citing its backend route and `role:`. Today's role lists are kept even where Appendix B §B.3 marks a mismatch (those are F-items for PR 4).
    - Tests: `lib/permissions.test.ts` covering every capability × admin, manager, supervisor, cashier, plus one multi-role user; role characterization tests unchanged.
    - Commit `refactor(auth): route UI role checks through one capability map`.
    - _Requirements: 2.6, 7.2_
  - [ ] 6.6 Zod 4 APIs and schema location (design §11.1)
    - `z.flattenError`, `z.email`, `error`; schemas to `lib/validation/{domain}.ts`.
    - Tests: unit tests per validation file asserting the exact messages for each invalid input the characterization tests use, and that previously valid inputs still pass.
    - Commit per app `refactor(validation): Zod 4 APIs and shared schemas`.
    - _Requirements: 9.1, 9.2_
  - [ ] 6.7 Remaining effect workarounds (design §7.3)
    - The QR print root via `createPortal`; the portal wallet alert input derived; `lib/echo-store.ts` in both apps replacing the `setEcho` effect.
    - Tests: an echo store unit test (connect, disconnect and token change, subscribers notified); render fixtures unchanged (the print root DOM included).
    - Commit `refactor: replace setState-in-effect workarounds`.
    - _Requirements: 9.3_
  - [ ] 6.8 Lint to zero (design §11.2)
    - Reasoned single-line disables for the `<img>` cases and `client.ts:56`. `quality:validate` uses `eslint --max-warnings 0`.
    - Tests: `npm run quality:validate` passes; suite unchanged.
    - Commit `chore(lint): zero warnings, enforce in quality:validate`.
    - _Requirements: 9.4, 11.1_

- [ ] 7. React Compiler, last in Track A (design §12; one commit per app)
  - Install `babel-plugin-react-compiler@1.0.0` exactly; set `reactCompiler` per D-6. Add the `COMPILED_TESTS` switch to `jest.config.ts` (design §12). In the POS, rename the two local types in `enrollment.test.tsx` (H26). Save design Appendix C.4 as `~/spec17-gates/compile-report.cjs` and run `node ~/spec17-gates/compile-report.cjs` from the app root. Remove manual `useCallback`/`useMemo` only in components reported as compiled; use `"use no memo"` with a reason for any component that misbehaves.
  - Tests: all gates; `COMPILED_TESTS=1 TZ=Asia/Manila RENDER_FIXTURES=compare npx jest --no-cache` passes with fixtures unchanged; route table identical; server smoke passes. The commit body carries the compile report summary (compiled / skipped / failed counts) and the full-stack smoke items to run (design §20.1).
  - Commit `perf: enable the React Compiler`.
  - _Requirements: 10.1, 10.2, 10.3, 10.4_

- [ ] 8. Final review and close-out of Track A
  - [ ] 8.1 Read-only review (spec owner runs the `react-component-expert` agent over each app's Track A diff; Q8)
    - Findings are reported to the user. Each approved finding becomes sub-task 8.1.n under Requirement 4 rules (tests unchanged, render fixtures unchanged).
    - _Requirements: 13.5_
  - [ ] 8.2 Raise coverage thresholds to the measured values (never lower)
    - Tests: `npm run test:coverage` passes at the new thresholds.
    - Commit `test: raise coverage thresholds to measured values`.
    - _Requirements: 4.5_
  - [ ] 8.3 Close-out check and handover
    - Size check (design §8) prints nothing. Full gates. The suite passes 3 consecutive runs, `TZ=UTC npx jest --ci` passes, and the faked-clock sweep adds no failure (design §22). `test:contract` exits 0. `css-diff.cjs` against a build of the PR's base reports nothing removed or changed, and `compare.mjs` exits 0 (design §7.1, Appendix C.5 and C.6). Any `LOOK` line is opened and explained in the report. Report the full-stack smoke checklist (design §20.1), which the user runs before merging. The user opens PR 3 in each repo. Name any commit that relies on design §4.9, because Spec 16 must reach production before it does.
    - _Requirements: 6.1, 4.5, 2.10_

## PR 4: Track B2 (after PR 3 merges; branch `fix/spec17-track-b2`; failing test first except FB-01)

- [ ] 9. Approved fixes
  - [ ] 9.1 FB-01: POS token comment (design §15.1)
    - Replace the docblock; add the `sessionStorage` restore test to `lib/store/auth.test.ts`.
    - Commit `docs(auth): FB-01 say the staff token is persisted per tab`.
    - _Requirements: 14.1, 14.2_
  - [ ] 9.2 FB-02: error screens (design §15.2; run the `frontend-design` skill first)
    - Write `components/error-screen.test.tsx` and one test per `error.tsx`, and confirm they fail. Add `components/error-screen.tsx`, the route-group `error.tsx` files, root `app/error.tsx` and `app/global-error.tsx` in both apps.
    - Tests: title and body render; error details never shown; Try again calls `retry`; Reload calls `location.reload`; heading focused.
    - Commit per app `feat(errors): FB-02 add error screens with retry`.
    - _Requirements: 14.3, 13.4_
  - [ ] 9.3 FB-03: the detail-page top-up refreshes the student list (design §15.3)
    - Write the failing test (seeded list, top-up on detail, list mounted, `GET /students` re-sent, new balance shown); then add `["students"]` to the detail caller's invalidation set.
    - Commit `fix(students): FB-03 refresh the list after a detail-page top-up`.
    - _Requirements: 14.4_
  - [ ] 9.4 FB-04.n: each refresh gap the user approved from Appendix B §B.5, one commit each, same test pattern as 9.3
    - _Requirements: 14.5_
  - [ ] 9.5 F-4+: each frontend/backend mismatch the user approved from Appendix B, one commit each; failing test first with fixture-shaped data
    - _Requirements: 2.8, 14.7_
  - [ ] 9.6 Report; the user opens PR 4 in each repo
    - `TZ=UTC npx jest --ci` passes and the faked-clock sweep adds no failure (design §22).
    - Smoke: FB-02 by throwing in a dev build; FB-03 by a top-up and back navigation within 60 s.

## Standards documents (spec owner, in `~/sunbites-api`; each needs the user's approval of the exact text)

- [ ] 10. Documents match the code (after PR 3 is handed over)
  - [ ] 10.1 `.claude/rules/frontend/frontend-standards.md` per design §14
    - _Requirements: 12.1_
  - [ ] 10.2 `.claude/rules/testing.md` frontend section per design §14
    - _Requirements: 12.2_
  - [ ] 10.3 `.kiro/steering/tech.md` and `structure.md`: the exact before/after in design §14, applied only after approval; the versions once PR 1 is merged, and D1 once FB-01 is merged
    - _Requirements: 12.3, 14.2_

---

## Coverage check

| Requirement | Tasks |
|---|---|
| 1.1–1.5 | 1.1–1.4 |
| 2.1 | 4.3 (and every task's citation rule) |
| 2.2 | 2.3, 4.2, 4.3, 4.4 |
| 2.3 | 2.1, 4.3 |
| 2.4, 2.5 | 4.1, 4.2 |
| 2.6 | 4.3, 6.5, 9.5 |
| 2.7 | 4.3, 4.4 |
| 2.8 | 4.2, 4.3, 4.4, 9.5 |
| 2.9 | ground rules; 2.1, 2.3, 4.x |
| 2.10 | 8.3 |
| 2.11 | 4.3 |
| 2.12–2.15 | ground rules (backend checks, direct calls); task 4.3 (design §4.10) |
| 3.1–3.5 | 2.1–2.3, 2.7 |
| 3.6 | 2.4, 2.5, 2.6 |
| 4.1, 4.2, 4.6 | 5.1–5.9 |
| 4.3 | ground rules; every task in 6–8 |
| 4.4 | 3.3, 5.x, 6.x, 7 |
| 4.5 | 3.3, 8.2 |
| 5.1–5.5 | 6.1 (5.3 also 5.6, 5.7) |
| 6.1–6.4 | 6.2, 8.3 |
| 7.1–7.4 | 6.3 |
| 7.5 | design H16 (no task: nothing is shared) |
| 8.1–8.5 | 6.4 |
| 9.1, 9.2 | 6.6 |
| 9.3 | 6.1, 6.7 |
| 9.4 | 6.8 |
| 10.1–10.4 | 7 |
| 11.1 | 3.2, 6.8 |
| 11.2, 11.3 | 3.2 |
| 11.4 | 3.1 |
| 12.1–12.3 | 10.1–10.3 |
| 12.4 | ground rules (`AGENTS.md` block never edited) |
| 13.1–13.4, 13.6, 13.7 | ground rules; 9.2 (13.4) |
| 13.5 | 8.1 |
| 14.1, 14.2 | 9.1, 10.3 |
| 14.3 | 9.2 |
| 14.4 | 9.3 |
| 14.5 | 6.4 (recording), 9.4 |
| 14.6 | none (FB-05 left out) |
| 14.7 | ground rules ("Found, not fixed"), 9.5 |
