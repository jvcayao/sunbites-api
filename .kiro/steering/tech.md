---
inclusion: always
---

# Tech Steering

## Stack

Versions below are the **installed** versions (`composer.lock`, `package.json`, `compose.yaml`) as of 2026-10-23 (after the `composer update` in commit `880d4c0`, a PHP security patch; earlier updates: `ba2b420`/`2de27ac`, `5b5d5e3`).

| Layer | Technology | Version |
|---|---|---|
| API backend | Laravel | 13.38 |
| PHP | PHP | 8.5 (`composer.json` constraint `^8.3`) |
| POS & admin frontend | Next.js (App Router) | 16.2.6 |
| Parent portal frontend | Next.js (App Router) | 16.2.6 |
| React | React | 19.2 |
| Database (local) | MySQL via Sail | 8.4 |
| Database (CI tests) | SQLite + MySQL 8.4 | GitHub Actions `ci.yml`: `test` (SQLite) and `test-mysql` (MySQL 8.4 service container, Spec 16 C-1). `staging.yml` / `production.yml` gate deploys on SQLite only |
| Container / local dev | Laravel Sail | 1.69 |
| Hosting | Laravel Cloud | staging + production via `cloud deploy` |

## Libraries and Tools

### Backend (Laravel API: `~/sunbites-api`)

| Package | Version | Purpose |
|---|---|---|
| `laravel/sanctum` | 4.3 | Token auth for both guards (`sanctum` for staff, `parents` for parents) |
| `spatie/laravel-permission` | 7.4 | Roles: admin, manager, supervisor, cashier (guard `web`) |
| `bavix/laravel-wallet` | 12.1 | Student wallet; all balance mutations go through `deposit`/`withdraw` |
| `spatie/laravel-activitylog` | 5.1 | Audit trail for key model events and kitchen actions |
| `maatwebsite/excel` (+ `phpoffice/phpspreadsheet` 5.10) | 4.x-dev (`4dbe31c`; a dev branch, so it can move on any `composer update`) | Excel report exports |
| `laravel/reverb` | 1.12 | WebSocket server for private-channel notifications |
| `resend/resend-laravel` | 1.7 | `resend` mail transport is configured in `config/mail.php`; the mailer is chosen per environment (`.env.example` uses `smtp`) |
| `league/flysystem-aws-s3-v3` | 3.x | `private` and `public` disks are S3 (photos) |
| `sentry/sentry-laravel` / `spatie/laravel-flare` | 4.29 / 3.6 | Error monitoring (`Integration::handles` in `bootstrap/app.php`) |
| `laravel/fortify` | 1.41 | Installed; see Known Discrepancies (not used by either frontend) |
| `binafy/laravel-cart` | 2.1 | Installed; not referenced anywhere in `app/`, `config/` or `database/` |
| `laravel/boost` (dev) | 2.10.3 | MCP tools + agent guidelines |
| `laravel/pint` (dev) | 1.32 | Formatter (`preset: laravel`); run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes |
| `phpunit/phpunit` (dev) | 12.5 | Test runner |

### Frontend (both Next.js apps)

| Package | Version | Purpose |
|---|---|---|
| `@tanstack/react-query` | 5.x | All server state |
| `zod` | 4.x | Form validation schemas |
| `tailwindcss` | 4.x | Utility-first styling with `@theme` CSS variables |
| `shadcn/ui` | n/a | Base component library |
| `zustand` | 5.x | Cross-cutting client state (auth session, active branch) |
| `laravel-echo` / `pusher-js` | 2.3 / 8.5 | Reverb client |
| `jest` / `@testing-library/react` / `msw` | 30 / 16 / 2 | Tests |

## Architecture Patterns

### Two Auth Guards

Never mix guards:

| Guard | Model | Table | Token ability | Route file |
|---|---|---|---|---|
| `auth:sanctum` | `User` | `users` | `staff` | `routes/kitchen-api.php` |
| `auth:parents` | `ParentUser` | `parents` | `parent` | `routes/portal-api.php` |

`User::getDefaultGuardName()` returns `web` so Spatie resolves roles seeded under the `web` guard even on Sanctum requests. Use `App\Http\Middleware\CheckPermission` (alias `permission`) rather than Spatie's permission middleware for Sanctum routes.

### Routing

`bootstrap/app.php` registers `routes/api.php` (prefix `/api`), which mounts:

