# Requirements Document

## Introduction

The POS (`~/sunbites-pos`) and the parent portal (`~/sunbites-portal`) work, and they grew feature by feature across Specs 01–16. This spec does three things:

1. Brings their code up to the versions now installed and to the project's frontend standards, **without changing anything a cashier, manager or parent sees or does**. It is the frontend counterpart of Spec 16 (backend code quality).
2. Makes both frontends **comply with the backend, verified from the backend side** (user, 2026-10-31). Every response the frontends read and every request they send is checked against what the API actually does, never against the frontend's own types or test mocks.
3. Absorbs the former Spec 17, *Frontend contract fixes* (F-1 to F-3, approved 2026-10-17, never implemented). Its folder `17-frontend-contract-fixes` is replaced by this one. The IDs F-1, F-2 and F-3 are kept, so the references in Spec 16 (`contract-gaps.md`, `design.md`) still point at the right items.

**Baseline (2026-10-31).** On 2026-10-31 both apps ran `npm update` and then moved Next.js and React off their exact pins. Both apps now have identical versions. The changes are uncommitted on `bump-latest-upgrade-pos` and `bump-latest-upgrade-portal`.

| Package | Before | Now |
|---|---|---|
| `next` / `eslint-config-next` | 16.2.6 | **16.4.3** |
| `react` / `react-dom` | 19.2.4 | **19.3.1** |
| `@tanstack/react-query` | 5.101 | 5.105 |
| `zod` | 4.4 | 4.6.7 |
| `@base-ui/react` | 1.6 | 1.9 |
| `shadcn` / `lucide-react` | 4.16 / 1.27 | 4.23 / 1.65 |
| `tailwindcss`, `zustand`, `jest`, `msw` | | 4.3.3, 5.0.16, 30.5, 2.15 (unchanged majors) |

Next 16.2.6 had five critical advisories, including unauthenticated remote code execution through the image optimizer. The portal is exposed because it serves `next/image` from wildcard S3/Spaces hosts. All five are fixed in 16.4.3. After the bump, `npm audit --omit=dev` reports 0 high and 0 critical in both apps.

**What the audit found (verified 2026-10-31; details in Appendix A).**
- **The frontends don't fully match the backend.**
  - Spec 16's contract freeze compared every API response with the frontend types. Three mismatches are visible bugs today: the empty inventory snapshot and ignored history filters (F-1), and wallet amounts without separators (F-2).
  - 78 response fields differ from what the API sends, measured by the type checker (design Appendix B.1). Most have no visible effect.
  - The test mocks use shapes the API never sends, which is how F-1 and F-2 went unnoticed.
- **No safety net for much of the code.** Coverage reports 82.9% (POS) and 85.0% (portal), but only for files some test loads. **73 POS files (about 11,810 lines) and 23 portal files (about 3,386 lines) are never loaded by any test**, including the POS checkout parts, the dashboard, 8 of 10 reports, pre-registrations and reminders, and the portal's public pre-registration form.
- **Oversized files.** The POS student detail page is 3,276 lines with 56 `useState` calls and 15 inline components. 21 files are over 500 lines (18 POS, 3 portal).
- **Copies.** POS helpers and components are defined over and over: `formatPeso` 8 times, `TableRowSkeleton` 8, `StatusBadge` 7, `formatDate` 6. Some copies have already drifted apart.
- **Hand-wired data loading.** The POS writes its TanStack Query keys by hand 161 times (the portal 35), with no `queryOptions()`. 48 of the POS's 61 `useQuery` calls sit directly in pages.
- **Deprecated APIs.** The installed type definitions mark these as deprecated: Zod `.flatten()` (24 call sites), `z.string().email()` (6) and the `message` refine parameter (5). The new Next 16.4 lint rule fires once. The student detail page still types `params` the Next 15 way.
- **Current React patterns not used.** The React Compiler (stable in Next 16) is off. 5 effects wrap `setState` in `startTransition` (6 calls) to get past the `set-state-in-effect` lint rule, and 2 more disable that rule.
- **The project's frontend standards don't match these apps.** `frontend-standards.md` says pages fetch on the server, which is impossible here because the login token lives in the browser. It also shows Zod's deprecated `.flatten()` and bans inline styles that the QR print cards need.

**Two tracks, as in Spec 16:**
- **Track A (refactor)** must not change behaviour, and must be provably so. The proof is the behaviour freeze (Requirement 4): characterization tests written before the change, plus rendered-markup comparison. Correcting a frontend type to match the backend is Track A when no runtime behaviour changes.
- **Track B (fixes)** are opt-in per item. Each one starts with a failing test and states what users will notice. The F-items fix frontend/backend mismatches; the FB-items fix other defects.

A Track A change and a Track B change are never combined in one commit or one PR.

**Depends on:**
- **Spec 16** (backend code quality, branch `bump-laravel-13-latest`). Its local contract fixtures are the backend truth this spec checks against, and they include every Spec 16 Track B response change. On 2026-11-07 that branch was not yet on GitHub, so production still runs the pre-Spec 16 API (design §4.9). The backend keeps moving, including Laravel upgrades, so this spec isn't pinned to a commit: Requirement 2.12 to 2.15 keep the frontends aligned with the latest backend. In Track A, Spec 16 guarantees that responses don't change.
- **No backend file changes in this spec.** A mismatch only the backend can fix is reported as a proposed backend item (Req 2.11).

**Verification basis.** Every number above and in Appendix A was measured on 2026-10-31 by:
- reading the code in both repos;
- the installed `.d.ts` files of Zod 4.6.7 and `@types/react` 19.3;
- the docs bundled with Next 16.4.3 (`node_modules/next/dist/docs/`);
- a full Jest coverage run;
- `next build` in both apps;
- the 471 Spec 16 contract fixtures in `~/sunbites-api/tests/Fixtures/contracts/`, together with `contract-gaps.md`.

The former Spec 17's design (verified 2026-10-17, commit `6a333e8`) is carried into this spec's design and re-verified against the current fixtures.

