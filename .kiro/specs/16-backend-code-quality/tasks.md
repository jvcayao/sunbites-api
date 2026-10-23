# Implementation Plan: Spec 16

## Ground rules (apply to every task)

- **Implementers start with [`HANDOFF.md`](./HANDOFF.md)**: the step-by-step protocol, the authoritative commit order (§6), stop conditions and report formats.

- **Non-negotiable tooling (Req 12, design §12):**
  - **Before any PHP work:** activate `laravel-best-practices` and read its mapped rule files (design §12.1).
  - **Before any test work:** activate `testing-best-practices`.
  - **Domain skills:** `laravel-excel` (exports), `laravel-permission-development` (roles), `fortify-development` (T-17.1), `activitylog-development` (any activity-log work, H5).
  - **Boost:** confirm the Laravel Boost MCP is connected (`vendor/bin/sail up -d`); run `application-info` at PR start and `search-docs` before using any Laravel or package API. Use `database-schema`/`database-query` before model, enum or raw-SQL changes (design §12.2). **Do not proceed without Boost.**
  - **Tooling log:** the PR description includes one block per commit listing skills, rule files read, and each `search-docs` query with the section used.
  - **Laravel Pint (non-negotiable, `.claude/rules/backend/laravel-pint.md`):** every backend task ends with `vendor/bin/sail bin pint --dirty --format agent` before it is reported done or committed. Before pushing the branch, `vendor/bin/sail bin pint --format agent` must report no changes (CI runs `pint --test`).
  - **Laravel 13 standards:** each task also applies the design §14.1 rows (S1–S10) assigned to it, and never introduces anything in §14.3.

- **Test environment (H18):** never run `config:cache`, `route:cache`, `event:cache` or `optimize` locally. Before each test run, `bootstrap/cache/` must contain only `packages.php` and `services.php` (run `vendor/bin/sail artisan config:clear` if unsure); otherwise tests can hit the real dev database.
- **Attributes (design §14.5):** use only the attributes marked *Adopt*. Never add the *Forbidden* Form Request attributes (H17: `#[FailOnUnknownFields]`, `#[StopOnFirstFailure]`, `#[ErrorBag]`, `#[RedirectTo]`, `#[RedirectToRoute]`), and never add any attribute marked N/A.
- **Before starting any task:**
  - Read `design.md` §3 (hazards H1–H18) and §14 (Laravel 13 standards, including the §14.5 attribute inventory).
  - Read the design section the task cites.
  - Read the current code of every file you will touch.
  - If the code differs from what the design describes, **stop and report**. Do not adapt silently.
- **Delivery (Q3, design §6; updated 2026-10-09):** **do not create a new branch.** All commits go on the current branch, `bump-laravel-13-latest`. Confirm with `git branch --show-current` before every commit, and never commit on `main`.
  - **One commit per task group** (T-1 … T-19, C-1) and **one commit per approved Track B item**, in the order of design §6.
  - Subjects use `type(scope): description` (imperative, < 72 chars) per `.claude/agents/git-workflow.md`.
  - **No `Co-Authored-By`, `Signed-off-by` or AI attribution** in commits or the PR.
  - `<spec16-start-sha>` = **`3454977`** (HEAD of `bump-laravel-13-latest` on 2026-10-09, before any Spec 16 commit). Record it in the T-1 commit body.
  - The first commit is the spec/steering/rules documents: `docs(spec16): add backend code quality spec, steering update and pint rule`.
  - After T-19, stop and tell the user. **Do not push** unless the user asks; the user pushes, opens the PR and merges it, preferably without squash (design §6).
- **Track A commits must not change any contract fixture:** the fixture digest (design §4.1.7) stays identical. Track B commits change only the fixtures listed in the task, proven by the per-file hash diff. **Fixtures are gitignored and are never committed:** never `git add -f` them.
- **Existing tests must not be edited**, except where a task explicitly names the edit: T-12.3 (one line), T-17.1 (`tests/TestCase.php` unused Fortify helper), B-01 (Arrange-only: branch links in `ParentAccountManagementTest` and `DashboardTest`, design §5.1), B-04 (deletions), B-05 (`BillingReportTest` seed fix, design §5.5).
- **`make:request` generates `authorize(): bool { return false; }`** (verified in the framework stub). Every Form Request in this spec MUST change it to `return true;`, since authorization lives in route middleware and `#[Authorize]`. Leaving `false` makes the endpoint return 403 for everyone. The contract suite catches this, but check it anyway.
- **Create files with Artisan**, then fill them in:
  - `vendor/bin/sail artisan make:request Kitchen/ListActivityLogsRequest --no-interaction`
  - `make:class`, `make:enum`, `make:middleware`, `make:resource`, `make:test --phpunit`
- **Verification gate (every task):**
  1. **MySQL gate** (design §11.1, exact command), all green
  2. **SQLite gate** (design §11.1, exact command), all green. **`vendor/bin/sail artisan test` is not a gate**: it runs SQLite, not MySQL
  3. **FE integration check:** re-read the design §13.2 rows for every endpoint the task touches, and list the §13.3 pages to smoke-test on staging in the PR description
  4. `vendor/bin/sail bin pint --dirty --format agent`
  5. Contract suite green with **no skipped tests**; fixture digest unchanged (Track A) or changed only in the listed files (Track B) (design §4.1.7)
  6. Re-read the diff against every hazard the task lists

---

## Track A