| Prefix | File / group | Auth |
|---|---|---|
| `/api/v1/` | `routes/kitchen-api.php` | `auth:sanctum` + `ability:staff`; login/password routes public with `throttle:login` / `throttle:password-reset` |
| `/api/v1/portal/` | `routes/portal-api.php` | `auth:parents` + `ability:parent`; login/password routes public |
| `/api/v1/public/` | inline group in `routes/api.php` | none (throttled): branches, pre-registrations, kiosk lookup |
| `/api/health` | `routes/api.php` | none (DB ping) |

Role authorization lives in the route files as `role:` middleware groups. Policy checks use the `#[Authorize('ability', 'param')]` attribute on controller methods (15 methods: `OrderPolicy::void`, `UserPolicy` on user management, `ParentStudentPolicy::view` on portal student routes). Policies are registered on the models with `#[UsePolicy]` (`Order`, `User`, and `Student` → `ParentStudentPolicy`); `AppServiceProvider` has no `Gate::policy()` calls. The base `Controller` uses `AuthorizesRequests`.

### Branch Scoping

Models using `App\Models\Concerns\HasBranch` get `App\Models\Scopes\BranchScope`. `SetActiveBranch` reads `X-Branch-Id`, verifies the user may access that active branch, and binds `app('active_branch')`. It is **prepended** to the `api` middleware group so it runs before `SubstituteBindings`; that ordering is what makes route-model binding branch-scoped.

`BranchScope` applies **no filter** when `active_branch` is unbound (portal requests, or kitchen requests without the header). Bypass explicitly with `Model::withoutBranch()` only with a documented cross-branch reason. Documented exceptions: `PreRegistration` route binding (`AppServiceProvider::bindCrossBranchRouteModels`), `CreditTransaction` and `WalletTopupVoid` (deliberately not `HasBranch`; they carry a `branch_id` snapshot).

### Code Organisation (backend)

- **Actions** (`app/Actions/`): single-purpose operations with an `execute()` method, grouped by domain: `Pos/` (`CheckoutAction`, `VoidOrderAction`), `Wallet/` (`VoidWalletTopUpAction`), `Students/` (`EnrollStudentAction`), `PreRegistrations/` (`ApprovePreRegistrationAction`), `Parents/` (disable/enable/restore/soft-delete), plus `DowngradeStudentSubscriptionAction`. Controllers stay thin: validate, call the action, return the response.
- **Services** (`app/Services/`): multi-method domain services.
  - `CreditLedgerService` is the **only** writer of `credit_transactions` and `students.credit_balance`.
  - `InventoryStockService` makes every stock change after an item is created: sale deduction, void restock, manual adjustment and the initial-stock log. It locks the inventory rows with `lockForUpdate()` in id order (Spec 16 B-03).
  - `StudentLedgerQuery` + `LedgerEntryFormatter` build the unified ledger shared by kitchen and portal. `RecaptchaVerifier` wraps the Google reCAPTCHA call for the public pre-registration form.
- **Form Requests** (`app/Http/Requests/`): `Kitchen/` and `Public/` per consumer; shared ones at the root (`SettleCreditRequest`, `WaiveCreditRequest`, `VoidWalletTopUpRequest`, `StudentLedgerRequest`), and shared rule traits in `Concerns/` and `Kitchen/Concerns/`. `authorize()` returns `true` (authorization is in route middleware and `#[Authorize]`). Guards that must win over validation run in `prepareForValidation()` and throw `HttpResponseException` with the same body as before.
- **API Resources** (`app/Http/Resources/`): `OrderResource`, `OrderItemResource`, `StudentResource`, `UserResource`, `CreditLedgerEntryResource`, `InventoryItemResource`, `InventoryLogResource`, `PosMenuItemResource`, `InventoryIngredientResource`. They are returned inside `response()->json([...])` or nested, so there is no automatic `data` wrapper.
- **Enums** (`app/Enums/`): string-backed, TitleCase cases, `label()` helpers.
- **Controller concerns** (`app/Http/Controllers/Concerns/`): shared code that both Kitchen and Portal need (they may not import each other).
- Base `Controller` provides `paginationMeta()` and `sanitizeText()`.

### Student Wallet

Use **only** the `bavix/laravel-wallet` API for balance changes. Reading `transactions` via `DB::table` for reports is fine; the only approved direct write is the `meta` update in the Spec 15 top-up void.

### Activity Logging

Key models (`Student`, `Order`, `User`, `InventoryItem`, `PosMenuItem`) use `LogsActivity`. Controllers also log manual events (`'pos.order_created'`, `'wallet.topped_up'`, …). Log names and descriptions are consumed by the POS Activity Log screen; do not rename them.

### Queues, Mail, Notifications