**Audit of this spec (2026-11-07).** The riskiest parts of the design were built and run on throwaway clones of both apps (design §1.4). That corrected several mechanisms, filled in the audit tables, and found new visible bugs (FA-27 to FA-34), including one crash live on production (FA-33).

---

## Decisions *(Q1–Q8 approved 2026-10-31; Q3 and Q7 amended the same day when the former Spec 17 was merged in)*

| # | Decision | Approved |
|---|---|---|
| Q1 | React Compiler | **On** (config in design D-6), with the new devDependency `babel-plugin-react-compiler` approved. The lint rules it relies on already pass. It is enabled in its own commit per app, last in Track A, so it can be reverted alone (Req 10). |
| Q2 | Size limit for source files after the split | **400 lines** for any non-test file outside `components/ui/`. `page.tsx` stays a thin shell (Req 5, 6). |
| Q3 *(amended)* | Delivery shape | Four PRs per app, in this order:<ol><li>**Upgrade** (Req 1), from the existing `bump-latest-upgrade-*` branches.</li><li>**Track B1, contract fixes**: F-1 to F-3 (Req 3), plus any F-item from Req 2 that doesn't depend on Track A.</li><li>**Track A**: one commit per task.</li><li>**Track B2**: FB-01 to FB-04 (Req 14), plus any F-item found during Track A.</li></ol>The user pushes and merges. *(Amended: the 2026-10-31 approval had one Track B PR per app. Splitting it lets the contract fixes ship early, before Track A splits the files they touch.)* |
| Q4 | `undici` 5 advisories (Jest polyfill only; dev-only, never shipped) | **Fixed in this spec** by upgrading to 6.29.1. Verified on Node 26 and Node 20 (Req 11.4, design §13). |
| Q5 | Process | Same as Spec 16: a spec-owner session reviews an implementer session one task at a time, with a `HANDOFF.md`. |
| Q6 | How to prove the markup is unchanged | Local, gitignored **render fixtures**: the HTML of each recorded state, captured before the change and compared after. This mirrors Spec 16's local contract fixtures, and it respects `testing.md`'s ban on committed snapshot tests (Req 4.4). |
| Q7 *(amended)* | Former Spec 17 | **Merged into this spec** (user, 2026-10-31). F-1 to F-3 ship in Track B1, right after the upgrade and before Track A (Q3). |
| Q8 | Final pass | **A read-only `react-component-expert` review** of each app's full Track A diff. It only reports findings. Each finding the user approves becomes a normal Track A task, under Requirement 4. No tool applies changes on its own (Req 13.5). |

---

## Requirements

### Requirement 1: Upgrade shipped and a green starting point

**User Story:** As the product owner, I want the security upgrade shipped on its own and both test suites fully green, so that the rest of this spec starts from a known-good, patched baseline.

#### Acceptance Criteria

1. The 2026-10-31 upgrade (lockfile refresh; `next` 16.4.3, `react`/`react-dom` 19.3.1 and `eslint-config-next` 16.4.3, pinned exactly) SHALL be committed in each app before any other commit of this spec and shipped as its own PR (Q3).
2. WHEN the upgrade is committed THEN `npm audit --omit=dev` SHALL report no high or critical advisory in either app.
3. Both apps SHALL pass `npm run type-check`, `npm run lint` (0 errors), `npm test` and `npm run build`.
4. Before any Track A or Track B task, the two failing POS tests SHALL pass:
   - `FeedbackPage › shows a success toast and closes the sheet after replying`. It fails on every run since `fa7e677`; the toast text is found twice.
   - The intermittent `SettleCreditDialog` failures, which already happen on `main`.

   The fix is test-only. Both causes were proven on 2026-11-07, and neither is visible to users (design H12, H21). IF the fix doesn't hold, THEN stop and report.
5. WHEN the baseline is declared green THEN each app's full suite SHALL pass 3 consecutive runs.

### Requirement 2: The frontends comply with the backend, verified from the backend side

**User Story:** As a cashier, manager or parent, I want every screen to read exactly what the API sends and send exactly what the API accepts, so that no field is silently blank, wrong or ignored.

#### Acceptance Criteria

1. The backend is the only source of truth, never a frontend type, an existing test mock or memory. Each question has one source:

   | Question | Source |
   |---|---|
   | Response status and shape (keys, JSON types, string formats such as decimal strings and ISO-8601 timestamps, nullability) | The Spec 16 contract fixtures in `~/sunbites-api/tests/Fixtures/contracts/` (471 files on 2026-10-31; local and gitignored; they reflect `bump-laravel-13-latest` including Spec 16 Track B) |
   | Paths and methods | `vendor/bin/sail artisan route:list --path=api --except-vendor` |
   | Query and body field names and allowed values | The route's Form Request rules (`app/Http/Requests/`) |
   | Error bodies | `bootstrap/app.php` and the controller or Action code (tech steering, "Exception Rendering"; Spec 16 Req 1.3) |
   | Any case no fixture covers | The controller, Action or Resource code |

2. Every frontend type that describes a response SHALL match its fixture for every field the frontend declares. That covers the typed `apiClient` calls in each app's `lib/api/*.ts` (155 counted in Spec 16 T-1) and the types they use in `types/*.ts`:
   - same key and same JSON type;
   - nullable where the backend can send `null`;
   - `string` where the backend sends a decimal string;
   - enum unions that include every value the backend can send.

   Extra backend keys the frontend doesn't declare are allowed.
3. Every request SHALL match the backend:
   - its path and method exist in `route:list`;
   - every query parameter and body key is one the route's Form Request reads, with values its rules accept.
4. Every MSW response used in frontend tests (`__tests__/mocks/handlers.ts` and per-test `server.use(...)`) SHALL have the shape of the matching fixture. Mocks with shapes the API never sends are how F-1 and F-2 were hidden.
5. A **local contract check** (design defines it) SHALL fail when an MSW response's shape differs from its fixture. MSW response data SHALL be typed with the frontend's response types (`satisfies`), so type-check fails when test data and frontend type disagree.
   - Like Spec 16's fixtures, the check runs locally and is skipped in CI, because the fixtures are not committed.
   - Locally, a skipped check is a gate failure.
