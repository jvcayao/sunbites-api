---
inclusion: always
---

# Tech Steering

## Stack

Versions below are the **installed** versions (`composer.lock`, `package.json`, `compose.yaml`) as of 2026-10-09 (after the `composer update` in commits `ba2b420`/`2de27ac`).

| Layer | Technology | Version |
|---|---|---|
| API backend | Laravel | 13.35 |
| PHP | PHP | 8.5 (`composer.json` constraint `^8.3`) |
| POS & admin frontend | Next.js (App Router) | 16.2.6 |
| Parent portal frontend | Next.js (App Router) | 16.2.6 |
| React | React | 19.2 |
| Database (local) | MySQL via Sail | 8.4 |
| Database (CI tests) | SQLite | GitHub Actions `ci.yml` |
| Container / local dev | Laravel Sail | 1.68 |
| Hosting | Laravel Cloud | staging + production via `cloud deploy` |

## Libraries and Tools

### Backend (Laravel API: `~/sunbites-api`)

| Package | Version | Purpose |
|---|---|---|
| `laravel/sanctum` | 4.3 | Token auth for both guards (`sanctum` for staff, `parents` for parents) |
| `spatie/laravel-permission` | 7.4 | Roles: admin, manager, supervisor, cashier (guard `web`) |
| `bavix/laravel-wallet` | 12.1 | Student wallet; all balance mutations go through `deposit`/`withdraw` |
| `spatie/laravel-activitylog` | 5.1 | Audit trail for key model events and kitchen actions |
| `maatwebsite/excel` (+ `phpoffice/phpspreadsheet` 5.10) | 4.x-dev | Excel report exports |
| `laravel/reverb` | 1.12 | WebSocket server for private-channel notifications |
| `resend/resend-laravel` | 1.6 | `resend` mail transport is configured in `config/mail.php`; the mailer is chosen per environment (`.env.example` uses `smtp`) |
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

Role authorization lives in the route files as `role:` middleware groups. Controller-level `$this->authorize()` is used for policy checks (`OrderPolicy::void`, `UserPolicy`, `ParentStudentPolicy::view` on portal student routes).

### Branch Scoping

Models using `App\Models\Concerns\HasBranch` get `App\Models\Scopes\BranchScope`. `SetActiveBranch` reads `X-Branch-Id`, verifies the user may access that active branch, and binds `app('active_branch')`. It is **prepended** to the `api` middleware group so it runs before `SubstituteBindings`; that ordering is what makes route-model binding branch-scoped.

`BranchScope` applies **no filter** when `active_branch` is unbound (portal requests, or kitchen requests without the header). Bypass explicitly with `Model::withoutBranch()` only with a documented cross-branch reason. Documented exceptions: `PreRegistration` route binding (`AppServiceProvider::bindCrossBranchRouteModels`), `CreditTransaction` and `WalletTopupVoid` (deliberately not `HasBranch`; they carry a `branch_id` snapshot).

### Code Organisation (backend)

- **Actions** (`app/Actions/`, `app/Actions/Parents/`): single-purpose operations with an `execute()` method, e.g. `DowngradeStudentSubscriptionAction`.
- **Services** (`app/Services/`): multi-method domain services. `CreditLedgerService` is the **only** writer of `credit_transactions` and `students.credit_balance`; `StudentLedgerQuery` + `LedgerEntryFormatter` build the unified ledger shared by kitchen and portal.
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

`bootstrap/app.php` renders, for `api/*`: 401 `{"message":"Unauthenticated."}`, 403 `{"message":"Forbidden."}`, 404 `{"message":"Not found."}`, 422 `{"message":"Validation failed.","errors":{...}}`. `abort(code, 'text')` returns `{"message":"text"}`.

### Rate Limiters (`AppServiceProvider`)

`login`, `password-reset`, `portal-login`, `portal-forgot-password`; route-level `throttle:` on public pre-registration (3/60 min public, 5/10 min portal) and kiosk lookup (10/min).

### Frontend Data Fetching

- Server state: TanStack Query. Local UI state: `useState` / `useReducer`. Cross-cutting: Zustand (`lib/store/`).
- All API calls through typed modules in `lib/api/`; `apiClient` in `lib/api/client.ts` handles the bearer token, base URL (`NEXT_PUBLIC_API_URL`, which **includes** `/api/v1`), error parsing into `ApiError`, and logout on 401. The POS client also sends `X-Branch-Id` from `activeBranch`.

## Standards

### PHP
- PHP 8.x features: constructor property promotion, readonly properties, enums, first-class attributes
- Explicit return types and parameter types on all methods
- Form Request classes for all validation. Existing inline `$request->validate()` (48 controller files) is being migrated under Spec 16, domain by domain, behind contract tests. Do not add new inline validation.
- `$request->validated()` only, never `$request->all()`
- Model casts via the `casts()` method; accessors via `Attribute::make()`

### TypeScript / React
- No `any`; named exports only for components; `"use client"` only when needed

### Testing (backend)
- `RefreshDatabase` on every Feature test; `actingAs($user, 'sanctum')` / `actingAs($parent, 'parents')`
- Never mock Eloquent or the database layer
- Tests must pass on **both** MySQL (local Sail) and SQLite (CI). Driver-specific SQL needs a SQLite branch (see `AnalyticsController::isSqlite()`, `SpendingSummaryController`)
- Run (MySQL): `vendor/bin/sail artisan test --compact tests/Feature/Foo.php`
- Run (SQLite, as in CI, inside the Sail container): `docker compose exec -T -u sail -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: laravel.test php artisan test --compact`

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

Deploy: GitHub Actions `staging.yml` / `production.yml` run `cloud deploy "Sunbites API Prod" {staging|production}`. CI (`ci.yml`): PHP 8.5, `pint --test`, `php artisan test` on SQLite.

## Known Discrepancies (verified 2026-09-25; resolve or accept, then remove)

| # | Discrepancy | Evidence |
|---|---|---|
| D1 | POS staff token **is persisted** to `sessionStorage`; the code comment says it is kept in-memory only. Portal parent token is also in `sessionStorage` (intended). | `~/sunbites-pos/lib/store/auth.ts` `partialize` includes `token` |
| D2 | Fortify is installed and registers session-based web routes (`/login`, `/logout`, `/forgot-password`, `/reset-password`, two-factor, email verification) that neither frontend uses; staff password reset is implemented in `Kitchen\AuthController` | `route:list --only-vendor`; `config/fortify.php` |
| D3 | `withRouting(channels: ...)` also registers a top-level `GET/POST /broadcasting/auth` web route alongside the two scoped API ones | `route:list --only-vendor` |
| D4 | `binafy/laravel-cart` is a dependency with no usage | grep of `app/`, `config/`, `database/` |
| D5 | Local tests run on MySQL 8.4, CI tests on SQLite, so driver-specific behaviour is only caught locally | `compose.yaml`, `.github/workflows/ci.yml`, `.env.example` |
| D6 | `composer.json` `name` is still `laravel/react-starter-kit`; `dev`/`ci:check` scripts still call npm/Vite | `composer.json` |
| D7 | POS staging domain is `staging-pos.sunbites.com.ph` in the POS docs, but the API CORS allow-list hard-codes `pos-staging.sunbites.com.ph`. Staging POS works only if `CORS_ALLOWED_ORIGINS` covers it; the deployed env was not checked | `config/cors.php`, `~/sunbites-pos/infrastructure/README.md` |
| D8 | `payment_reminder_days` is seeded as a system configuration but is not read anywhere in `app/` | `database/seeders`, grep of `app/` |
