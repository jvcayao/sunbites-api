# Requirements Document

## Introduction

The Sunbites API (Laravel **13.35.0**, PHP 8.5) works and has a strong regression net (94 feature test files, 951 tests, all passing on 2026-10-09 on MySQL 8.4 (Sail `testing` schema) and on SQLite (CI's driver), using the gate commands in design §11.1. It also grew feature by feature across Specs 01–15, and the code shows it:
- 83 controller methods (48 files) validate inline.
- About 200 `response()->json([...])` calls hand-build response arrays.
- Business rules live in controllers (Checkout 308 lines, Reminders 360, Analytics 535).
- String literals stand in for enums (`'paid'`/`'unpaid'`/`'voided'`).
- Current Laravel 13 idioms (`#[Scope]`, `#[UsePolicy]`, `#[Authorize]`, `#[Tries]`, `ShouldQueueAfterCommit`) are not used.

This spec modernises the backend to Laravel 13 conventions and the project's `laravel-best-practices` rules **without changing any response the two frontends consume** (`~/sunbites-pos`, `~/sunbites-portal`).

The audit also found **real defects**: cross-branch access, a stock race, a reCAPTCHA bypass, a school-year bug that spans backend and frontend, PII leaking through raw model serialisation, and blank UI fields. Fixing a defect changes behaviour by definition, so the work has two tracks:

- **Track A (refactor):** must be provably behaviour-neutral. Every Track A PR is verified by one rule: *the contract fixtures did not change*.
- **Track B (defect fixes):** opt-in per item, one commit each (Q3: one commit per area, all pushed as one PR), each starting with a failing test and stating its frontend impact.

A Track A change and a Track B change are never combined in one PR.

**Depends on:** Specs 01–15 (all merged). Spec 14 (`CreditLedgerService`) and Spec 15 (`WalletTopupVoid`) are the reference style.

**Verification basis:** every finding in [Appendix A](#appendix-a--audit-findings) was verified by reading the backend code and, where a frontend consumes the field, the matching page or type in `~/sunbites-pos` / `~/sunbites-portal` (verified 2026-09-26; re-verified 2026-10-09 after the Laravel 13.35 `composer update`, with a full FE call-site to route mapping, recorded in design §13).

### Steering alignment

`.kiro/steering/tech.md` was amended on 2026-09-25 (human-approved) to allow the Form Request migration: *"Existing inline `$request->validate()` (48 controller files) is being migrated under Spec 16, domain by domain, behind contract tests."* Known Discrepancies D1–D8 in `tech.md` are **not** in scope unless listed in Requirement 11.

---

## Requirements

### Requirement 1: API contract freeze (prerequisite for all Track A work)

**User Story:** As a frontend developer on POS and Portal, I want every backend response shape locked by tests before refactoring starts, so that I can trust a refactor PR without re-testing every screen.

#### Acceptance Criteria

1. WHEN Track A work begins THEN every route listed by `php artisan route:list --path=api --except-vendor` (165 routes on 2026-09-26) SHALL have at least one contract fixture (stored locally and gitignored, design §4.1.7) recording its HTTP status and the recursive JSON *shape* of its body (keys, JSON types, and string formats), per the grammar in design §4.1.
2. WHEN a route returns a file (Excel export, photo) THEN its fixture SHALL record status, `Content-Type`, and the `Content-Disposition` filename pattern instead of a body shape.
3. WHEN a route validates input or has a documented error path THEN it SHALL also have fixtures for each error envelope in use today, including the non-standard ones: `{ "errors": {...} }` with **no** `message` (subscription end-before-start in `EnrollmentController::store`, `PaymentController::addRange`, `Public\PreRegistrationController::store`; `Portal\ProfileController::changePassword`), and `{ "message", "error": "account_not_activated" | "account_disabled" }` from portal login.
4. The system SHALL have a route-coverage test that fails when any API route lacks a success fixture. Adding a route without a fixture breaks the local contract gate. CI has no fixtures and skips this check (design §4.1.7).
5. The system SHALL have a role-authorization matrix test asserting, for every route inside a `role:` middleware group, that each of `admin`, `manager`, `supervisor`, `cashier` receives 403 exactly when its role is not listed.
6. IF a Track A change alters any fixture or matrix expectation THEN it is a regression and SHALL NOT merge. Fixtures change only in Track B commits, and each changed fixture is listed in that commit's body and in the PR description with its frontend impact.
7. WHEN fixtures are recorded THEN each recorded shape SHALL be cross-checked against the consuming TypeScript interface in `~/sunbites-pos/lib/api/*.ts`, `~/sunbites-pos/types/*.ts`, `~/sunbites-portal/lib/api/*.ts` and `~/sunbites-portal/types/*.ts`. Every mismatch SHALL be logged in `.kiro/specs/16-backend-code-quality/contract-gaps.md` and not fixed in Track A.
8. The system SHALL NOT assert JSON key order (`.claude/rules/testing.md`).
9. *(Decided 2026-10-12.)* Contract fixtures SHALL NOT be committed, pushed or deployed: `/tests/Fixtures/contracts` is in `.gitignore`. A fixture-dependent test SHALL be skipped when the fixtures directory is absent (CI), and SHALL fail when the directory exists but its fixture is missing. Locally, a skipped test is a gate failure. Fixture changes SHALL be proven with the fixture digest and per-file hash diff (design §4.1.7) instead of `git diff`.

### Requirement 2: Thin controllers: extract domain logic into Actions/Services

**User Story:** As a backend developer, I want controllers to only translate HTTP to domain calls, so that business rules can be tested and reused without going through HTTP.

#### Acceptance Criteria

1. WHEN a controller method contains multi-step business logic (transactions, locking, wallet or credit mutations, inventory movement, notification fan-out) THEN that logic SHALL move into an Action (`app/Actions/{Domain}/`, method `execute()`) or a Service (`app/Services/`), following `DowngradeStudentSubscriptionAction` and `CreditLedgerService`.
2. At minimum the extractions listed in design §4.3 SHALL be done, each with the exact class name and signature given there.
3. WHEN inventory stock changes (checkout deduction, void restock, manual adjust, initial stock) THEN all four paths SHALL go through `App\Services\InventoryStockService`, which is the only writer of `inventory_logs` rows and of `inventory_items.quantity` outside CRUD.
4. WHEN logic is extracted THEN the extracted class SHALL have its own feature test, and the existing HTTP feature tests SHALL pass unchanged.
5. WHEN the original code aborts (`abort()`/`abort_if()`) THEN the extracted code SHALL keep using `abort()`/`abort_if()` with the identical status and message text (precedent: `CreditLedgerService`). WHEN the original code returns `response()->json([...], $status)` from a guard THEN the extracted code SHALL throw `Illuminate\Http\Exceptions\HttpResponseException` carrying that identical response. The two are not interchangeable: `abort()` output gains debug keys when `APP_DEBUG=true`.
6. The ordering of side effects SHALL be preserved: DB writes inside the transaction, then (after commit) activity log calls, job dispatch, mail queueing, in the same order as today.
7. The system SHALL NOT introduce repositories, interfaces for single implementations, DTO packages, or an `app/Exceptions/` directory (no new base folders; `rules/architecture.md`).

### Requirement 3: Form Request validation

**User Story:** As a backend developer, I want validation and input sanitising in Form Request classes, so that controllers stay small and rules are reusable and discoverable.

#### Acceptance Criteria

1. WHERE a controller method calls `$request->validate(...)` THE system SHALL move those rules into the Form Request named in design §4.2 (all 83 methods), under `app/Http/Requests/{Kitchen|Portal|Public}/`, or `app/Http/Requests/` for requests shared by both consumers.
2. WHEN rules move THEN each rule SHALL be carried over verbatim: same rule list, custom messages, and conditional rules (`required_if`, `Rule::when`, `Rule::prohibitedIf`, `Rule::unique(...)->where(...)`). The resulting `errors` object is identical.
3. WHEN a controller runs a guard **before** calling `validate()` THEN the Form Request SHALL run that guard first, in `prepareForValidation()`, with an identical response, so the same error wins when several apply. The affected methods are listed in design §4.2.2 and include `TransactionController::void`, `PaymentController::void`/`updateAmount`, `Kitchen\PreRegistrationController::update`/`reject`, `Portal\FeedbackController::store`, `Kitchen\FeedbackController::reply` and `Public\PreRegistrationController::store`.
4. WHEN a controller calls `$this->authorize()` before validating THEN that check SHALL move to an `#[Authorize]` controller attribute **in the same PR** (the attribute runs as middleware before the Form Request resolves), so a forbidden request still gets 403 rather than 422.
5. WHEN input is sanitised today (`strip_tags`, `sanitizeText`) THEN the Form Request SHALL reproduce the same timing: sanitise-before-validate in `prepareForValidation()`, sanitise-after-validate in `passedValidation()` or at the point of use.
6. WHEN a controller reads unvalidated input (`$request->deleted`, `->search`, `->type`, `->month`, `->payment_status`, `->year`, `->grade`, `->status`, `->role`, `->input('month')`, `->input('week')`, `->integer('year')`, `->integer('page')`) THEN those inputs SHALL be declared with **permissive** rules that accept every value the frontends send today (design §4.2.3) and be read from `validated()`. No previously accepted request may start returning 422.
7. Controllers SHALL call `$request->validated()` (or `->validated('key')`) only, never `$request->all()`.

### Requirement 4: Eloquent model modernisation

**User Story:** As a backend developer, I want models to use current Laravel 13 conventions consistently, so that the codebase has one way of doing each thing.

#### Acceptance Criteria

1. `InventoryItem::scopeActive` / `scopeArchived` SHALL become `#[Scope]` protected methods with unchanged call sites (`InventoryItem::active()`).
2. `PreRegistration::getFullNameAttribute` SHALL become an `Attribute::make()` accessor; `ParentPaymentReminder::$casts` SHALL become a `casts()` method.
3. A `PaymentStatus` string-backed enum (`Paid = 'paid'`, `Unpaid = 'unpaid'`, `Voided = 'voided'`) SHALL cast `student_monthly_payments.status`, and every comparison site listed in design §4.5.2 SHALL be updated, including in-memory collection filters (`$payments->where('status', 'paid')`), which would otherwise silently match nothing.
4. The literal `'enrolled'` in `EnrollmentService` and the `'subscription'`/`'non_subscription'`/`'completed'` literals in Eloquent (not raw SQL) queries in report controllers SHALL use the existing `EnrollmentStatus`, `StudentType` and `OrderStatus` enums.
5. Policies SHALL be bound with `#[UsePolicy]` where a model→policy mapping exists (`Order`→`OrderPolicy`, `User`→`UserPolicy`, `Student`→`ParentStudentPolicy`). `Gate::policy(ReportPolicy::class, ReportPolicy::class)` and the unused `ReportPolicy`/`StudentPolicy` classes SHALL be removed. Neither is referenced by any `authorize()`/`can()` call (verified by grep).
6. `Model::preventLazyLoading()` SHALL be enabled so that violations **throw only in `testing`** and are **logged, not thrown**, in every other non-production environment (`local`, `staging`). `local` must not throw: developers run the POS and portal against the local API (`APP_ENV=local` in `.env`), so any code path the tests don't cover would otherwise break pages on their machines. Every violation the test suite surfaces SHALL be fixed with eager loading in the same PR.
7. *(Q4 approved 2026-10-09)* Every model in `app/Models/` SHALL move `$table`, `$fillable`, `$hidden` and `$timestamps = false` to the Laravel 13 class attributes `#[Table]`, `#[Fillable]`, `#[Hidden]` and `#[WithoutTimestamps]`, all in one commit (T-16), per design §4.5.5. A model-attribute snapshot test SHALL pass before and after. The same commit adds the missing `@use HasFactory<…>` and relation generic docblocks.
8. `Model::preventSilentlyDiscardingAttributes()` SHALL NOT be enabled. Several code paths rely on silent discarding today (e.g. `User::create($validated)` with `role`, `branch_ids`, `profile_photo`; `contacts()->createMany()` with `id`), and enabling it would turn working requests into 500s on staging.

### Requirement 5: API Resources for duplicated response shapes

**User Story:** As a backend developer, I want response shapes that are duplicated across controllers defined once, so that they cannot drift apart.

#### Acceptance Criteria

1. Only the Resources listed in design §4.4 SHALL be introduced. Each replaces byte-identical inline arrays that exist in two or more places, or a `format*()` helper. Inline arrays that appear once stay inline in Track A (no speculative abstraction).
2. WHEN a Resource replaces an inline array THEN every key, JSON type and string format SHALL be preserved (Req 1). A Resource SHALL be serialised via `response()->json($resource)`, `->resolve()` or nesting inside an array, **never** returned directly from a controller, because a directly returned `JsonResource` adds a `data` wrapper.
3. Raw model serialisation (`SystemConfiguration`, `BranchMonthlyAmount`, `StudentContact`, `StaffDailyStatus`, `PreRegistration::toArray()`) SHALL stay as it is in Track A. The raw serialisation in the Sales and Billing reports is changed only by Track B item B-12.
4. The pagination envelope from `Controller::paginationMeta()` and the native paginator envelope (`StudentController::index`, `UserManagementController::index`) SHALL be preserved. The three 4-key `meta` endpoints keep their 4 keys.
5. The system SHALL NOT adopt `JsonApiResource`.

### Requirement 6: Authorization consistency

**User Story:** As a security reviewer, I want one visible authorization pattern, so that missing checks are easy to spot.

#### Acceptance Criteria

1. Route files SHALL remain the single source of truth for **role** middleware. Role checks SHALL NOT move to controller attributes.
2. Every `$this->authorize()` call SHALL become an `#[Authorize('ability', 'routeParam')]` method attribute (verified present in the installed framework: `Illuminate\Routing\Attributes\Controllers\Authorize`).
3. `Portal\StudentPaymentHistoryController::index` SHALL use `#[Authorize('view', 'student')]`. Today it hand-rolls the check with message `'You do not have access to this student.'`, and after the change the 403 body becomes `{"message":"Forbidden."}` once B-23 has landed (today Laravel would render `This action is unauthorized.`, design H19). The body text changes, so it ships as Track B item **B-15**. Verified 2026-09-26: the portal does not display that message (`payment-history-tab.tsx:31` shows a fixed "Failed to load payment history."), so FE impact is none.
4. `AuthorizesRequests` SHALL be declared once on the base `Controller` (7 copies today).
5. Business-rule `abort(403|422, '...')` guards keep status and message (Req 2.5).

### Requirement 7: Query performance

**User Story:** As a canteen manager on a slow school network, I want list and report pages to load in a bounded number of queries, so that the POS doesn't stall at lunch rush.

#### Acceptance Criteria

1. `AnnouncementController::index` SHALL compute read counts with one grouped query per page, not one per announcement.
2. `AnnouncementController::show` SHALL load recipients with one `whereIn` query, not `find()` per notification.
3. `CreditLedgerService::notifyParentsOfCharge` SHALL check the daily debounce with an `exists()` query instead of loading all of today's notifications.
4. `ReminderController::send` SHALL load existing reminders for all requested parents in one query.
5. Duplicated filter blocks (Sales report ×3; inventory-log join query ×3 across `InventoryController::history` and `InventoryReportController`) SHALL be defined once (design §4.6).
6. Each of criteria 1–4 SHALL have a test asserting the query count does not grow with N (N=1 vs N=5).
7. Output of every endpoint touched here SHALL be unchanged (Req 1).

### Requirement 8: Queued notifications and transactions

**User Story:** As an operator, I want notifications and mail dispatched only after the data they describe is committed, so that parents never get a notification for a rolled-back charge.

#### Acceptance Criteria

1. The five notification classes (`AnnouncementNotification`, `CreditChargedNotification`, `CreditSettledNotification`, `PaymentReminderNotification`, `PreRegistrationNotification`) SHALL implement `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` in place of `ShouldQueue` (it extends `ShouldQueue`; honoured by `SendQueuedNotifications`, verified in 13.35). The queue connection's `after_commit` stays `false` (Q5).
2. `ParentWelcomeMail`, which is queued inside transactions via `ParentProvisioningService::provision` (called inside the `EnrollmentController::store` and `PreRegistrationController::approve` transactions), SHALL implement `ShouldQueueAfterCommit` in place of `ShouldQueue` (honoured by `SendQueuedMailable`, verified in 13.35). Its other two call sites (`StudentContactController::resendActivation`, `ParentController::resendActivation`) run outside transactions, so they still dispatch immediately.
3. *(Q7 approved 2026-10-09)* `WalletAlertJob` SHALL declare `#[Tries(3)]` and `#[Backoff(10, 60)]` (3 attempts, retried after 10 s and then 60 s). This is a deliberate change from today's single attempt.
4. Notification payloads (`toArray()`/`toDatabase()`), `via()` channels and broadcast channel names SHALL NOT change.
5. After-commit behaviour SHALL be tested with real side effects (the `notifications` table, and `MessageSending` events via `Event::fake([MessageSending::class])`) for both a rolled-back and a committed transaction. `Queue::fake()`, `Notification::fake()` and `Mail::fake()` SHALL NOT be used for these tests, because the fakes record immediately and ignore after-commit (verified in 13.35, design §4.7).

### Requirement 9: Framework and project hygiene

**User Story:** As a backend developer, I want project metadata and bootstrap code to reflect what this project is, so that tooling and newcomers aren't misled.

#### Acceptance Criteria

1. `composer.json` `name` SHALL become `sunbites/api` and `description` SHALL describe the API. The `dev` script SHALL drop the `npm run dev` process, and `ci:check` SHALL drop the `npm run lint:check`, `format:check` and `types:check` steps (steering D6). No `require`/`require-dev` change.
2. `bootstrap/app.php` exception rendering SHALL stay as is. `renderable()` and `render()` are aliases in Laravel 13.35 (re-verified on 2026-10-09 in `Illuminate\Foundation\Configuration\Exceptions`), so there is nothing to modernise.
3. New cross-cutting defaults (Req 4.6) live in `AppServiceProvider::configureDefaults()`.
4. `#[CurrentUser]` SHALL NOT be adopted wholesale; it MAY appear in new Action entry points only.

### Requirement 10: Track B, behaviour-changing defect fixes *(B-01–B-05 and B-07–B-16 approved 2026-10-09; B-17, B-18 and B-19 approved 2026-10-12; B-20 and B-21 approved 2026-10-17; B-22 and B-23 proposed 2026-10-17; B-06 merged into B-05)*

**User Story:** As the product owner, I want each real defect listed separately with its verified frontend impact, so that I can approve, defer or reject each one.

#### Acceptance Criteria

1. Each Track B item SHALL ship in its own commit, SHALL start with a failing test that reproduces the defect, SHALL update only the fixtures it names, and SHALL state its frontend impact exactly as recorded in design §5.
2. **B-01 Cross-branch access.** A staff user acting on a record from another branch SHALL get 404 `{"message":"Not found."}`:
   - `Kitchen\ParentController` `show`, `disable`, `enable`, `destroy`, `restore`, `resendActivation` (parent must have a student in the active branch)
   - `InventoryController::unarchive` (drop `withoutBranch()`)
   - `DashboardController::updateStaffStatus`, where the `user_id` must belong to the active branch; this one returns a 422 validation error on `user_id` rather than a 404.
3. **B-02 Branch header required.** A kitchen request outside the allowlist in design §5.2 that arrives without a valid active branch SHALL get 422 `{"message":"No active branch selected. Please set a branch first."}`, instead of `BranchScope` silently not filtering or a 500 from `app('active_branch')->id`.
4. **B-03 Inventory race.** Concurrent checkouts, voids and adjustments on the same inventory item SHALL NOT lose updates or oversell: stock is re-checked under `lockForUpdate` inside the transaction, rows are locked in ascending id order, and the error message stays `"{name} is out of stock."`.
5. **B-04 Unused unauthenticated pre-registration routes.** `POST /api/v1/portal/pre-registrations` (no reCAPTCHA) and `POST /api/v1/portal/pre-registrations/check` SHALL be removed with their controllers and tests. Neither frontend calls them; verified: the portal uses `/public/pre-registrations` only.
6. **B-05 School-year vs calendar-year.** `student_monthly_payments.year` stores the calendar year, but:
   - `ReminderController` treats it as a school-year start (+1 for Jan–Mar) in `extractUnpaidPeriods`, `resolveDueDate` and the overdue check.
   - The POS sends the **school-year start** as `year` to `/reports/billing`, `/reports/subscription` and `/reminders/eligible-parents?school_year`, but those endpoints filter on calendar year.

   In January–March, therefore, Jan–Mar payments are never reminded, have due dates a year late, and are missing from the billing, subscription and reminder screens. The fix (design §5.5) makes those three request parameters mean school-year start on the backend, with **no frontend change**.
7. **B-07 Receipt cashier name.** `OrderResource.cashier.name` reads a non-existent `User::name` column and is always `null`, so the POS receipt modal (`components/pos/receipt-modal.tsx`) renders an empty cashier. It SHALL return the cashier's `full_name`.
8. **B-08 Portal login disclosure.** `account_disabled` SHALL be returned only after the password is verified. `account_not_activated` stays before the password check, because unactivated parents have no password and the portal login page relies on that code to show the activation prompt. Changing it would break activation.
9. **B-09 Non-atomic writes.** `Kitchen\PreRegistrationController::update` (delete and re-create contacts) and `Public\PreRegistrationController::store` (record plus contacts) SHALL run inside `DB::transaction()`. Mail and notifications stay after commit.
10. **B-10 LIKE wildcards.** User search terms SHALL be matched literally (`%`, `_` escaped with `ESCAPE '!'`, which works on both MySQL and SQLite) in every search listed in design §5.9.
11. **B-11 Analytics credit limit.** `AnalyticsController` SHALL read `SystemConfiguration::getValue('credit_limit', config('sunbites.credit_limit', 300))`, matching checkout.
12. **B-12 PII in raw report rows.** `/reports/sales` and `/reports/billing` serialise full `User` models (`cashier`, `recorder`), exposing `daily_rate`, `phone`, `birthday`, address and emergency-contact fields to supervisors, plus full `Student` models including `qr_code` (the checkout credential). They SHALL emit the reduced shapes in design §5.11, which keep every field the POS reads (verified against `SalesOrder`, the sales page and the billing page).
13. **B-13 Blank student name on the sales report.** The POS sales page renders `order.student.full_name`, but the raw `Student` serialisation has no `full_name`, so the column is blank. It is fixed by B-12's shape.
14. **B-14 500 on bad status input.** `PATCH /students/{student}/status` calls `EnrollmentStatus::from($request->enrollment_status)` inside the rule definition, so a missing or invalid value returns 500 instead of 422. It SHALL use `tryFrom`.
15. **B-15 Portal payment-history 403 message.** See Req 6.3.
16. **B-16 Archived inventory unreachable in POS.** The POS inventory page (`references/inventory/page.tsx:894-895`) splits `GET /references/inventory` into active and archived sections and offers "Unarchive". But `InventoryController::index` returns only `InventoryItem::active()`, so the archived section is always empty and unarchive cannot be reached. `GET /api/v1/references/inventory` SHALL return active **and** archived items (ordered by `name`), and `GET /api/v1/pos/inventory` SHALL keep returning active items only. Verified safe: every consumer of `/references/inventory` already filters `is_archived` client-side where it needs active items (`components/pos/menu-mgmt-tab.tsx:442`, `reports/inventory/page.tsx:539`).
17. **B-17 *(approved 2026-10-12)*: cross-branch branch monthly amounts.** Updating or deleting another branch's `BranchMonthlyAmount` by id SHALL return 404 (design §5.15).
18. **B-18 *(approved 2026-10-12)*: cross-branch feedback.** Replying to or marking read another branch's `Feedback` by id SHALL return 404 (design §5.16).
19. **B-19 *(approved 2026-10-12)*: inventory export crashes.** `GET /reports/inventory/export` SHALL return the xlsx (200) instead of 500, by making `InventoryReportExport` implement `Maatwebsite\Excel\Concerns\Export` (design §5.17).
20. **B-20 *(approved 2026-10-17)*: sold menu item delete.** Deleting a menu item that has order lines SHALL return 422 `{"message": "This menu item has sales history and cannot be deleted. Mark it as unavailable instead."}` instead of 500, and SHALL leave the item in place (design §5.18).
21. **B-21 *(approved 2026-10-17)*: portal dashboard credit.** `GET /portal/dashboard` SHALL include `credit_balance` (number) for each student, so the existing "Credit owed" badge works (design §5.19).
22. **B-22 *(proposed 2026-10-17, awaiting approval)*: pre-registration birthday.** Pre-registration responses SHALL serialise `birthday` as `Y-m-d`, so the POS edit form shows it and saving does not move it back one day (design §5.20).
23. **B-23 *(proposed 2026-10-17, awaiting approval)*: 403/404 bodies.** For `api/*`, a policy denial SHALL return 403 `{"message":"Forbidden."}` and a model-binding or `firstOrFail()` miss SHALL return 404 `{"message":"Not found."}`, as `bootstrap/app.php` intends. Every `abort()` text and the `role:` middleware text SHALL stay unchanged (design §5.21).

### Requirement 11: Dependency and surface cleanup *(Q6 approved 2026-10-09, on condition that the API is not affected)*

**User Story:** As a security reviewer, I want unused packages and routes removed, so that the attack surface matches what the product uses.

#### Acceptance Criteria

1. `laravel/fortify` SHALL be removed (package, `config/fortify.php`, and the unused `TestCase::skipUnlessFortifyHas()` helper). Verified: staff password reset uses the `Password` broker directly in `Kitchen\AuthController`, and no API route depends on Fortify (steering D2; design §4.8).
2. `binafy/laravel-cart` SHALL be removed (steering D4).
3. The top-level `/broadcasting/auth` web route SHALL be removed by dropping `channels:` from `withRouting()` and loading `routes/channels.php` from `AppServiceProvider::boot()` (steering D3).
4. Before any removal, `BroadcastChannelAuthTest` SHALL be added (none exists today) proving staff and parent private-channel authorization on the two `/api` auth routes.
5. **The API SHALL NOT be affected:** `route:list --path=api --except-vendor --json` is identical before and after, the contract suite passes with the fixture digest unchanged (design §4.1.7), and the full suite passes on both databases (design §4.8).

### Requirement 12: Mandatory tooling and final simplification pass *(non-negotiable)*

**User Story:** As the spec owner, I want every backend task done with Laravel Boost and the project's Laravel skills, and all finished backend changes reviewed by `laravel-simplifier`, so that the code follows current Laravel 13 practice and stays consistent.

#### Acceptance Criteria

1. WHEN any task that writes or changes PHP starts THEN the implementer SHALL activate the `laravel-best-practices` skill (`.claude/skills/laravel-best-practices/SKILL.md`), read every rule file its index maps to the task's concerns, and follow them. Where a rule conflicts with this spec, this spec's behaviour-preservation hazards win, and the conflict SHALL be noted in the PR.
2. WHEN a task uses any Laravel or first-party package API (Form Requests, attributes, Resources, Eloquent, queues, notifications, validation rules, middleware) THEN the implementer SHALL first query the Laravel Boost `search-docs` tool for that API, and SHALL use the other Boost tools as specified in design §12.
3. WHEN a task writes or changes tests THEN the `testing-best-practices` skill SHALL be activated. WHEN it touches `app/Exports/` THEN `laravel-excel`. WHEN it touches roles, permissions or `role:` middleware THEN `laravel-permission-development`. WHEN it touches Fortify THEN `fortify-development`.
4. IF the Laravel Boost MCP server is not connected (it runs through Sail: `vendor/bin/sail artisan boost:mcp`) THEN the implementer SHALL start Sail (`vendor/bin/sail up -d`), reconnect, and SHALL NOT continue the task until Boost is available.
5. The PR description SHALL contain a **Tooling log** with one block per commit: the skills activated, the rule files read, and each `search-docs` query with the doc section it relied on.
6. WHEN every backend commit in this spec (Track A, every approved Track B item, and the T-18 standards audit) is on `bump-laravel-13-latest` THEN a final task (T-19) SHALL run the `laravel:laravel-simplifier` agent over the complete Spec 16 backend diff (`<spec16-start-sha>..HEAD`), under the constraints in design §12.3, before the branch is pushed for the PR. Its changes SHALL pass the full verification gate (both databases, fixture digest unchanged per design §4.1.7, role matrix, Pint).
7. The simplifier pass SHALL NOT change behaviour, response shapes, validation rule sets, messages, status codes, activity-log calls or hazard-protected code (design §3). Any simplifier suggestion that would do so SHALL be rejected and listed in the PR as "rejected: <hazard id>".

### Requirement 13: Laravel 13 and PHP standards alignment *(final check)*

**User Story:** As the spec owner, I want the backend to follow Laravel 13 conventions, the project's PHP code standards and Pint styling everywhere this spec touches, so that the codebase reads as one consistent Laravel 13 application.

#### Acceptance Criteria

1. The Laravel 13 / PHP idioms listed in design §14.1 (S1–S10) SHALL be applied at every listed site, inside the task named for that row, behaviour-identically.
2. Only the attributes marked *Adopt* in design §14.5 (the complete Laravel 13.35 attribute inventory) SHALL be introduced. The Form Request attributes marked *Forbidden* (hazard H17) SHALL NOT be used. The idioms rejected in design §14.3 SHALL NOT be introduced either: `final` classes, `declare(strict_types=1)`, PHP 8.4+ syntax while `composer.json` requires `php: ^8.3`, `whereLike()` replacing `lower(...) like`, and converting `Carbon::create*()` month loops to immutable dates (hazard H16).
3. WHEN all Track A and Track B commits and T-17 are done THEN task T-18 SHALL run the audit commands in design §14.4, and every command SHALL return its expected result (0 hits), or list an exception with its reason, in the T-18 commit body.
4. Every backend task SHALL end by running Laravel Pint through Sail (`vendor/bin/sail bin pint --dirty --format agent`) as required by `.claude/rules/backend/laravel-pint.md`, and before the branch is pushed `vendor/bin/sail bin pint --format agent` SHALL report no changes (CI runs `pint --test` on the whole repo).

### Requirement 14: MySQL test job in CI *(approved 2026-10-12)*

**User Story:** As the product owner, I want every pull request tested on MySQL as well as SQLite, so that a bug that only appears on the production database engine cannot be merged.

#### Acceptance Criteria

1. `.github/workflows/ci.yml` SHALL gain a second job, `test-mysql`, that runs the full PHPUnit suite against a temporary MySQL 8.4 service container, alongside the existing SQLite `test` job (which SHALL stay unchanged).
2. The job SHALL call `vendor/bin/phpunit` directly with `DB_CONNECTION=mysql` and the service's connection settings, never `php artisan test` (design §11.1 explains why).
3. The job SHALL use only throwaway, CI-local credentials for the service container. It SHALL NOT use any repository secret, and SHALL NOT connect to any staging or production resource.
4. `staging.yml` and `production.yml` SHALL NOT change in this spec.
5. The job SHALL be added in the commit directly after T-1 (task C-1), so every later Spec 16 commit is checked on both engines when the PR runs.

---

## Cross-Cutting Requirements

- **Security / authorization:** No Track A change may widen access (enforced by Req 1.5). Role middleware stays in route files. Security fixes: B-01, B-02, B-04, B-08, B-12.
- **Data isolation:** `HasBranch` + `BranchScope` remain the enforcement mechanism. Every `withoutBranch()`/`withoutGlobalScopes()` that moves into an Action keeps a docblock stating its cross-branch reason. No new ones are added without one.
- **Wallet integrity:** balances change only via `deposit`/`withdraw`. The Spec 15 `DB::table('transactions')` meta update moves into `VoidWalletTopUpAction` unchanged.
- **Credit ledger invariant:** `CreditLedgerService` stays the only writer of `credit_transactions` and `students.credit_balance`. `CreditLedgerInvariantTest` stays green.
- **Activity log:** log names, descriptions and property keys are data consumed by the POS Activity Log screen. Moving code SHALL NOT add or remove model `save()`/`update()` calls on models using `LogsActivity` (`Student`, `Order`, `User`, `InventoryItem`, `PosMenuItem`). Switching between Eloquent `update()` and query-builder `update()`/`increment()` changes which activity rows are written.
- **Database portability:** every query must behave the same on MySQL 8.4 and SQLite. Tests must pass on both, using the two gate commands in design §11.1 (CI runs SQLite only; `vendor/bin/sail artisan test` also runs SQLite).
- **Performance:** Track A must not add queries per request. Req 7 reduces them.
- **Error handling:** envelopes from `bootstrap/app.php` are unchanged, and `abort()` message text is unchanged.
- **Observability:** Sentry + Flare integration unchanged.
- **Tooling (non-negotiable):** Laravel Boost + `laravel-best-practices` on every PHP task, `testing-best-practices` on every test change, and a final `laravel-simplifier` pass over all backend changes (Req 12).
- **Test environment:** never run config/route/event caching locally during this work (hazard H18).
- **Existing tests:** never edited, except the edits named in `tasks.md` ground rules (T-12.3, T-17.1, B-04, B-05).
- **Frontend integration:** every commit is checked against the FE integration matrix in design §13. After the single PR merges to `staging`, the full page checklist (design §13.3) is run on staging before promoting to `main`.
- **Delivery (Q3, updated 2026-10-09):** all work is committed on the **current branch `bump-laravel-13-latest`** (no new branch); start commit `3454977`; one commit per task group and per approved Track B item; `type(scope): description` subjects with no AI attribution trailers; the user opens and merges the single PR, preferably **without squash** so each area stays revertible (design §6).
- **Code style:** Pint through Sail after every backend task (`.claude/rules/backend/laravel-pint.md`); PHP 8.3-compatible syntax only (`composer.json` `php: ^8.3`); hazard H16 (Carbon mutability) applies to every date-loop edit.
- **Testing:** every commit runs the full suite with the **MySQL gate** and the **SQLite gate** (design §11.1), plus `vendor/bin/sail bin pint --dirty --format agent`. Contract fixtures are recorded with `app.debug = false` (design §4.1.2).

## Out of Scope

- Database schema changes.
- Frontend changes. Track B items document FE impact; none requires a FE change except as noted in design §5.
- New features, endpoints, route renames, API versioning.
- `JsonApiResource`, DTO/Data packages, repository pattern, static analysis (Larastan) or any new dependency. (Removals approved under Req 11 are in scope.)
- Raising `composer.json` `php` to `^8.5`, or adopting PHP 8.4+ syntax (design §14.3).
- `AnalyticsController` SQL beyond B-11. Its `isSqlite()` branches stay.
- Steering discrepancy D1 (POS token in `sessionStorage`) is a frontend change and needs its own POS spec. D5, D7 and D8 are ops or product decisions.

---

## Appendix A: Audit Findings

| ID | Finding | Where | Track |
|---|---|---|---|
| A-01 | Fat controllers with business rules | `CheckoutController::store`, `TransactionController::void`, `WalletController::voidTopUp`, `ReminderController`, `Kitchen\PreRegistrationController::approve` | A |
| A-02 | Pre-registration create flow duplicated; portal copy unused and lacks reCAPTCHA | `Portal\PreRegistrationController`, `Public\PreRegistrationController` | B-04 |
| A-03 | Stock checked outside the transaction without lock; writes use stale quantity | `CheckoutController` (pre-check ~L73-92, write in transaction); `InventoryController::adjust`; `TransactionController::void` (unordered locks) | B-03 |
| A-04 | Cross-branch IDOR | `ParentController` (show…restore), `InventoryController::unarchive`, `DashboardController::updateStaffStatus` | B-01 |
| A-05 | Calendar vs school year, cross-stack | `ReminderController` L85/293/352; `BillingReportController` L26/68 + POS `billing/page.tsx`; `SubscriptionReportController` + POS `subscription/page.tsx`; reminders `school_year` + POS `reminders/page.tsx` | B-05 |
| A-06 | Inventory movement + `InventoryLog` write copied in 3 controllers | Checkout, TransactionController, InventoryController | A |
| A-07 | N+1 / unbounded loads | `AnnouncementController` L24/107/114; `CreditLedgerService` L200; `ReminderController` L193 | A |
| A-08 | Duplicated query/filter blocks | `SalesReportController` (3×); inventory-log query (3×) | A |
| A-09 | ~200 inline response arrays; 4 Resources; duplicated `format*()` helpers | all controllers | A |
| A-10 | Status string literals instead of enums | `StudentMonthlyPayment` (24 comparison sites), `EnrollmentService` L54, report controllers | A |
| A-11 | Unvalidated magic input | `StudentController::index`, `UserManagementController::index`, `Kitchen\MealPlannerController::show`, `BranchMonthlyAmountController::index`, `ReminderController::eligibleParents` (`page`) | A |
| A-12 | Non-standard 422 envelopes (no `message`) | Enrollment, Payment `addRange`, Public pre-reg, Portal `changePassword` | A (preserve) |
| A-13 | Mixed model conventions | `ParentPaymentReminder::$casts`, `PreRegistration::getFullNameAttribute`, `InventoryItem::scope*` | A |
| A-14 | Policy wiring: self-mapped unused `ReportPolicy`, unused `StudentPolicy`, hand-rolled portal check, `AuthorizesRequests` ×7 | `AppServiceProvider` L37, `Portal\StudentPaymentHistoryController` | A + B-15 |
| A-15 | Queued notifications/mail inside transactions; `after_commit => false` | `config/queue.php`, `CreditLedgerService`, `AnnouncementController::store`, `ParentProvisioningService` | A |
| A-16 | Starter-kit leftovers in `composer.json` | `composer.json` | A |
| A-17 | `OrderResource` reads non-existent `cashier->name`; POS receipt blank | `OrderResource` L47; POS `receipt-modal.tsx` L72 | B-07 |
| A-18 | Portal login reveals `account_disabled` before the password check | `Portal\AuthController::login` | B-08 |
| A-19 | `BranchScope` no-ops without active branch | `Models/Scopes/BranchScope.php` | B-02 |
| A-20 | Raw `User`/`Student` models in report rows leak PII; sales student name blank | `SalesReportController` L69, `BillingReportController`; POS sales/billing pages | B-12, B-13 |
| A-21 | `EnrollmentStatus::from()` on raw input, 500 on bad value | `StudentController::updateStatus` | B-14 |
| A-22 | Analytics reads credit limit from config, not System Settings | `AnalyticsController` L442 | B-11 |
| A-23 | Guards that run before validation (ordering hazard for Form Requests) | 18 methods, design §4.2.2 | A (preserve) |
| A-24 | `/references/inventory` omits archived items the POS page expects; unarchive unreachable | `InventoryController::index`; POS `references/inventory/page.tsx:894` | B-16 |