6. What each role can see and do in the POS SHALL match the backend's `role:` middleware (route files and Spec 16's `RoleAuthorizationMatrixTest`; `route:list --json` shows it as `RoleMiddleware:…`). Two cases are mismatches:
   - a control shown to a role whose request the backend rejects with 403;
   - a control hidden from a role the backend allows.
7. Error handling SHALL match the backend's error bodies:
   - 422 `{ message, errors }`;
   - the 422 bodies with `errors` and no `message` (subscription end-before-start, payment range, public pre-registration, portal change-password);
   - 401 `Unauthenticated.`, 403 `Forbidden.` and 404 `Not found.`;
   - `abort()` texts;
   - the portal login `error` codes `account_not_activated` and `account_disabled`.
8. Every mismatch SHALL be listed in design with the fixture or backend file it was checked against. That covers `contract-gaps.md` §1–§2, re-checked against the current fixtures, plus any new one. Each SHALL be resolved:
   - **Track A**, when correcting the frontend type changes no runtime behaviour (the code already converts the value, or never reads the field);
   - **otherwise a Track B F-item** (F-4, F-5, …), failing test first, approved one by one.
9. WHEN a task writes or changes code that reads a response or sends a request THEN the implementer SHALL first read the matching fixture, or the backend code named in 2.1, and SHALL cite it (fixture file name or `file:line`) in the commit body. A shape SHALL never be inferred from a frontend type or an existing mock.
10. A frontend change that relies on a response changed by Spec 16 Track B SHALL NOT be deployed to production before Spec 16 is. There is no staging API any more, so production is the only place this can go wrong. Examples: B-07 receipt cashier name, B-12/B-13 report row shapes, B-16 archived inventory, B-21 `credit_balance`, B-22 birthday format.
11. No backend file changes in this spec. WHEN a mismatch can only be fixed in the backend THEN it SHALL be reported under "Found, not fixed" as a proposed backend item.
12. **Always align to the latest backend (user, 2026-11-07).** No snapshot of the backend SHALL be kept; the running Laravel app is checked directly (user, 2026-11-08). Before every step the implementer SHALL record the backend branch, commit and Laravel version (design §4.10.1). WHEN the backend has uncommitted changes in `app`, `routes`, `bootstrap`, `config`, `database/migrations` or `composer.lock` THEN no frontend commit SHALL be made until they are committed.
13. WHEN backend code changed since the implementer's previous commit THEN the backend's contract suite SHALL pass on the latest code (design §4.10.2) before work continues. WHEN code reads a response or sends a request THEN the endpoint SHALL be checked by calling Laravel directly, inside a rolled-back transaction, GET routes only (design §4.10.3). Routes and roles SHALL be read from Laravel's `route:list`, never from a saved copy.
14. WHEN the backend changed a response, request rule, route, role or enum that a screen depends on THEN the design and Appendix B SHALL be updated before work continues, and any visible effect SHALL become a proposed F-item.
15. WHEN Laravel or another backend dependency is upgraded THEN 2.12 to 2.14 apply, and its release notes SHALL also be read for changes to validation, responses and serialization.

### Requirement 3: Contract fixes F-1, F-2 and F-3 *(Track B; approved 2026-10-17 in the former Spec 17)*

**User Story:** As a manager and as a parent, I want the screens that misread the API today to show the right data, and the POS cart to never carry one branch's order into another, so that reports, wallet amounts and checkout can be trusted.

#### Acceptance Criteria

1. **F-1 POS inventory report (`~/sunbites-pos`).**
   1. WHEN the Inventory report loads THEN the Stock Snapshot table SHALL list the rows the API returns in `items` (`GET /api/v1/reports/inventory`).
   2. The status of each row SHALL be read as the API sends it: `"OK"`, `"LOW"`, `"OUT"` or `"OVER"` (uppercase, `InventoryItem::status`).
   3. WHEN a row is shown THEN its badge SHALL show the existing label and colour for its status: OK ✓, LOW ⚠, OUT ✕, OVER ▲.
   4. WHEN a status filter is chosen (OK, Low, Out, Over) THEN only rows with that status SHALL be shown. "All Status" SHALL show every row.
   5. WHEN the API returns no items THEN the existing empty state, "No inventory data found.", SHALL be shown. WHEN the request fails THEN the existing error text, "Failed to load inventory data. Please try again.", SHALL be shown.
   6. WHEN a History type is applied THEN the request SHALL send it as `type`. WHEN the History page changes THEN the request SHALL send it as `page`. Those are the names the API reads; today the page sends `log_type` and `history_page`, which the API ignores.
   7. The dashboard's low-stock widget SHALL NOT change. It reads a different endpoint (`GET /dashboard`), which sends lowercase `out`/`low`, and it already works.
2. **F-2 Portal wallet amounts (`~/sunbites-portal`).**
   1. The `Transaction.amount` type SHALL match the API: a decimal string such as `"1500.00"` or `"-150.00"` (`GET /api/v1/portal/students/{student}/wallet`, bavix `amountFloat`).
   2. WHEN a transaction is shown THEN its amount SHALL be converted to a number before formatting, so ₱1,000 or more shows with a thousands separator (`+PHP 1,500.00`).
   3. A positive amount SHALL keep the `+` prefix and the green style. A negative amount SHALL keep the red style and show the minus sign (`PHP -150.00`), exactly as intended today.
   4. `formatPHP` SHALL NOT change. Its other callers already receive numbers (verified against the fixtures).