- Queue connection: `database` (`after_commit => false` on all connections).
- All notifications implement `ShouldQueue` + `ShouldBroadcast` and use `database` + `broadcast` channels.
- Every mailable is queued (`Mail::to()->queue()`; no synchronous `send()` in `app/`); Blade templates in `resources/views/emails/`. Links in emails use `config('app.pos_url')` / `config('app.portal_url')` (`POS_APP_URL` / `PORTAL_APP_URL`).
- Scheduled (`routes/console.php`): `activitylog:clean` daily, `queue:prune-failed` weekly, `pre-registrations:expire` daily 00:00.
- Artisan commands use `#[Signature]` / `#[Description]` attributes.

### Real-Time Broadcasting (Reverb)

Private channels, authorized in `routes/channels.php`:
- `parents.{parentId}`: `ParentUser` (`receivesBroadcastNotificationsOn()`)
- `staff.{userId}`: `User`

Channel auth endpoints inside the authenticated groups:
- Portal: `POST /api/v1/portal/broadcasting/auth` (`auth:parents`)
- Kitchen: `POST /api/v1/broadcasting/auth` (`auth:sanctum`)

Frontend: `components/providers/echo-provider.tsx` (Client Component) in both apps initialises `laravel-echo` with the bearer token from `useAuthStore` and disconnects when the token is cleared. POS renders it in `app/(kitchen)/layout.tsx` (auth endpoint `…/broadcasting/auth`); Portal renders it in `app/(portal)/layout.tsx` (`…/portal/broadcasting/auth`). Env: `NEXT_PUBLIC_REVERB_APP_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME`.

### Exception Rendering

`bootstrap/app.php` renders, for `api/*`: 401 `{"message":"Unauthenticated."}`, 403 `{"message":"Forbidden."}` for a policy or `#[Authorize]` denial, 404 `{"message":"Not found."}` for a missing route model or `firstOrFail()`, and 422 `{"message":"Validation failed.","errors":{...}}`.
- Laravel converts `AuthorizationException` and `ModelNotFoundException` into HTTP exceptions before the renderable runs, so the 403/404 branches match `AccessDeniedHttpException` / `NotFoundHttpException` by `getPrevious()` (Spec 16 B-23). Before B-23 these returned `This action is unauthorized.` and `No query results for model […]`.
- Everything else keeps its own text: `abort(code, 'text')` returns `{"message":"text"}`, and the `role:` middleware returns 403 `{"message":"User does not have the right roles."}`.

### Rate Limiters (`AppServiceProvider`)

`login`, `password-reset`, `portal-login`, `portal-forgot-password`; route-level `throttle:` on public pre-registration (3/60 min public, 5/10 min portal) and kiosk lookup (10/min).

### Frontend Data Fetching

- Server state: TanStack Query. Local UI state: `useState` / `useReducer`. Cross-cutting: Zustand (`lib/store/`).
- All API calls through typed modules in `lib/api/`; `apiClient` in `lib/api/client.ts` handles the bearer token, base URL (`NEXT_PUBLIC_API_URL`, which **includes** `/api/v1`), error parsing into `ApiError`, and logout on 401. The POS client also sends `X-Branch-Id` from `activeBranch`.

## Standards

### PHP
- PHP 8.x features: constructor property promotion, readonly properties, enums, first-class attributes
- Explicit return types and parameter types on all methods
- Form Request classes for all validation. Inline `$request->validate()` is being migrated under Spec 16, domain by domain, behind contract tests: 48 controller files at the start, 25 left after Spec 16 step 19 (B-22), with T-9 to T-11 covering the rest. Do not add new inline validation.
- `$request->validated()` only, never `$request->all()`
- Model casts via the `casts()` method; accessors via `Attribute::make()`

### TypeScript / React
- No `any`; named exports only for components; `"use client"` only when needed