- [x] 1. Contract freeze harness and fixtures *(design §4.1; Req 1)*
  - [x] 1.1 Create the shape extractor
    - Create `tests/Support/Contract/ShapeExtractor.php` implementing the grammar in design §4.1.1 exactly (token names, check order, `ksort`, `__map__`, `__list__`, `__list_union__`, `list:empty`).
    - Write `tests/Unit/Contract/ShapeExtractorTest.php`, extending `PHPUnit\Framework\TestCase` (pure logic), with one test per token, plus:
      - key order is ignored
      - mixed lists produce a union
      - `mapPaths` collapse data keys
      - `"150.00"` gives `string:decimal2`, not `string`
      - `"2026-10-15T09:30:00.000000Z"` gives `string:iso8601_micro`
    - _Requirements: 1.1, 1.8_
  - [x] 1.2 Create the assertion trait
    - Create `tests/Support/Contract/AssertsApiContract.php` with `assertContract()` and `assertFileContract()` (design §4.1.2): slug rule, fixture path, record mode via `CONTRACT_RECORD=1`, failure messages verbatim, pretty JSON flags.
    - Unit test: the slug for `GET api/v1/students/{student}` is `get__api_v1_students_{student}`; record mode writes a file; compare mode fails on a changed shape.
    - _Requirements: 1.1, 1.2, 1.6_
  - [x] 1.3 Create the deterministic scenario
    - Create `tests/Support/Contract/ContractScenario.php` building every record in design §4.1.3 with existing factories and states only (attribute overrides allowed where no state exists, e.g. the voided payment; no new factory states) (`Student::factory()->subscription()`, `StudentMonthlyPayment::factory()->paid()`, `Order::factory()->credit()`, `->voided()`, `PreRegistration::factory()->pending()/approved()/expired()`, `ParentUserFactory::unactivated()`, …). Freeze time with `travelTo('2026-10-15 09:30:00')`.
    - Expose typed public properties (`$branchA`, `$branchB`, `$admin`, `$manager`, `$supervisor`, `$cashier`, `$subscriptionStudent`, …) and helpers `asStaff(User $u)` and `asParent(ParentUser $p)` following the `tests/Feature/PosCheckoutTest.php` pattern.
    - Test (`tests/Feature/Contract/ContractScenarioTest.php`): builds twice in separate tests with identical counts per table; branch B data is not visible with the branch A header.
    - _Requirements: 1.1_
  - [x] 1.4 Write the contract tests for every route
    - One test class per controller under `tests/Feature/Contract/{Kitchen,Portal,Public}/{Controller}ContractTest.php`.
    - For each route in `php artisan route:list --path=api --except-vendor` (165 on 2026-09-26), write the variants required by design §4.1.4.
    - Use `Http::fake` for reCAPTCHA, and `Mail::fake()`/`Notification::fake()`/`Storage::fake('private')`/`Storage::fake('public')` where the endpoint sends or stores.
    - Declare `mapPaths` for data-keyed maps. At minimum: `summary.grade_breakdown`, `summary.status_breakdown` (student report); `school_month_defaults` (enrollment index); `subscription_monthly_status.categories`, `subscription_daily_status` (lookup/portal); `payment_method_split` (spending summary); `settled_by_method` (credit report).
    - Every contract test extends `Tests\Feature\Contract\ContractTestCase` (design §4.1.2), which sets `config(['app.debug' => false])`.
    - Record on **MySQL** with the MySQL gate (design §11.1), adding `-e CONTRACT_RECORD=1` before `laravel.test` and `tests/Feature/Contract` at the end.
    - Then create SQLite overrides only where shapes differ, with the SQLite gate plus `-e CONTRACT_RECORD=1 -e CONTRACT_DRIVER_OVERRIDE=1` and `tests/Feature/Contract` (design §4.1.2, H15). Then run again **without** the flags on both drivers; both must be green with no diffs. List every `.sqlite.json` override in `contract-gaps.md`.
    - _Requirements: 1.1, 1.2, 1.3_
  - [x] 1.5 Add the route coverage test
    - `tests/Feature/Contract/RouteCoverageTest.php` per design §4.1.5. It must pass after 1.4.
    - Also add a test that asserts the route count is 165, with a comment explaining the number, so an added or removed route is a visible, deliberate change.
    - _Requirements: 1.4_
  - [x] 1.6 Add the role authorization matrix
    - `tests/Feature/Contract/RoleAuthorizationMatrixTest.php` per design §4.1.5: parameter resolver by URI, the exceptions array, and the 401 checks selected **by middleware** (`auth:parents` / `auth:sanctum`), excluding public routes.
    - **No data provider:** PHPUnit 12 runs providers before Laravel boots, so `Route::getRoutes()` would fail there. Write one test method per role that iterates the routes at runtime and asserts once on the collected mismatches (design §4.1.5).
    - _Requirements: 1.5_
  - [x] 1.7 Cross-check against the frontend types
    - Create `.kiro/specs/16-backend-code-quality/contract-gaps.md` seeded with the five rows in design §4.1.6, then extend it from design §13.1 (unused routes) and §13.2.
    - For every fixture, compare it with its FE type (`rg -n "apiClient\.(get|post|put|patch|delete)<" ~/sunbites-pos/lib/api ~/sunbites-portal/lib/api`) and add a row per mismatch. Do **not** fix mismatches here.
    - No test is needed (this is documentation); the PR description links the file.
    - _Requirements: 1.7_
  - [x] 1.8 Keep fixtures out of git and keep CI green without them *(design §4.1.7)*
    - `/tests/Fixtures/contracts` is already in `.gitignore` (added by the spec owner on 2026-10-12). Do not change that line, and never `git add -f` a fixture. Before committing, confirm that `git status --porcelain` lists no fixture and that `git check-ignore -q 'tests/Fixtures/contracts/get__api_health@ok.json'` exits 0.
    - Implement the skip rule in `AssertsApiContract` and `RouteCoverageTest`, and the `AssertsApiContractTest` changes (`mkdir` in `setUp()`, the new skip test), exactly as design §4.1.7 states.
    - Prove the CI behaviour locally:
      1. Note the digest.
      2. Rename the folder in place: `mv tests/Fixtures/contracts tests/Fixtures/contracts.ci-check`.
      3. Run the SQLite gate with `tests/Feature/Contract tests/Unit/Contract`. Expect zero failures and zero errors, with only skips.
      4. Rename it back: `mv tests/Fixtures/contracts.ci-check tests/Fixtures/contracts`.
      5. Confirm the digest is unchanged and the full gates show no skips.
      Do not commit between the two renames.
    - Put `Fixtures: <count> files, digest <digest> (local only, not committed)` in the T-1 commit body.
    - _Requirements: 1.9_

- [x] C-1. MySQL test job in CI *(approved 2026-10-12; design §15; Req 14)*
  - [x] C-1.1 Add the `test-mysql` job
    - Add the job exactly as written in design §15 to `.github/workflows/ci.yml`, after the existing `test` job. Do not change the `test` job, `staging.yml` or `production.yml`.
    - Tests: the design §15 verification steps 1–3 (YAML parses; only additions in the diff; the MySQL gate passes including `tests/Feature/Contract`). Step 4 (the real GitHub run) is the user's, on the PR.
    - Commit: `chore(ci): add MySQL 8.4 test job to CI workflow`.
    - _Requirements: 14.1–14.5_