3. **F-3 POS cart cleared when the branch changes (`~/sunbites-pos`).**
   1. WHEN the active branch changes from one branch to another THEN the cart SHALL be cleared: items, student, walk-in flag, payment method and notes, as `clearCart()` does.
   2. WHEN the user logs out (branch becomes null) THEN the cart SHALL be cleared.
   3. WHEN a branch is selected for the first time (null to a branch), or the page is hard-refreshed, THEN the cart SHALL NOT be touched. That is the same guard `BranchCacheSync` already uses.
4. Each fix SHALL be one commit in its app's Track B1 PR (Q3). It SHALL start with tests that fail on the current code, and those tests SHALL use the real API shape (string amounts, `items`, uppercase statuses).
5. Before implementation, each fix SHALL be re-verified against the current fixtures, because the former Spec 17 design was verified on 2026-10-17 and Spec 16 has changed responses since.
6. **Approved by the user on 2026-11-08 (design §5.5, §5.6, Appendix B.0).** F-4 and F-5 SHALL ship in Track B1 under 3.4. F-16 ships as a hotfix to `main`, ahead of PR 1 and PR 2:
   1. **F-4:** the Sales report Customer filter SHALL filter the rows and the export, using the backend's `is_walk_in`.
   2. **F-5:** the Sales and Billing reports SHALL show money with thousands separators.
   3. **F-16 (live on production):** the portal SHALL show a student whose `enrollment_status` is `unenrolled` instead of crashing. Committed on 2026-11-08 as `81c204e` on the portal's `hotfix/portal-unenrolled-child-crash`, branched from `main` (design §5.6).

### Requirement 4: Behaviour freeze (prerequisite for all Track A work)

**User Story:** As the product owner, I want every screen's current behaviour pinned by tests before its code is touched, so that I can trust a refactor without re-testing every screen by hand.

#### Acceptance Criteria

1. WHEN a Track A task will change a file THEN every screen that renders it SHALL first have characterization tests (Jest 30, React Testing Library, MSW 2, per `.claude/rules/testing.md`). They SHALL cover:
   - what the screen shows when loading, empty, failed and loaded;
   - every request it sends (method, path, query parameters, body);
   - what follows each user action (toast text, dialogs opening and closing, navigation, and which requests are sent again).

   Their MSW data SHALL follow Requirement 2.4.
2. Characterization tests SHALL be written against the unchanged code, pass there, and land in a commit before the refactor commit.
3. A Track A commit SHALL NOT change the assertions of any existing test. Only import paths, the render target (a page becomes its screen component, Req 5) and shared test utilities may change.
4. WHEN a Track A task changes a component THEN the HTML it renders for each recorded state SHALL be identical before and after, once generated ids and one-frame animation attributes are normalised (design §6.2) and the clock is pinned. This is proved with local render fixtures (Q6, design) and is never a committed snapshot test.
5. Coverage SHALL count every source file (`collectCoverageFrom`), so untested files show up. At the end of this spec, the thresholds in `jest.config.ts` SHALL be raised to the measured values and never lowered.
6. Every file that no test loads today (Appendix B) SHALL have tests before a Track A task changes it.

### Requirement 5: Thin route files and Next.js 16 conventions

**User Story:** As a frontend developer, I want `page.tsx` files to be thin server shells that follow Next 16's typed routes, so that each route is easy to find and its params are typed correctly.

#### Acceptance Criteria

1. Each `page.tsx` SHALL be a Server Component that reads route params with `await params`, typed with the generated `PageProps<'/route'>` helper (`next typegen`). It SHALL render one screen component. Hooks, state and data loading live in that Client Component. Today 38 of 40 POS pages and 9 of 13 portal pages start with `"use client"`.
2. The POS student detail page SHALL stop typing `params` as a plain object and SHALL drop its `useParams()` fallback. `parsePositiveInt` SHALL behave exactly as before for every input.
3. The auth gates in the client layouts (`(kitchen)`, `(pos)`, `(portal)`) SHALL keep their redirects and their "render nothing until the session is restored" behaviour.
4. The `Route (app)` table from `next build` SHALL be identical before and after, so no route changes between static and dynamic.
5. On the built server, every route SHALL return the same status as before, with no server error.

### Requirement 6: Break up oversized files

**User Story:** As a frontend developer, I want each file to do one job, so that I can change a dialog without scrolling through 3,000 lines.

#### Acceptance Criteria

1. At the end of this spec, no non-test file outside `components/ui/` SHALL exceed 400 lines (Q2). Today 28 files exceed it (25 POS, 3 portal); Appendix A FA-03 lists them.
2. Components used by one route SHALL move to that route's `_components/` folder, as `students/[id]/_components/` already does. Components used by two or more routes SHALL move to `components/{domain}/`.
3. Extracted components SHALL follow the component template in `frontend-standards.md`: named export, props interface above the component, and `className?: string` on presentational components.
4. Every split SHALL satisfy Requirement 4 (behaviour and markup unchanged).

### Requirement 7: One copy of each helper and component, per app

**User Story:** As a frontend developer, I want each formatter and shared component defined once, so that fixing one fixes it everywhere.

#### Acceptance Criteria

1. Helpers defined in two or more files SHALL be defined once per app in `lib/`. The portal already has `lib/format.ts`. Appendix A FA-04 lists them: `formatPeso` ×8, `formatDate` ×6, `formatDateTime` ×5, `getDateRange` ×4, `fmt` ×4, `groupByDate` ×3, plus the school-year, `parsePositiveInt`, `historyRowClass` and `formatTime` pairs.
2. Components defined in two or more files (FA-05) SHALL be defined once when the copies render the same output. Same-named components that are different things (for example, `StatusBadge` for different status sets) SHALL get distinct names instead of being forced together.
3. WHEN copies differ THEN the merged version SHALL keep each screen's current output, through props or variants, and design SHALL record each difference. A difference that is a defect SHALL become a Track B item (FB-03 is the first).
4. The authenticated photo download, copied 4 times as `fetch(student.photo_url, { headers })`, SHALL go through one function in `lib/api/`.
5. Code shared between the two apps stays duplicated (Out of Scope).

### Requirement 8: Data layer follows TanStack Query v5 conventions