### Testing (backend)
- `RefreshDatabase` on every Feature test; `actingAs($user, 'sanctum')` / `actingAs($parent, 'parents')`
- Never mock Eloquent or the database layer
- Tests must pass on **both** MySQL (production engine) and SQLite. CI runs both on every PR (`ci.yml` jobs `test` and `test-mysql`). Driver-specific SQL needs a SQLite branch (see `AnalyticsController::isSqlite()`, `SpendingSummaryController`)
- **API contract tests** (Spec 16): `tests/Feature/Contract/` (one class per controller, plus `RouteCoverageTest` and `RoleAuthorizationMatrixTest`), and the harness in `tests/Support/Contract/`. They compare every API route's status and JSON shape with fixtures in `tests/Fixtures/contracts/`. Those fixtures are **gitignored and local only**, so fixture comparisons are skipped in CI and run only locally (design §4.1.7 of Spec 16)
- The MySQL gate uses Sail's shared `testing` schema. Never run two MySQL test runs at once (for example from two Claude sessions): `RefreshDatabase` in one wipes the other's tables
- `vendor/bin/sail artisan test` runs **SQLite** locally: Collision clears the `.env` variables, so the gitignored local `.env.testing` (`DB_CONNECTION=sqlite`) wins and the gitignored `testing` file in the repo root is used
- Explicit MySQL and SQLite gates (call `vendor/bin/phpunit` directly; MySQL uses Sail's `testing` schema): see `.kiro/specs/16-backend-code-quality/design.md` §11.1

### Testing (frontend)
- Import from `__tests__/test-utils.tsx`; role/label queries first; MSW at the network boundary

## Constraints

- No Inertia.js; only Blade files are transactional emails
- No Wayfinder imports in frontend apps
- No `console.log` in committed code; no hardcoded API URLs (`NEXT_PUBLIC_API_URL`)
- No secrets in `NEXT_PUBLIC_` env vars
- No `localStorage` for auth tokens
- Commands run through Sail: `vendor/bin/sail artisan ...`, `vendor/bin/sail bin pint ...`
- No new Composer/npm dependency without approval

## Integration Points

| Service | Local | Staging | Production |
|---|---|---|---|
| Laravel API | `http://api.sunbites.test` (Sail) | `https://api-staging.sunbites.com.ph` | `https://api.sunbites.com.ph` |
| POS & admin | `http://localhost:3000` (`POS_APP_URL`) | `staging-pos.sunbites.com.ph` (per POS `infrastructure/`; see D7) | `pos.sunbites.com.ph` |
| Parent portal | `http://localhost:3001` (`PORTAL_APP_URL`) | `portal-staging.sunbites.com.ph` | `portal.sunbites.com.ph` |

Frontend URLs come from each app's `infrastructure/` docs; deployed values were not checked.

CI and deploy (GitHub Actions):
- `ci.yml`, on pull requests to `staging` or `main`. Job `test`: PHP 8.5, `pint --test`, then `php artisan test` on SQLite. Job `test-mysql` (Spec 16 C-1): `vendor/bin/phpunit` against a throwaway MySQL 8.4 service container, using no secrets.
- `staging.yml`, on a push to `staging`: SQLite tests and Pint, then `cloud deploy "Sunbites API Prod" staging`.
- `production.yml`, manual dispatch with the input `DEPLOY`: SQLite tests and Pint, then `cloud deploy "Sunbites API Prod" production`.
- The deploy workflows do not run the MySQL job; MySQL coverage comes from `ci.yml` on the PR.

## Known Discrepancies (verified 2026-09-25; resolve or accept, then remove)

| # | Discrepancy | Evidence |
|---|---|---|
| D1 | POS staff token **is persisted** to `sessionStorage`; the code comment says it is kept in-memory only. Portal parent token is also in `sessionStorage` (intended). | `~/sunbites-pos/lib/store/auth.ts` `partialize` includes `token` |
| D2 | Fortify is installed and registers session-based web routes (`/login`, `/logout`, `/forgot-password`, `/reset-password`, two-factor, email verification) that neither frontend uses; staff password reset is implemented in `Kitchen\AuthController` | `route:list --only-vendor`; `config/fortify.php` |
| D3 | `withRouting(channels: ...)` also registers a top-level `GET/POST /broadcasting/auth` web route alongside the two scoped API ones | `route:list --only-vendor` |
| D4 | `binafy/laravel-cart` is a dependency with no usage | grep of `app/`, `config/`, `database/` |
| D5 | *(Partly resolved 2026-10-12 by Spec 16 C-1, `fd567a4`.)* `ci.yml` now runs the suite on MySQL 8.4 too (`test-mysql`). Still SQLite only: local `vendor/bin/sail artisan test` (Collision loads the gitignored `.env.testing`) and the test jobs in `staging.yml`/`production.yml` that gate deploys | `.env.testing` (local, gitignored), `.github/workflows/*.yml` |
| D6 | `composer.json` `name` is still `laravel/react-starter-kit`; `dev`/`ci:check` scripts still call npm/Vite | `composer.json` |
| D7 | POS staging domain is `staging-pos.sunbites.com.ph` in the POS docs, but the API CORS allow-list hard-codes `pos-staging.sunbites.com.ph`. Staging POS works only if `CORS_ALLOWED_ORIGINS` covers it; the deployed env was not checked | `config/cors.php`, `~/sunbites-pos/infrastructure/README.md` |
| D8 | `payment_reminder_days` is seeded as a system configuration but is not read anywhere in `app/` | `database/seeders`, grep of `app/` |