- [x] 2. Base authorization and policy wiring *(design §4.2.1 list of 14 calls, §4.5.3; Req 4.5, 6.2, 6.4)*
  - [x] 2.1 Move `AuthorizesRequests` to the base controller
    - Add `use AuthorizesRequests;` to `app/Http/Controllers/Controller.php`.
    - Remove the 7 per-controller `use AuthorizesRequests;` statements and their imports.
    - Tests: the existing suite passes; fixtures are unchanged.
    - _Requirements: 6.4_
  - [x] 2.2 Replace `$this->authorize()` with `#[Authorize]`
    - Replace all 14 calls with the attributes in the design §4.2.1 table (`use Illuminate\Routing\Attributes\Controllers\Authorize;`). Delete the call lines.
    - Confirm `rg -n '\$this->authorize\(' app` returns nothing afterwards.
    - Tests: add `tests/Feature/Kitchen/AuthorizeAttributeTest.php` with one test per attribute asserting 403 `{"message":"This action is unauthorized."}` (today's production body, H19; `app.debug=false` in `setUp()`) for a denied actor (e.g. a parent without a link to the student, supervisor voiding when the policy denies, and a user deactivating self).
    - Matrix and fixtures are unchanged.
    - _Requirements: 6.2_
  - [x] 2.3 Bind policies with `#[UsePolicy]`
    - Add `#[UsePolicy]` to `Order`, `User` and `Student` (design §4.5.3), and remove the four `Gate::policy()` lines and their imports from `AppServiceProvider`.
    - Run `rg -n "ReportPolicy|StudentPolicy" app routes tests`. If only the class files match, delete `app/Policies/ReportPolicy.php` and `app/Policies/StudentPolicy.php`. If tests match, stop and report.
    - Tests: `tests/Unit/PolicyResolutionTest.php` (extends `Tests\TestCase`; needs Gate) asserts `Gate::getPolicyFor(Order::class)` is an `OrderPolicy` (likewise `User` and `Student`); the suite and fixtures are unchanged.
    - _Requirements: 4.5_

- [x] 3. POS domain: checkout, void, stock *(design §4.3.1–4.3.3, §4.2.1/4.2.2 rows for CheckoutController and TransactionController; hazards H1, H3, H5, H10)*
  - [x] 3.1 Create `InventoryStockService`
    - Create `app/Services/InventoryStockService.php` with the four methods and the exact write styles in design §4.3.1 (query builder for sale, Eloquent for restock and adjust).
    - Tests: `tests/Feature/Kitchen/InventoryStockServiceTest.php` covers:
      - sale deduction writes the stale-quantity value (reproduce H10: two cart lines sharing an item end at `start - 1`, not `start - 2`). This test is **deleted by B-03**, which is allowed there.
      - the sale log fields
      - restock writes a Restock log and an `inventory.updated` activity row
      - adjust add/deduct clamps at 0 and writes a log
      - initial stock writes an `'Initial stock'` log
    - _Requirements: 2.3_
  - [x] 3.2 Create `CheckoutRequest`
    - Create `app/Http/Requests/Kitchen/CheckoutRequest.php` with the 12 rules copied verbatim.
    - Tests: covered by fixtures (the `422-validation` variant); add one test sending `payment_method=bitcoin` and asserting the same `errors.payment_method` message as before the move.
    - _Requirements: 3.1, 3.2_
  - [x] 3.3 Create `CheckoutAction` and slim the controller
    - Create `app/Actions/Pos/CheckoutAction.php` per design §4.3.2 (order of steps 1–10, every message verbatim; walk-in guards as `HttpResponseException`, others `abort`). Reduce `CheckoutController::store` to the 3-line body shown in the design.
    - Tests: include a cashier checkout **without** a discount that succeeds (proves the discount check stays conditional). `tests/Feature/Kitchen/CheckoutActionTest.php` has one test per guard message (walk-in wallet, walk-in subscription, unmapped item, out of stock, discount 403, not enrolled, non-subscription student, ineligible item, daily limit, insufficient balance, credit limit exceeded), plus:
      - loyalty points
      - `WalletAlertJob` dispatched only for wallet orders (`Queue::fake()`)
      - `pos.order_created` and `pos.discount_applied` activity rows
    - All `PosCheckoutTest`, `PosInsufficientFundsTest`, `SubscriptionCheckoutTest` and `PosWalletLockTest` tests pass unchanged.
    - _Requirements: 2.1, 2.2, 2.5, 2.6_
  - [x] 3.4 Create `VoidOrderRequest` and `VoidOrderAction`
    - `app/Http/Requests/Kitchen/VoidOrderRequest.php` has the already-voided guard in `prepareForValidation()` (design §4.2.2) plus `void_reason` required|string|max:500.
    - `app/Actions/Pos/VoidOrderAction.php` per design §4.3.3. The controller method keeps `#[Authorize('void', 'order')]` from T-2.
    - Tests: `tests/Feature/Kitchen/VoidOrderActionTest.php` covers:
      - wallet refund excludes the credit portion
      - credit reversal via the ledger
      - restock logs
      - loyalty recompute
      - `pos.order_voided` activity
      - **precedence:** an already-voided order with a missing `void_reason` returns 422 `"Order is already voided."`, not a validation error
    - `PosVoidTest` and `CreditVoidReversalTest` pass unchanged.
    - _Requirements: 2.1, 2.5, 3.3_
  - [x] 3.5 Form Requests for the remaining POS endpoints
    - `ListTransactionsRequest`, `InlineReloadRequest` and `StudentLookupRequest` per the design §4.2.1 table.
    - Tests: fixtures unchanged.
    - _Requirements: 3.1_

- [x] 4. Wallet and credit *(design §4.3.4, §4.4 `CreditLedgerEntryResource`; hazards H1, H3)*
  - [x] 4.1 Create `TopUpWalletRequest`
    - Keep `strip_tags(note)` at the point of use.
    - Tests: fixtures unchanged; `WalletTopUpTest` unchanged.
    - _Requirements: 3.1, 3.5_
  - [x] 4.2 Create `VoidWalletTopUpAction`
    - Create `app/Actions/Wallet/VoidWalletTopUpAction.php` moving the body verbatim (design §4.3.4). The controller keeps the `int|string $transaction` parameter and its comment.
    - Tests: `tests/Feature/Kitchen/VoidWalletTopUpActionTest.php` has one test per guard (not found ×2, already voided, self-void, outside today for non-admin, admin outside today allowed), full and partial recovery, and the shortfall charged as credit. `WalletTopUpVoidTest` passes unchanged.
    - _Requirements: 2.1, 2.2_
  - [x] 4.3 Create `CreditLedgerEntryResource`
    - Replace `CreditController::entryPayload()` with `app/Http/Resources/CreditLedgerEntryResource.php`, nested as `'transaction' => new CreditLedgerEntryResource($entry)` (H6).
    - Tests: fixtures unchanged; `CreditSettlementTest` and `CreditWaiveTest` unchanged.
    - _Requirements: 5.1, 5.2_
  - [x] 4.4 Wallet history and ledger requests
    - Create `ListWalletHistoryRequest` (`per_page` max 50, H13) and the shared `app/Http/Requests/StudentLedgerRequest.php`. Change the `ResolvesStudentLedger` trait signature (design §4.2.1).
    - Tests: fixtures unchanged; `StudentLedgerTest` unchanged; plus a portal precedence test: an unlinked parent with `per_page=999` gets 403, not 422.
    - _Requirements: 3.1, 3.4_

- [x] 5. Students, enrollment, payments, contacts *(design §4.3.6, §4.2.1–4.2.3; hazards H3, H4, H8, H13)*
  - [x] 5.1 Create `EnrollStudentRequest` and `EnrollStudentAction`
    - End-before-start goes in `passedValidation()` as an errors-only `HttpResponseException`.
    - Tests: `tests/Feature/Kitchen/EnrollStudentActionTest.php` covers:
      - contacts `is_primary` by index
      - every emailed contact provisioned
      - photo stored on the `private` disk
      - `students.enrolled` activity
    - Plus an assertion that the end-before-start response body has **no** `message` key.
    - Plus (H16) enrolling a subscription student from June to March creates exactly 10 monthly payments and the request returns, which guards against an immutable-date infinite loop.
    - `EnrollmentTest` is unchanged.
    - _Requirements: 2.1, 3.1, 3.2_
  - [x] 5.2 `StudentController` requests
    - Create `ListStudentsRequest` (permissive, §4.2.3), `UpdateStudentRequest`, `UpdateStudentStatusRequest` (copy `from()` as is, H-note), `UpdateStudentTypeRequest`, `UploadStudentPhotoRequest` and `ListStudentOrdersRequest`.
    - Tests: fixtures unchanged, plus a test that `GET /students?deleted=1&type=all&month=june&year=abc` still returns 200 (permissive rules).
    - _Requirements: 3.1, 3.6_
  - [x] 5.3 `PaymentController` requests
    - Create `RecordPaymentRequest`, `AddPaymentRangeRequest` (errors-only in `passedValidation()`), `UpdatePaymentAmountRequest` and `VoidPaymentRequest` with the `prepareForValidation()` guards in design §4.2.2, in the same order.
    - Tests, precedence:
      - `updateAmount` on a paid payment with a non-numeric amount → 422 `"Can only edit amount on unpaid payments."`
      - `void` on another student's payment with an empty body → 404
      - `void` on an unpaid payment with an empty body → 422 `"Only paid payments can be voided."`
    - (H16) `addRange` June→March creates the expected rows and returns.
    - `PaymentControllerTest`, `VoidPaymentTest` and `PaymentRangeTest` unchanged.
    - _Requirements: 3.3_
  - [x] 5.4 `StudentContactController` requests
    - Create `StoreStudentContactRequest` and `UpdateStudentContactRequest` (404 guard in `prepareForValidation()`).
    - Tests, precedence: updating another student's contact with invalid email → 404 `"Contact does not belong to this student."`. `StudentContactTest` unchanged.
    - _Requirements: 3.3_

- [x] 6. Staff, branches, configuration *(design §4.2.1; hazard H7)*
  - [x] 6.1 Kitchen auth requests
    - Create `StaffLoginRequest`, `SendStaffResetLinkRequest`, `ResetStaffPasswordRequest` (keep `$request->only(...)`, H7) and `SwitchBranchRequest`.
    - Tests: fixtures unchanged; `StaffAuthTest`/`AuthTest` unchanged; plus a test that password reset still succeeds, which proves `password_confirmation` reaches the broker.
    - _Requirements: 3.1, 3.7_
  - [x] 6.2 User management requests
    - Create `ListStaffUsersRequest` (permissive), `StoreStaffUserRequest`/`UpdateStaffUserRequest` sharing the `App\Http\Requests\Kitchen\Concerns\StaffUserRules` trait, `UploadUserPhotoRequest` and `AssignUserBranchRequest`.
    - Tests: fixtures unchanged; `UserManagementTest` unchanged; precedence: a manager uploading a photo for a user the policy denies with no file → 403.
    - _Requirements: 3.1, 3.4, 3.6_
  - [x] 6.3 Branch and configuration requests
    - Create `UpdateBranchRequest`, `UpdateSystemConfigurationRequest` (memoised `configuration()`, 404 before 422), `UpdateSubscriptionConfigRequest`, and `ListBranchMonthlyAmountsRequest`/`StoreBranchMonthlyAmountRequest`/`UpdateBranchMonthlyAmountRequest`.
    - Tests: fixtures unchanged, plus `PUT /system-configurations/unknown_key` with an empty body → 404 `{"message":"Not found."}`.
    - _Requirements: 3.1, 3.3_

- [x] 7. Inventory, menu, meal planner *(design §4.3.1, §4.4, §4.6; hazards H5, H6)*
  - [x] 7.1 Inventory controller
    - `InventoryController` uses `InventoryStockService::adjust()`/`recordInitialStock()`. Create `StoreInventoryItemRequest`, `UpdateInventoryItemRequest`, `AdjustInventoryRequest` and `ListInventoryHistoryRequest`.
    - Tests: fixtures unchanged; `InventoryTest` unchanged; the activity rows for adjust are identical in count and description (assert against the `activity_log` table).
    - _Requirements: 2.3, 3.1_
  - [x] 7.2 Inventory resources and shared scope
    - Create `InventoryItemResource` and `InventoryLogResource` replacing `formatItem`/`formatLog` and the report's log map.
    - Add the `InventoryLog::forActiveItemsInBranch` `#[Scope]` (design §4.6), used in 3 places. Do not change `buildDiscrepancySection`.
    - Tests: fixtures unchanged; `InventoryReportTest` unchanged.
    - _Requirements: 5.1, 7.5_
  - [x] 7.3 Menu item and ingredient resources
    - Create `PosMenuItemResource` (with `resolveInventoryStatus`), `SavePosMenuItemRequest`, `InventoryIngredientResource` and `AttachIngredientRequest`.
    - Tests: fixtures unchanged; `PosMenuItemTest` and `InventoryIngredientTest` unchanged.
    - _Requirements: 3.1, 5.1_
  - [x] 7.4 Meal planner requests
    - Create `ShowMealPlanRequest` (permissive), `UpdateMealPlanRequest`, `ResetMealPlanWeekRequest` and `UpdateMealPlanWeekVisibilityRequest`.
    - Tests: fixtures unchanged; `MealPlannerTest` unchanged.
    - _Requirements: 3.1, 3.6_

- [x] 8. Pre-registration (kitchen and public) *(design §4.3.5, §4.2.2; hazard H3)*
  - [x] 8.1 Create `ApprovePreRegistrationAction`
    - Create `app/Actions/PreRegistrations/ApprovePreRegistrationAction.php` per design §4.3.5.
    - Tests: `tests/Feature/Kitchen/ApprovePreRegistrationActionTest.php` covers:
      - only the primary contact with an email is provisioned
      - all contacts are copied
      - both duplicate 422 messages
      - the approved mail is queued to the primary, falling back to the first contact
    - `PreRegistrationTest` and `PreRegistrationApprovalDuplicateTest` unchanged.
    - _Requirements: 2.1, 2.2_
  - [x] 8.2 Kitchen pre-registration requests
    - Create `ListPreRegistrationsRequest`, `UpdatePreRegistrationRequest` and `RejectPreRegistrationRequest` (pending guards as `HttpResponseException`).
    - Tests, precedence: rejecting an approved record with an empty body → 422 `"Only pending pre-registrations can be rejected."`.
    - _Requirements: 3.3_
  - [x] 8.3 Public pre-registration request
    - Create `app/Services/RecaptchaVerifier.php` and `StorePublicPreRegistrationRequest` (honeypot, then captcha in `prepareForValidation()`; errors-only month check in `passedValidation()`; `recaptchaScore()`).
    - Tests: `tests/Feature/Public/PreRegistrationTest.php` unchanged, plus:
      - honeypot with an invalid body → 201 and no record created
      - `Http::fake` throwing `ConnectionException` → 503 and a logged warning (`Log::spy()`)
      - a low score with an invalid body → 422 `"Submission could not be verified. Please try again."`
    - _Requirements: 3.3_

- [x] 9. Reminders, announcements, notifications, feedback, dashboard, kitchen parents *(design §4.3.7, §4.4, §4.6; hazard H11)*
  - [x] 9.1 Create `PaymentReminderService`
    - Create `app/Services/PaymentReminderService.php` per design §4.3.7, moving the logic verbatim including the `+1` year logic (H11), with Req 7.4 applied in `send()`.
    - Tests: `tests/Feature/Kitchen/PaymentReminderServiceTest.php` covers unpaid periods excluding future months, due date, bell count, and the `send` force/skip paths; plus a query-count test (N=1 vs N=5 parents). `ReminderTest` unchanged.
    - _Requirements: 2.1, 7.4, 7.6_
  - [x] 9.2 Reminder requests
    - Create `ListEligibleParentsRequest` (+ permissive `page`) and `SendRemindersRequest`.
    - Tests: fixtures unchanged.
    - _Requirements: 3.1, 3.6_
  - [x] 9.3 Announcement N+1 fixes
    - Implement the read-count and recipient queries in `AnnouncementController` per design §4.6, and create `StoreAnnouncementRequest`.
    - Tests: query-count tests for index (N=1 vs 5 announcements) and show (N=1 vs 5 recipients); `AnnouncementTest` unchanged; fixtures unchanged.
    - _Requirements: 7.1, 7.2, 7.6_
  - [x] 9.4 Notification resource
    - Create `NotificationResource`, used by the Portal and Staff notification index.
    - Tests: fixtures unchanged; `NotificationTest`/`StaffNotificationTest` unchanged.
    - _Requirements: 5.1_
  - [x] 9.5 Credit notification debounce
    - Rewrite the `CreditLedgerService::notifyParentsOfCharge` debounce as an `exists()` query (design §4.6).
    - Tests: `CreditNotificationTest` unchanged, plus a query-count test with 1 vs 5 prior notifications that day.
    - _Requirements: 7.3, 7.6_
  - [x] 9.6 Feedback, dashboard and parent requests
    - Create `ListFeedbackRequest`, `ReplyFeedbackRequest` (sanitise in `prepareForValidation()`), `UpdateStaffStatusRequest` and `ListParentsRequest`.
    - Tests: precedence: a reply of `"<br><br>"` → 422 on `reply` (`min:5` applied to sanitised text); fixtures unchanged.
    - _Requirements: 3.1, 3.5_

- [ ] 10. Reports *(design §4.2.1, §4.6; hazards H1, H8)*
  - [ ] 10.1 Sales report
    - Create `ExportSalesReportRequest` and `ListSalesReportRequest extends` it, and add the private `filteredOrders()` builder.
    - Tests: fixtures unchanged; `SalesReportTest` unchanged; `GET /reports/sales/export?per_page=500` still returns 200 (H8).
    - _Requirements: 3.1, 7.5_
  - [ ] 10.2 Billing and student reports
    - Create `ExportBillingReportRequest`/`ListBillingReportRequest` and `ExportStudentReportRequest`/`ListStudentReportRequest`.
    - Tests: fixtures unchanged, plus the same export `per_page` test.
    - _Requirements: 3.1_
  - [ ] 10.3 Remaining report requests
    - Create `ShowInventoryReportRequest`, `ExportInventoryReportRequest`, `ShowSubscriptionReportRequest`, `ListWalletReportRequest`, `ListCreditReportRequest`, `ShowDailySummaryRequest`, `ListActivityLogsRequest` and `ShowAnalyticsRequest`.
    - Tests: fixtures unchanged.
    - _Requirements: 3.1_

- [ ] 11. Portal *(design §4.2.1 Portal rows; hazards H4, H7, H13)*
  - [ ] 11.1 Portal auth and profile requests
    - Create `ParentLoginRequest`, `ParentForgotPasswordRequest`, `ResetParentPasswordRequest` (keep `only()`, `min:8`), `UpdateParentProfileRequest`, `ChangeParentPasswordRequest` and `UploadParentPhotoRequest`.
    - Tests: fixtures unchanged; `PortalAuthTest` and `ProfileTest` unchanged.
    - _Requirements: 3.1_
  - [ ] 11.2 Portal feedback request
    - Create `StoreFeedbackRequest` with the memoised `linkedStudents()` and the guard in design §4.2.2.
    - Tests, precedence: a parent with no linked students sending an empty body → 422 `"You have no linked students."`. `FeedbackTest` unchanged.
    - _Requirements: 3.3, 3.5_
  - [ ] 11.3 Remaining portal requests
    - Create `ListStudentActivityRequest`, `ShowSpendingSummaryRequest`, `UploadPortalStudentPhotoRequest` (`max:5120`), `ListPortalWalletRequest`, `SetWalletAlertRequest` and `ShowPortalMealPlanRequest`.
    - Tests: fixtures unchanged, plus a precedence test for each `#[Authorize]` + request pair (an unlinked parent with invalid input → 403).
    - _Requirements: 3.1, 3.4_
  - [x] 11.4 Portal pre-registration requests: **N/A**
    - B-04 was approved on 2026-10-09, so both portal pre-registration routes and controllers are removed. Do not create `CheckPreRegistrationRequest` or `StorePortalPreRegistrationRequest`. B-04's commit must come before T-11 (design §6).
    - _Requirements: 3.1_

- [ ] 12. Model modernisation *(design §4.5; hazard H2)*
  - [ ] 12.1 Scopes, accessors and casts
    - `#[Scope]` on `InventoryItem`, an `Attribute` accessor on `PreRegistration`, `casts()` on `ParentPaymentReminder`, and `EnrollmentStatus::Enrolled` in `EnrollmentService`.
    - Tests: fixtures unchanged (`PreRegistration::toArray()` still has no `full_name`; assert this explicitly in a unit test).
    - _Requirements: 4.1, 4.2, 4.4_
  - [ ] 12.2 Report literals to enums
    - Replace `'subscription'`/`'non_subscription'`/`'completed'` literals in **Eloquent** (not raw-SQL) queries in report controllers with `StudentType::Subscription`, `StudentType::NonSubscription` and `OrderStatus::Completed`. Grep: `rg -n "'(subscription|non_subscription|completed)'" app/Http/Controllers`.
    - Tests: fixtures unchanged.
    - _Requirements: 4.4_
  - [ ] 12.3 `PaymentStatus` enum cast
    - Create `app/Enums/PaymentStatus.php` (design §4.5.1), add the cast, and change **exactly** the sites in the design §4.5.2 table, including `tests/Feature/EnrollmentTest.php:106` (the only permitted test edit).
    - Tests: `tests/Unit/PaymentStatusTest.php` (extends `PHPUnit\Framework\TestCase`; labels); billing export totals are correct with paid and unpaid rows (this would have silently become 0 without the collection fix); fixtures unchanged.
    - _Requirements: 4.3_
  - [ ] 12.4 Post-cast grep check
    - Run the grep in design §4.5.2 and paste its output into the PR description, with each hit classified as safe.
    - _Requirements: 4.3_

- [ ] 13. Lazy-loading guard *(design §4.5.4; hazard H14)*
  - [ ] 13.1 Enable the guard and fix violations
    - Add the `configureDefaults()` code, run the full suite, and fix every `LazyLoadingViolationException` with eager loading at the query site. Never use model `$with`.
    - Tests: the full suite is green; fixtures unchanged; plus unit tests that the violation handler logs rather than throws when the environment is `local` **and** when it is `staging` (`app()->detectEnvironment(fn () => 'local')`, `Log::spy()`), and that it throws in `testing`.
    - _Requirements: 4.6_

- [ ] 14. Notifications after commit *(design §4.7)*
  - [ ] 14.1 `ShouldQueueAfterCommit` on the five notifications
    - Change the 5 notification classes from `ShouldQueue` to `ShouldQueueAfterCommit` (design §4.7). No constructor change.
    - Tests: `tests/Feature/Kitchen/NotificationAfterCommitTest.php` uses **no fakes** (design §4.7; `QueueFake` ignores after-commit):
      - **Rollback:** call `CreditLedgerService::charge` for a student with a linked parent inside `DB::transaction(...)` that then throws (catch it). Assert `DB::table('notifications')->where('notifiable_id', $parent->id)->count() === 0`.
      - **Commit:** the same without the throw. Assert the count is `1`.
      - Existing notification tests are unchanged.
    - _Requirements: 8.1, 8.4, 8.5_
  - [ ] 14.2 `ShouldQueueAfterCommit` on the welcome mail
    - Change `ParentWelcomeMail` from `ShouldQueue` to `ShouldQueueAfterCommit` (design §4.7). No call-site change.
    - Tests (no `Mail::fake()`): `Event::fake([MessageSending::class])`.
      - **Rollback:** call `ParentProvisioningService::provision()` for a new email inside a transaction that throws; assert `Event::assertNotDispatched(MessageSending::class)`.
      - **Commit:** the same without the throw; assert it was dispatched once.
    - _Requirements: 8.2_
  - [ ] 14.3 `WalletAlertJob` retries *(Q7 approved)*
    - Add `#[Tries(3)]` and `#[Backoff(10, 60)]` (`Illuminate\Queue\Attributes\Tries`, `…\Backoff`) to `WalletAlertJob`.
    - Test: resolve the attributes via reflection and assert `3` and `[10, 60]`; `WalletAlertTest` unchanged.
    - _Requirements: 8.3_

- [ ] 15. Project hygiene *(Req 9.1)*
  - [ ] 15.1 `composer.json` metadata and scripts
    - Update `composer.json` `name`/`description` and remove the npm steps from the `dev` and `ci:check` scripts. Do not touch `require`/`require-dev`. Run `vendor/bin/sail composer validate`.
    - Tests: none (no behaviour); CI green.
    - _Requirements: 9.1_

- [ ] 16. Laravel 13 model PHP attributes *(Q4 approved; design §4.5.5; Req 4.7)*
  - [ ] 16.1 Snapshot test first
    - Create `tests/Unit/ModelAttributesTest.php` (extends `Tests\TestCase`; model construction boots traits that use the container). For every model in `app/Models/`, assert `getTable()`, `getFillable()` (exact array and order), `getHidden()`, `usesTimestamps()`, `getKeyName()` and `getCasts()` equal literals copied from the current code.
    - It must pass on the unconverted code before 16.2 starts.
    - _Requirements: 4.7_
  - [ ] 16.2 Convert every model
    - Move `$table` → `#[Table('…')]`, `$fillable` → `#[Fillable([...])]` (same order), `$hidden` → `#[Hidden([...])]` and `public $timestamps = false` → `#[WithoutTimestamps]` (imports from `Illuminate\Database\Eloquent\Attributes\`), exactly per the design §4.5.5 table.
    - Keep `UPDATED_AT`/`CREATED_AT` constants, `$recordEvents`, `casts()`, `HasBranch` and `getDefaultGuardName()` as they are.
    - Add the 6 missing `/** @use HasFactory<XFactory> */` docblocks and a generic `@return` docblock on all 52 relation methods.
    - Tests: `ModelAttributesTest` is unchanged and still passes; full suite green; fixtures unchanged.
    - _Requirements: 4.7, 13.1_

- [ ] 17. Dependency and surface cleanup *(Q6 approved on condition "must not affect the API"; design §4.8; Req 11)*
  - [ ] 17.1 Broadcast auth tests, then removals
    - Record `vendor/bin/sail artisan route:list --path=api --except-vendor --json > /tmp/api-routes-before.json`.
    - Add `tests/Feature/Foundation/BroadcastChannelAuthTest.php` (design §4.8 step 1) and make it pass on the current code.
    - Run `vendor/bin/sail composer remove laravel/fortify binafy/laravel-cart`.
    - Delete `config/fortify.php`; remove `skipUnlessFortifyHas()` and its import from `tests/TestCase.php` (permitted test edit; 0 callers).
    - Drop `channels:` from `withRouting()` in `bootstrap/app.php` and add `require base_path('routes/channels.php');` (with a docblock) to `AppServiceProvider::boot()`.
    - Run `vendor/bin/sail artisan boost:update`, and review and keep only the Fortify-related skill and guideline removals.
    - Tests:
      - `BroadcastChannelAuthTest` still passes
      - `route:list --path=api --except-vendor --json` is byte-identical to `/tmp/api-routes-before.json` (`diff` shows nothing)
      - `route:list --only-vendor` has no Fortify routes and no top-level `broadcasting/auth`
      - contract suite has zero diffs; full suite green on both databases
    - _Requirements: 11.1–11.5_

- [ ] 18. Laravel 13 standards audit *(design §14.4; Req 13.3)*
  - [ ] 18.1 Run the audit and fix leftovers
    - Run every command in design §14.4.
    - Each must return its expected result. Fix any leftover behaviour-identically in this commit, or record it as an exception with its reason.
    - Paste the command output into the commit body.
    - **Named edit (comment only):** correct the stale docblock on `tests/Feature/Contract/Kitchen/PosMenuItemContractTest::test_deleting_a_menu_item_matches_contract`. Since B-20, deleting a sold item returns 422 (`@422-has-sales`) instead of failing on the `order_items` foreign key. Change no code in that file.
    - Tests: full verification gate (both databases, fixture digest unchanged, role matrix, `vendor/bin/sail bin pint --format agent` reporting no changes).
    - _Requirements: 13.1, 13.2, 13.3, 13.4_

---

- [ ] 19. Final `laravel-simplifier` pass *(non-negotiable; design §12.3; Req 12.6, 12.7)*
  - [ ] 19.1 Run the simplifier over the complete backend diff
    - Starts only after **every** Track A commit, every approved Track B commit and T-18 are on `bump-laravel-13-latest`.
    - Build the file list from `git diff --name-only <spec16-start-sha>..HEAD -- app routes bootstrap config`. `<spec16-start-sha>` is `3454977`, recorded in the T-1 commit body.
    - Invoke the `laravel:laravel-simplifier` agent with the verbatim fenced prompt in design §12.3 plus the file list.
    - Tests: none new. Run the full verification gate: MySQL + SQLite suites green with no skipped tests, contract-fixture digest unchanged (design §4.1.7), role matrix green, Pint (`--dirty --format agent`, then `vendor/bin/sail bin pint --format agent` reporting no changes).
    - Revert (don't fix) any individual simplifier change that fails the gate.
    - Commit as `refactor(spec16): apply laravel-simplifier pass`. The commit body lists applied changes, rejected suggestions (`rejected: H#`) and reverted changes.
    - Then stop and tell the user the work is complete. Do not push unless asked (design §6).
    - _Requirements: 12.6, 12.7_

---

## Track B (23 items approved: B-01–B-05 and B-07–B-16 on 2026-10-09, B-17–B-19 on 2026-10-12, B-20–B-24 on 2026-10-17; one commit each; failing test first)

- [x] B-01. Cross-branch access *(design §5.1; after T-9 and T-7)*
  - Write failing tests: a branch-A manager gets 404 on each `ParentController` action for a branch-B-only parent; 404 on unarchive of a branch-B item; 422 on staff-status for a branch-B user. Then implement.
  - Fixtures: the T-1 `other-branch` variants for these routes change from 200 to 404 (staff-status: 422); no new variants (design §5.1).
  - **Named edits to existing tests (Arrange only, decided 2026-10-23; no assertion changes):** design §5.1 *Existing tests*.
  - _Requirements: 10.2_
- [ ] B-02. Branch header required *(design §5.2; after T-6 at minimum. Actual slot: after T-7, T-9, B-01 and B-16, per `HANDOFF.md` §6)*
  - Create `EnsureActiveBranch` via `make:middleware`, register the alias, apply it to the group with the exact allowlist, and remove the dead `MealPlannerController` checks.
  - Failing tests: a branch-scoped list without a header returns 422 with the exact message; each allowlisted route works without a header; the matrix test still passes.
  - Fixtures: new `422-no-branch` variants.
  - **Staging smoke test before production.**
  - _Requirements: 10.3_
- [x] B-03. Stock race *(design §5.3; after T-3 and T-7)*
  - Failing test: two cart lines sharing one inventory item deduct both units (replaces the H10 test from T-3.1, which is deleted here). Also: an ordered-lock query is issued (assert via the query log); adjust inside a transaction.
  - Fixtures: none.
  - _Requirements: 10.4_
- [ ] B-04. Remove unused portal pre-registration routes *(design §5.4; before T-11)*
  - Remove the routes, both controllers and both test files (approved deletion).
  - Tests: route coverage count becomes 163 (update the test and remove the two fixture sets); `POST api/v1/portal/pre-registrations` returns 404/405.
  - _Requirements: 10.5_
- [ ] B-05. School year *(design §5.5; after T-9 and T-10)*
  - Add the `SchoolMonth` helpers and fix `PaymentReminderService`, `BillingReportController` (index and export) and `SubscriptionReportController`.
  - Failing tests at `travelTo('2027-01-20')`, as listed in design §5.5; existing June tests pass.
  - Apply the permitted `BillingReportTest` edit described in design §5.5 (seed calendar year; add frozen-clock variants).
  - Fixtures: none (shapes unchanged).
  - _Requirements: 10.6_
- [x] B-07. Receipt cashier name *(design §5.6; after T-3)*
  - Failing test: checkout response `order.cashier.name` equals the cashier's full name.
  - Fixtures: the 4 listed in the design.
  - _Requirements: 10.7_
- [ ] B-08. Portal login disclosure *(design §5.7; after T-11.1)*
  - Failing test: a disabled parent with a wrong password gets 422 `"Invalid credentials."`; with the right password gets 401 `account_disabled`; an unactivated parent still gets `account_not_activated`.
  - Fixtures: as listed in the design.
  - _Requirements: 10.8_
- [x] B-09. Atomic pre-registration writes *(design §5.8; after T-8)*
  - Failing test: force `createMany` to throw (a contact payload violating a DB constraint, or a DB listener) and assert the parent record is rolled back.
  - _Requirements: 10.9_
- [ ] B-10. LIKE wildcards *(design §5.9; after T-10)*
  - Add the macro and apply it at every listed site.
  - Failing tests: a search for `50%` does not match `500`, and a search for `a_b` does not match `axb`, on both MySQL and SQLite.
  - _Requirements: 10.10_
- [ ] B-11. Analytics credit limit *(design §5.10; after T-10)*
  - Failing test: set `credit_limit` to 100 in System Settings; a student with 60 credit counts as near the limit.
  - _Requirements: 10.11_
- [ ] B-12 + B-13. Report row shapes *(design §5.11; after T-10)*
  - Failing tests:
    - sales `data.0.student.full_name` present
    - sales `data.0.cashier` has no `daily_rate`, `phone` or `birthday`
    - billing `data.0.recorder` has no `daily_rate`
    - `data.0.student` has no `qr_code`
  - Fixtures: sales and billing `ok`.
  - _Requirements: 10.12, 10.13_
- [x] B-14. Status update 500 *(design §5.12; after T-5)*
  - Failing test: `PATCH /students/{student}/status` with `{}` returns 422, not 500.
  - Fixture: new `422-missing-status`.
  - _Requirements: 10.14_
- [ ] B-16. Archived inventory visible in POS references *(design §5.14; after T-7)*
  - Failing test: `GET /api/v1/references/inventory` includes an archived item with `is_archived: true`; `GET /api/v1/pos/inventory` still excludes it.
  - Fixtures: none (row shape unchanged; only the set of rows grows). If the `ok` fixture's scenario has an archived item, its list shape stays identical.
  - Staging smoke test: References → Inventory shows the Archived section with the archived item, and Unarchive moves it back.
  - _Requirements: 10.16_
- [x] B-17. Cross-branch branch monthly amounts *(approved 2026-10-12; design §5.15; after T-6 and B-01)*
  - Failing test: a branch-A supervisor `PUT`/`DELETE` on a branch-B `BranchMonthlyAmount` gets 404.
  - _Requirements: 10.17_
- [ ] B-18. Cross-branch feedback *(approved 2026-10-12; design §5.16; after T-9 and B-01)*
  - Failing test: a branch-A supervisor reply/mark-read on branch-B feedback gets 404, and no `FeedbackReplyMail` is queued.
  - _Requirements: 10.18_
- [x] B-19. *(approved 2026-10-12)* Inventory export crash *(design §5.17; HANDOFF §6 step 3, right after C-1)*
  - Failing test first: a real download (no `Excel::fake()`) returns 200, the filename pattern, and a 2-sheet workbook (design §5.17).
  - Fixture: `get__api_v1_reports_inventory_export@ok.json` (500 → 200).
  - _Requirements: 10.19_
- [x] B-20. *(approved 2026-10-17)* Sold menu item delete *(design §5.18; HANDOFF §6, right after B-03)*
  - Failing test first (`tests/Feature/PosMenuItemDeleteTest.php`): a sold item gives 422 with the exact message, the item stays, and there is no `deleted` activity row; an unsold item still deletes.
  - Fixture: new `delete__api_v1_pos_menu_items_{item}@422-has-sales.json`.
  - _Requirements: 10.20_
- [ ] B-21. *(approved 2026-10-17)* Portal dashboard credit *(design §5.19; HANDOFF §6, right after B-08)*
  - Failing test first (`tests/Feature/Portal/DashboardCreditBalanceTest.php`): `credit_balance` is 37.5 for a student who owes, and 0 for one who does not.
  - Fixture: `get__api_v1_portal_dashboard@ok.json` gains `students[].credit_balance`.
  - _Requirements: 10.21_
- [x] B-22. *(approved 2026-10-17)* Pre-registration birthday *(design §5.20; HANDOFF §6, right after B-09)*
  - Failing test first (`tests/Feature/Kitchen/PreRegistrationBirthdayTest.php`, app timezone Asia/Manila): `show` returns `2016-02-14`, and a PATCH round trip keeps it.
  - Fixtures: the four pre-registration show/patch fixtures listed in design §5.20.
  - _Requirements: 10.22_
- [x] B-23. *(approved 2026-10-17)* 403/404 bodies *(design §5.21; HANDOFF §6 step 5, right after T-2)*
  - Failing test first (`tests/Feature/ApiExceptionRenderingTest.php`): a policy denial gives `Forbidden.` and a binding miss gives `Not found.`; `abort()` texts and the `role:` middleware text are unchanged.
  - Named edit: `AuthorizeAttributeTest` expects `Forbidden.`.
  - Fixtures: none.
  - _Requirements: 10.23_
- [x] B-24. *(approved 2026-10-17)* Checkout with another branch's menu item *(design §5.22; HANDOFF §6 step 10, right after T-4)*
  - Failing test first (in `CheckoutActionTest`): a branch-B menu item gives 422 with the exact message and writes nothing.
  - Fixture: new `post__api_v1_pos_checkout@422-other-branch-item.json`.
  - _Requirements: 10.24_
- [x] B-15. Portal payment-history 403 *(design §5.13; after T-2)*
  - Failing test: an unlinked parent gets 403 `{"message":"Forbidden."}`.
  - Fixture: the `403` variant.
  - _Requirements: 6.3, 10.15_

---

## Coverage check (every acceptance criterion maps to a task)

| Requirement | Tasks |
|---|---|
| 1.1–1.9 | T-1.1–1.8 |
| 2.1, 2.2 | T-3.3, 3.4, 4.2, 5.1, 8.1, 9.1 |
| 2.3 | T-3.1, 7.1 |
| 2.4 | the test bullet of every extraction task |
| 2.5, 2.6 | T-3.3, 3.4, 4.2, 8.1 (+ H3 checks) |
| 2.7 | ground rules + review |
| 3.1–3.7 | T-3.2, 3.4, 3.5, 4.1, 4.4, 5.x, 6.x, 7.x, 8.2, 8.3, 9.2, 9.3, 9.6, 10.x, 11.x |
| 4.1, 4.2, 4.4 | T-12.1, 12.2 |
| 4.3 | T-12.3, 12.4 |
| 4.5 | T-2.3 |
| 4.6 | T-13.1 |
| 4.7 | T-16.1, 16.2 |
| 4.8 | nothing to implement (prohibition); reviewer checks that no `preventSilentlyDiscardingAttributes` appears |
| 5.1–5.5 | T-4.3, 7.2, 7.3, 9.4 (+ H6 checks) |
| 6.1 | nothing to implement (prohibition); matrix test T-1.6 |
| 6.2 | T-2.2 |
| 6.3 | B-15 |
| 6.4 | T-2.1 |
| 6.5 | H3 checks in all tasks |
| 7.1–7.6 | T-9.1, 9.3, 9.5, 7.2, 10.1 |
| 7.7 | fixtures in each task |
| 8.1–8.5 | T-14.1–14.3 |
| 9.1 | T-15.1 |
| 9.2–9.4 | nothing to implement (keep as is / prohibition) |
| 10.2–10.24 | B-01 … B-24 |
| 14.1–14.5 | C-1.1 |
| 11.1–11.5 | T-17.1 |
| 12.1–12.5 | ground rules (every task) + the per-commit Tooling log in the PR |
| 12.6, 12.7 | T-19.1 |
| 13.1 | the §14.1 rows inside T-3, T-4, T-5, T-6, T-7, T-8, T-10, T-12, T-14, T-16 + audit T-18.1 |
| 13.2 | ground rules + T-18.1 audit (`final`/`strict_types` grep) + T-19 prompt |
| 13.3 | T-18.1 |
| 13.4 | ground rules (Pint per task) + T-18.1/T-19.1 full `pint --format agent` with no changes |

No acceptance criterion is without a task. Req 4.8, 6.1 and 9.2–9.4 are prohibitions or "keep as is", verified in review.