**User Story:** As a frontend developer, I want each query key and query defined once per API domain, so that refreshing data after a change can't silently miss a screen.

#### Acceptance Criteria

1. Each API domain SHALL define its query keys and its `queryOptions()` once, in the location set by design. Every `useQuery`, `useQueries`, `invalidateQueries`, `setQueryData` and `removeQueries` call SHALL use them. No literal key array SHALL remain outside those definitions (161 in the POS and 35 in the portal today).
2. In Track A, every key value SHALL stay identical to today's, so prefix matching and invalidation behave the same. Renaming keys is out of scope.
3. The requests sent again after each mutation SHALL stay the same, as proved by the Requirement 4 tests.
4. A mutation used by two or more screens (for example, wallet top-up) SHALL be defined once and reused.
5. `QueryClient` defaults (`staleTime: 60_000`, `retry: 1`) and `BranchCacheSync`'s query-cache reset SHALL NOT change. F-3 adds the cart clear to it.

### Requirement 9: Current APIs replace deprecated ones

**User Story:** As a frontend developer, I want the code to use the APIs the installed versions recommend, so that the next upgrade isn't blocked by removed functions.

#### Acceptance Criteria

1. Zod:
   - `.flatten()` (24 sites) SHALL become `z.flattenError()`.
   - `z.string().email()` (6 sites) SHALL become `z.email()`.
   - `.refine(..., { message })` (5 sites) SHALL use `error`.
   - Every error message shown SHALL stay identical, and so SHALL which inputs pass validation.
2. Zod schemas SHALL live in `lib/validation/{domain}.ts`, as `frontend-standards.md` requires. Today 11 POS files and 4 portal files define schemas inline. Where a schema mirrors a backend Form Request, its rules SHALL NOT accept less than the backend accepts. Any difference found SHALL be reported under Requirement 2.8.
3. The 6 `startTransition(() => setX())` calls inside effects (5 effects) and the 2 `eslint-disable react-hooks/set-state-in-effect` comments SHALL be replaced with patterns the React docs recommend (for example, a hydration check via `useSyncExternalStore` or the persist API). Behaviour SHALL stay the same. The `startTransition` in the reminders search `onChange` handler is a correct use and stays.
4. Lint SHALL end with 0 warnings in both apps. WHERE keeping current behaviour requires a rule exception, it SHALL be a single-line disable with a written reason. Two cases are known:
   - The full-page redirect to `/branch` in POS `lib/api/client.ts:56`. A full reload clears all in-memory state, and a client-side navigation would not.
   - The `<img>` elements in print and blob previews, where `next/image` would change the markup.

### Requirement 10: React Compiler *(Q1 approved 2026-10-31)*

**User Story:** As a frontend developer, I want the React Compiler to handle memoization, so that components don't need hand-written `useCallback`/`useMemo`.

#### Acceptance Criteria

1. Each app SHALL enable `reactCompiler` in `next.config.ts` with the config in design D-6, with `babel-plugin-react-compiler` pinned exactly as a devDependency.
2. Both apps SHALL pass type-check, lint, test and build with the compiler on. The full suite SHALL also pass **through the compiler** (`COMPILED_TESTS=1`), with render fixtures unchanged.
3. Hand-written `useCallback`/`useMemo` (POS 15 + 2, portal 1) SHALL be removed only from components the compiler actually compiles. Design defines how that is checked.
4. The compiler SHALL be enabled last in Track A, in its own commit per app.

### Requirement 11: TypeScript, lint, test and CI tooling

**User Story:** As a frontend developer, I want the tooling to catch mistakes early, so that problems show up in CI and not after a merge.

#### Acceptance Criteria

1. Portal ESLint SHALL ignore `coverage/`, as the POS already does. `npm run quality:validate` SHALL run lint with `--max-warnings 0`.
2. The `tsconfig.json` compiler options SHALL be modernised as listed in design (target, and any added strictness flags), with type-check passing and no runtime change (`noEmit`; Next compiles through SWC).
3. The PR workflow (`.github/workflows/pr.yml`) in each app SHALL also run `npm run build`. Today a build-only failure is first caught by the Vercel deploy.
4. No high or critical advisory SHALL remain in either app's full `npm audit`, including dev dependencies (Q4).

### Requirement 12: Standards documents match the code

**User Story:** As any developer or agent working on the frontends, I want the written standards to describe how these apps actually work, so that following them produces correct code.

#### Acceptance Criteria

1. `.claude/rules/frontend/frontend-standards.md` SHALL be updated, with the user's approval of the exact text, to cover:
   - client-side data fetching, and why (the token lives in the browser);
   - the server `page.tsx` shell with `PageProps`;
   - the `_components/` convention and the 400-line limit;
   - `queryOptions()` and key definitions;
   - Zod 4 APIs (`z.flattenError`, `z.email`, `error`);
   - when inline styles are allowed (print sizes, data-driven widths);
   - the React Compiler;
   - **the backend-compliance rule of Requirement 2**: verify every response and request against the backend, never against frontend types or mocks.
2. The frontend section of `.claude/rules/testing.md` SHALL describe, with the user's approval:
   - characterization tests;
   - MSW data shaped like the backend fixtures, and the local contract check;
   - `collectCoverageFrom` and the threshold rule.
3. `.kiro/steering/tech.md` (frontend versions; D1 outcome) and `.kiro/steering/structure.md` ("Auth token storage") SHALL change only with the user's explicit approval of the exact before/after text.
4. The `AGENTS.md` managed block in each app SHALL NOT be edited.

### Requirement 13: Mandatory tooling and final review *(non-negotiable)*

**User Story:** As the spec owner, I want every frontend task done against the backend's real behaviour and the installed versions' own docs, so that "correct" means what the API does and "newest" means newest for what's installed, not what an agent remembers.

#### Acceptance Criteria

1. WHEN a task reads a response or sends a request THEN Requirement 2.9 applies: check the backend first, and cite the fixture or file.
2. WHEN a task writes Next.js-specific code THEN the implementer SHALL first read the matching guide in `node_modules/next/dist/docs/`, as each app's `AGENTS.md` requires.
3. WHEN a task uses a library API (TanStack Query, Zod, Base UI, Zustand) THEN the implementer SHALL check it against that package's installed type definitions or bundled docs.
4. WHEN a task changes components THEN the implementer SHALL follow the `react-component-expert` guidance and run the `frontend-design` skill:
   - **as a guard** in Track A, confirming the look is unchanged;
   - **as the design lead** for any Track B item that adds UI (FB-02).
5. WHEN all Track A commits for an app are done THEN the `react-component-expert` agent SHALL review that app's full Track A diff **read-only** (Q8):
   - It reports findings to the spec owner and changes no file.
   - Each finding the user approves SHALL become a normal Track A task under Requirement 4.
   - A finding that would change behaviour, markup, requests or messages SHALL be listed in the PR as rejected, or proposed as a Track B item.
6. WHEN a task writes or changes tests THEN `.claude/rules/testing.md` applies. Track B items follow `superpowers:test-driven-development` (failing test first).
7. Each commit body SHALL carry a short tooling log: fixtures and backend files checked, docs read, skills and agents used.

### Requirement 14: Track B, other behaviour-changing fixes *(FB-01 option (a), FB-02, FB-03 and FB-04 approved 2026-10-31; FB-05 left out)*

**User Story:** As the product owner, I want each real defect listed separately with what users will notice, so that I can approve, defer or reject each one.

#### Acceptance Criteria

1. Each approved FB-item SHALL ship in its own commit in Track B2 (Q3), SHALL start with a failing test that reproduces it, and SHALL state its user-visible impact as recorded in design. FB-01 is the one exception (see 14.2).
2. **FB-01 POS token storage (steering D1), option (a) approved.**
   - The comment in POS `lib/store/auth.ts` says the token is "in-memory only, never persisted", but `partialize` saves it to `sessionStorage`.
   - The comment SHALL be corrected to say what the code does: the token is persisted to `sessionStorage` per tab, so staff stay signed in across a refresh. D1 SHALL be recorded as accepted.
   - Behaviour does not change, so there is no failing test. Instead, a test SHALL pin the documented behaviour: the token is restored from `sessionStorage` after a reload.
   - The matching steering edits need the user's approval of the exact text (Req 12.3).
3. **FB-02 Error screens, approved.**
   - Neither app has an `error.tsx` or `global-error.tsx`, so a crash shows Next's default error screen.
   - Each route group SHALL get an error screen with a plain message and a retry action, and never raw error details (`frontend-standards.md`). Each app SHALL also get a `global-error.tsx`.
   - The screens are designed with the `frontend-design` skill, within each app's existing tokens and components.
   - This is the only new UI in this spec.
4. **FB-03 Stale student list after a detail-page top-up, approved.**
   - The detail page's `WalletTopUpModal` invalidates only `["student", id]`. The list page's copy also invalidates `["students"]`.
   - So after a top-up on the detail page, the student list can show the old balance for up to 60 s (`staleTime`).
   - The detail-page top-up SHALL also refresh the list.
5. **FB-04 Other refresh gaps, approved as a process.** Any further case found during Requirement 8 where a change doesn't refresh a screen showing that data SHALL be:
   - listed in design or in the task report, with evidence;
   - fixed only after the user approves that specific item (FB-04.1, FB-04.2, …).
6. **FB-05 Portal image hosts, left out by the user on 2026-10-31.** The wildcard host patterns in portal `next.config.ts` stay as they are (Out of Scope).
7. Any other defect found during the work SHALL go in the task report under "Found, not fixed", and SHALL be proposed as a new F- or FB-item, never fixed in passing.

---

## Cross-Cutting Requirements

- **API contract and backend compliance:** every response the frontends read and every request they send is checked against the backend (Req 2). In Track A, every request keeps its method, path, query parameters, body and headers (`Authorization`, `X-Branch-Id`). No backend change.
- **Security / authorization:**
  - Track A doesn't change token handling, redirects, or what each role sees. UI role gates are checked against the backend's `role:` middleware (Req 2.6).
  - No new `NEXT_PUBLIC_` value, no `dangerouslySetInnerHTML`.
  - Security items: Req 1 (Next advisories), Req 11.4 (dev-only `undici`), FB-01 (documents the accepted token storage).
- **Data isolation:** branch switching keeps its query-cache reset (`BranchCacheSync`). F-3 adds the cart clear. Every kitchen request keeps sending `X-Branch-Id`.
- **Performance:** no screen sends more requests than today. No new runtime dependency.
- **Accessibility:** labels, roles and focus order stay the same, because markup is identical (Req 4.4).
- **Error handling:** every error message, toast and empty-state text stays word for word, and the frontends handle every backend error body (Req 2.7).
- **Observability:** N/A. Neither app has error monitoring today (verified: no Sentry or similar), and adding it is a feature.
- **Testing:** every commit passes type-check, lint, test and build in the app it touches, and the local contract check when it touches API code or MSW data. New tests follow `.claude/rules/testing.md`: the custom `render`, role and label queries, MSW at the network boundary with backend-shaped data, and no snapshot tests.
- **Delivery:**
  - Four PRs per app, in the Q3 order.
  - Commits use `type(scope): description`, with **no AI attribution lines**.
  - The user pushes, opens and merges every PR, to `staging` first. `staging` is a branch only: the API's staging environment was removed on 2026-11-07.
  - Before a PR merges, the user runs the local full-stack smoke (design §20.1).
  - Track A and Track B are never in the same PR.

## Out of Scope

- Any visual or interaction change other than an approved Track B item.
- Backend changes and API changes. A backend-only mismatch is reported, not fixed (Req 2.11).
- Narrowing the portal's `next/image` host allow-list (FB-05, left out by the user on 2026-10-31).
- Keeping the POS token in memory only (FB-01 option (b), not chosen).
- Major upgrades: TypeScript 7, ESLint 10, MSW 3, `@types/node` 26. CI runs Node 20, and `@types/node` 20 matches it.
- Sharing code between the two repos (a shared package or monorepo).
- Server-side data fetching, Cache Components, Partial Prefetching and Server Actions. All need cookie-based auth, because today the token is in the browser. That is its own spec.
- Moving forms to `useActionState`. TanStack mutations already provide pending and error state, and the switch would change reset and pending behaviour.
- View Transitions, `<Activity>`, and adding `loading.tsx` where it's missing (the portal has 1, the POS 28). Each changes what users see.
- New features.

---

## Appendix A: Audit findings (2026-10-31)

| ID | Finding | Where | Track |
|---|---|---|---|
| FA-01 | Next 16.2.6 critical RCE/SSRF advisories, unreachable by `npm update` because of exact pins | both `package.json` | Req 1 (done locally, uncommitted) |
| FA-02 | 73 POS files (~11,810 lines) and 23 portal files (~3,386 lines) never loaded by a test; coverage % hides them (no `collectCoverageFrom`) | Appendix B | A (Req 4) |
| FA-03 | 28 files over 400 lines (21 over 500). POS (25): `students/[id]/page.tsx` 3,276 · `students/page.tsx` 1,851 · `enrollment/page.tsx` 1,358 · `references/inventory/page.tsx` 1,230 · `references/users/[id]/page.tsx` 994 · `components/pos/menu-mgmt-tab.tsx` 908 · `pre-registrations/[id]/page.tsx` 815 · `reports/inventory/page.tsx` 813 · `components/pos/cart-panel.tsx` 690 · `reports/students/page.tsx` 646 · `components/pos/student-search-input.tsx` 642 · `references/users/create/page.tsx` 601 · `references/subscription-config/page.tsx` 536 · `components/notification-bell.tsx` 512 · `reminders/page.tsx` 512 · `notifications/page.tsx` 512 · `reports/billing/page.tsx` 510 · `dashboard/page.tsx` 509 · `reports/subscription/page.tsx` 494 · `references/feedback/page.tsx` 493 · `reports/credits/page.tsx` 470 · `references/meal-planner/page.tsx` 468 · `reports/sales/page.tsx` 455 · `lib/api/reports.ts` 437 · `reports/activity/page.tsx` 413. Portal (3): `components/pre-registration/pre-registration-form.tsx` 958 · `notifications/page.tsx` 582 · `components/notification-bell.tsx` 569 | both apps | A (Req 6) |
| FA-04 | Duplicated helpers (POS): `formatPeso` ×8, `formatDate` ×6, `formatDateTime` ×5, `getDateRange` ×4, `fmt` ×4, `groupByDate` ×3; ×2: `parsePositiveInt`, `historyRowClass`, `getSchoolYear`, `getDefaultSchoolYear`, `getDefaultSchoolMonth`, `formatTime`. Portal: `groupByDate` ×2 | POS `app/`, `components/` | A (Req 7) |
| FA-05 | Duplicated components (POS): `TableRowSkeleton` ×8, `StatusBadge` ×7, `SummaryCard` ×5, `FormField` ×5, `FieldError` ×4; ×3: `TableSkeleton`, `SectionCard`, `PaymentBadge`, `InfoRow`, `DetailSkeleton`, `CategoryBadge`; ×2: `WalletTopUpModal`, `StatusPickerDialog`, `StatusDot`, `SectionHeading`, `PaymentStatusBadge`, `NotificationDetailSheet`, `LogTypeBadge`. Portal: `NotificationDetailSheet` ×2 | POS, portal | A (Req 7) + FB-03 |
| FA-06 | `WalletTopUpModal` copies differ: list copy invalidates `["students"]` and `["student", id]` and resets fields; detail copy invalidates only `["student", id]` and adds the credit warning (Spec 14) | POS `students/page.tsx:126`, `students/[id]/page.tsx:386` | A (Req 7) + FB-03 |
| FA-07 | Authenticated photo fetch copied 4× outside `lib/api/` | POS `students/[id]/page.tsx:2560`, `students/page.tsx:431,627,1138` | A (Req 7.4) |
| FA-08 | Query keys hand-written (POS 161, portal 35), no `queryOptions()`; inconsistent roots (`student`/`students`, `parent`/`parents`, `user`/`users`, five inventory roots); 48 of 61 POS `useQuery` calls in pages, 2 in `hooks/` | both apps | A (Req 8) + FB-04 |
| FA-09 | Zod 4.6 deprecations: `.flatten()` ×24 (POS 16, portal 8), `z.string().email()` ×6, refine `message` ×5 | both apps | A (Req 9.1) |
| FA-10 | Inline Zod schemas in 11 POS / 4 portal files; POS has no `lib/validation/` | both apps | A (Req 9.2) |
| FA-11 | `startTransition(() => setState())` inside effects ×6 (5 effects: `(kitchen)`, `(pos)`, `(portal)` layouts; POS QR print root; portal wallet alert input) and `eslint-disable react-hooks/set-state-in-effect` ×2 (echo providers) | layouts, `students/page.tsx:841`, portal `wallet-tab.tsx:85`, `echo-provider.tsx` (both) | A (Req 9.3) |
| FA-12 | Lint warnings: POS 7 (6 `no-img-element`, 1 new 16.4 `no-location-assign-relative-destination` at `lib/api/client.ts:56`); portal 1 (lints `coverage/`) | both apps | A (Req 9.4, 11.1) |
| FA-13 | 38/40 POS and 9/13 portal `page.tsx` are Client Components; POS student detail types `params` as a plain object and falls back to `useParams()` | both apps | A (Req 5) |
| FA-14 | React Compiler off; `useCallback` ×15 / `useMemo` ×2 (POS), `useCallback` ×1 (portal); compiler lint rules (`eslint-plugin-react-hooks` 7.1.1) already pass | both apps | A (Req 10, Q1) |
| FA-15 | 2 POS tests failing (feedback toast since `fa7e677`; settle-credit flake also on `main`) | POS tests | Req 1.4 |
| FA-16 | `undici` 5 (Jest polyfill only) high advisories | `jest.polyfills.ts` (both) | Req 11.4 (Q4) |
| FA-17 | PR CI runs type-check, lint, test but not `build` | `.github/workflows/pr.yml` (both) | A (Req 11.3) |
| FA-18 | POS token comment says "in-memory only", code persists it (steering D1) | POS `lib/store/auth.ts` | FB-01 (a) |
| FA-19 | No `error.tsx` / `global-error.tsx` in either app | both apps | FB-02 |
| FA-20 | Portal image allow-list uses wildcard bucket hosts | portal `next.config.ts` | Out of scope (FB-05 left out) |
| FA-21 | `frontend-standards.md` prescribes server-side fetching, shows deprecated Zod API, bans inline styles the print cards need, and has no backend-verification rule | `.claude/rules/frontend/frontend-standards.md` | Req 12 |
| FA-22 | Inventory report reads `data` (API sends `items`) and lowercase statuses (API sends uppercase); history sends `log_type`/`history_page` (API reads `type`/`page`) | POS `reports/inventory/page.tsx`, `lib/api/reports.ts` | F-1 (Req 3.1) |
| FA-23 | Portal wallet `amount` typed `number`, API sends a decimal string; `formatPHP` loses the thousands separator | portal `types/portal.ts:85`, `wallet-tab.tsx` | F-2 (Req 3.2) |
| FA-24 | POS cart and selected student survive a branch switch | POS `components/branch-cache-sync.tsx`, `lib/store/cart.ts` | F-3 (Req 3.3) |
| FA-25 | Response fields typed differently from what the API sends: 78 measured on 2026-11-07, most with no visible effect (design Appendix B.1) | design Appendix B.1 | Req 2.8 (A or new F-items) |
| FA-26 | MSW mocks use shapes the API never sends (this hid F-1 and F-2); no check ties mocks to the backend | `__tests__/mocks/handlers.ts` (both) | Req 2.4, 2.5 |
| FA-27 | Sales report Customer filter sends `customer_type`, the API reads `is_walk_in`, so it does nothing, on screen or in the export | POS `reports/sales/page.tsx:164,197` | F-4 (approved 2026-11-08) |
| FA-28 | Sales and Billing report money reaches `formatPeso` as a decimal string, so `₱1500.00` has no separator. `contract-gaps.md` wrongly records this as no effect | POS `reports/sales/page.tsx:407-412`, `reports/billing/page.tsx:456` | F-5 (approved 2026-11-08) |
| FA-29 | The frontend asks for `per_page` on 3 endpoints whose controllers ignore it, so older announcements and notifications can't be reached, and staff recipients stop at 20 | POS `announcements/page.tsx:186`, `notifications/page.tsx:247`, `announcements/create/page.tsx:116` | F-6, F-7, F-8 (proposed; backend) |
| FA-30 | The UI and the backend disagree on roles: 4 controls shown to supervisors that the API rejects, 6 hidden that it allows | design Appendix B.3 | F-9 to F-14 (proposed) |
| FA-33 | **A parent with an unenrolled child gets a crash on the portal dashboard and Students list.** The status map has no `unenrolled` entry, the type hides it, and there's no error screen. Live on `main` | portal `components/enrollment-status-badge.tsx:40`, `types/portal.ts:36` | F-16 (approved 2026-11-08; hotfix) |
| FA-34 | Public pre-registration prints `new Date()` on a prerendered page, so the date is the build date until React recovers, with a hydration error | portal `pre-registration-form.tsx:945` | F-17 (proposed; low) |
| FA-32 | Forms let through input the backend refuses, notably passwords: production requires symbols and rejects breached ones, while the forms check 8 characters | design Appendix B.4 | F-15 (proposed) |
| FA-31 | The backend moved 10 commits and one Laravel release in one day, and a fixture digest can't detect that. Checked by calling Laravel directly, with no saved snapshot (user, 2026-11-08) | design §4.10 | Req 2.12–2.15 |
| FA-35 | The API's staging environment was removed on 2026-11-07, so the frontends' staging sites have no API, and a staging smoke is impossible | design §1.2, §20 | D-13, OPS-1 (decided 2026-11-08: leave the frontends' staging sites alone) |

## Appendix B: Files no test loads today

Measured with `jest --coverage` on 2026-10-31 (a file counts as untested when no test imports it, directly or indirectly). Design carries the full list. The largest are:

- **POS (73 files, ~11,810 lines):**
  - `components/pos/menu-mgmt-tab.tsx` 908 · `pre-registrations/[id]/page.tsx` 815 · `reports/inventory/page.tsx` 813 · `reminders/page.tsx` 512
  - `reports/billing/page.tsx` 510 · `dashboard/page.tsx` 509 · `reports/subscription/page.tsx` 494 · `reports/sales/page.tsx` 455
  - `reports/activity/page.tsx` 413 · `announcements/create/page.tsx` 388 · `reports/wallet/page.tsx` 388 · `pre-registrations/page.tsx` 386
  - `components/pos/inventory-tab.tsx` 375 · `reports/daily-summary/page.tsx` 365 · `references/system-settings/page.tsx` 324
  - `components/pos/transaction-history-tab.tsx` 303 · `components/pos/menu-grid.tsx` 248 · `components/pos/receipt-modal.tsx` 210
- **Portal (23 files, ~3,386 lines):**
  - `components/pre-registration/pre-registration-form.tsx` 958 · `feedback/page.tsx` 390 · `profile/page.tsx` 385
  - `students/[id]/_components/student-qr-actions.tsx` 274 · `reset-password-form.tsx` 216 · `activate-form.tsx` 205
  - `student-header.tsx` 155 · `dashboard/page.tsx` 149 · `student-detail-shell.tsx` 146
