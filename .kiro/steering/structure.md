---
inclusion: always
---

# Structure Steering

## Directory Layout

### Laravel API (`~/sunbites-api`)

```
app/
  Actions/                  ← single-purpose operations, execute() (DowngradeStudentSubscriptionAction)
    Parents/                ← Disable/Enable/Restore/SoftDelete parent actions
    Pos/                    ← CheckoutAction, VoidOrderAction
    PreRegistrations/       ← ApprovePreRegistrationAction
    Students/               ← EnrollStudentAction
    Wallet/                 ← VoidWalletTopUpAction
  Console/Commands/         ← CreateAdminUser, ExpirePreRegistrations, FixOrphanedSubscriptionPayments
  Enums/                    ← string-backed enums (PaymentMethod, OrderStatus, SchoolMonth, LedgerEntryType, …)
  Exports/                  ← maatwebsite/excel export classes (Sales, Students, Wallet, Inventory, Billing)
  Http/
    Controllers/
      Concerns/             ← traits shared by Kitchen + Portal (ResolvesStudentLedger)
      Kitchen/              ← POS/admin controllers, incl. staff AuthController (auth:sanctum)
      Portal/               ← parent portal controllers (auth:parents)
      Public/               ← unauthenticated: branches, pre-registration, kiosk lookup
      Controller.php        ← base: paginationMeta(), sanitizeText()
    Middleware/             ← SetActiveBranch, CheckPermission, ForceConnectionClose
    Requests/               ← Form Requests; shared at the root (SettleCredit, WaiveCredit, VoidWalletTopUp, StudentLedger)
      Concerns/             ← shared rule traits (ValidatesOutstandingCredit)
      Kitchen/              ← kitchen endpoint requests (Concerns/StaffUserRules); migration still in progress under Spec 16
      Public/               ← KioskLookupRequest, StorePublicPreRegistrationRequest
    Resources/              ← Order, OrderItem, Student, User, CreditLedgerEntry, InventoryItem,
                              InventoryLog, PosMenuItem, InventoryIngredient resources
  Jobs/                     ← WalletAlertJob
  Listeners/                ← AuthEventSubscriber (auth activity logging)
  Mail/                     ← queued mailables (welcome, reset, pre-reg, feedback reply, wallet alert)
  Models/
    Concerns/               ← HasBranch
    Scopes/                 ← BranchScope
  Notifications/            ← ShouldQueue + ShouldBroadcast notifications
  Policies/                 ← OrderPolicy, UserPolicy, ParentStudentPolicy; registered with #[UsePolicy]
                              on Order, User and Student (the unused ReportPolicy/StudentPolicy were removed in Spec 16 T-2)
  Providers/                ← AppServiceProvider (only provider)
  Services/                 ← CreditLedgerService, EnrollmentService, InventoryStockService,
                              ParentProvisioningService, RecaptchaVerifier, StudentDuplicateService,
                              StudentLedgerQuery, LedgerEntryFormatter
bootstrap/app.php           ← routing, middleware aliases, API exception rendering
routes/
  api.php                   ← /api/health + /api/v1 mount + /api/v1/public group
  kitchen-api.php           ← /api/v1/        POS/admin
  portal-api.php            ← /api/v1/portal/ parent portal
  channels.php              ← Reverb private channel authorization
  console.php               ← schedule
  web.php                   ← GET / → JSON banner only
config/sunbites.php         ← school months, grade levels, fallback business-rule defaults
resources/views/emails/     ← transactional email Blade templates only
tests/
  Feature/
    Auth/  Foundation/  Kitchen/  Portal/  Public/  Reports/
    Contract/               ← Spec 16 API contract tests (Kitchen/, Portal/, Public/, RouteCoverage, RoleAuthorizationMatrix)
    *.php                   ← older domain tests still at the root of Feature/
  Support/Contract/         ← contract harness (ShapeExtractor, AssertsApiContract, ContractScenario)
  Fixtures/contracts/       ← contract fixtures: gitignored, local only, skipped in CI
  Unit/
.github/workflows/          ← ci.yml (PR: SQLite + MySQL 8.4 jobs), staging.yml, production.yml (deploy)
.kiro/
  steering/                 ← product.md, tech.md, structure.md
  specs/NN-name/            ← requirements.md, design.md, tasks.md (+ HANDOFF.md)
docs/superpowers/specs/     ← design specs (all projects' specs live here, never in frontends)
```

### POS & Admin App (`~/sunbites-pos`)

```
app/
  (auth)/
    login/  branch/  reset-password/
  (kitchen)/                ← layout.tsx renders KitchenLayout (collapsible sidebar) + EchoProvider
    dashboard/  enrollment/  students/  students/[id]/  notifications/
    reminders/  reminders/[parentId]/  announcements/  announcements/[id]/  announcements/create/
    pre-registrations/  pre-registrations/[id]/
    reports/    activity/ analytics/ billing/ credits/ daily-summary/ inventory/ sales/ students/ subscription/ wallet/
    references/ branches/ feedback/ inventory/ meal-planner/ parents/ parents/[id]/
                subscription-config/ system-settings/ users/ users/[id]/ users/create/
  (pos)/                    ← page.tsx + pos/page.tsx: full-screen POS checkout
  (kiosk)/kiosk/            ← read-only student QR balance lookup (calls /public/kiosk/lookup)
components/
  layouts/                  ← kitchen-layout.tsx, auth-layout.tsx
  providers/                ← echo-provider.tsx
  ui/  pos/  reports/  analytics/  parents/  users/  navigation/
hooks/                      ← use-{name}.ts (use-student-ledger, use-wallet-history, use-kiosk-lookup, …)
types/                      ← one file per domain
lib/
  api/                      ← one typed module per domain (students.ts, reports.ts, kiosk.ts, …) + client.ts
  store/                    ← Zustand: auth.ts, cart.ts (POS cart)
__tests__/                  ← test-utils.tsx, mocks/handlers.ts, mocks/server.ts
```

### Parent Portal (`~/sunbites-portal`)

```
app/
  (auth)/
    login/  activate/  forgot-password/  reset-password/
  (portal)/                 ← layout.tsx renders PortalLayout (top nav) + EchoProvider
    dashboard/  students/  students/[id]/  notifications/  meal-plan/  feedback/  profile/
  (public)/
    pre-register/           ← calls /public/pre-registrations (never /portal/pre-registrations*)
components/
  layouts/                  ← portal-layout.tsx, auth-layout.tsx
  providers/                ← echo-provider.tsx
  ui/  pre-registration/
types/                      ← auth.ts, notification.ts, portal.ts, pre-registration.ts
lib/
  api/                      ← auth.ts, portal.ts, notifications.ts, pre-registration.ts, client.ts
  store/                    ← Zustand auth.ts (sessionStorage persist)
  validation/               ← pre-registration.ts
__tests__/                  ← test-utils.tsx, mocks/handlers.ts, mocks/server.ts
(no hooks/ directory)
```

## Naming Conventions

| Artifact | Convention | Example |
|---|---|---|
| PHP controllers | `PascalCase` + `Controller`, namespaced by consumer | `Kitchen\EnrollmentController` |
| PHP actions | verb-noun + `Action`, method `execute()` | `DowngradeStudentSubscriptionAction` |
| PHP services | noun + `Service` / `Query` / `Formatter` | `CreditLedgerService`, `StudentLedgerQuery` |
| Form Requests | verb-noun + `Request` | `SettleCreditRequest` |
| PHP models | `PascalCase` singular | `StudentMonthlyPayment` |
| PHP enums | `PascalCase`, TitleCase cases, string-backed | `PaymentMethod::Wallet` |
| PHP traits | descriptive verb/noun | `HasBranch`, `ResolvesStudentLedger` |
| Migrations | timestamp + `snake_case` | `2026_06_01_000000_create_students_table` |
| Route files | `kebab-case-api.php` | `kitchen-api.php` |
| Next.js pages | `page.tsx` per segment | `app/(kitchen)/students/page.tsx` |
| Next.js hooks | `use-{noun}.ts` | `use-student-payments.ts` |
| API service modules | `{domain}.ts` | `lib/api/students.ts` |
| Zod schemas | `{noun}Schema` | `enrollStudentSchema` |
| Zustand stores | `use{Noun}Store` | `useAuthStore` |
| Kiro specs | `NN-kebab-name/` | `15-wallet-topup-void/` |

## Module Boundaries

- **Kitchen** and **Portal** controllers never import each other; shared code goes in `Http/Controllers/Concerns/` or `app/Services/`
- **`app('active_branch')`** is the only sanctioned way to read the active branch in controllers; never read `X-Branch-Id` directly (exception: activity-log properties in `AuthController::login`)
- **Wallet mutations** only through the `bavix/laravel-wallet` API
- **Credit mutations** only through `CreditLedgerService`
- **Stock changes** (after an item is created) only through `InventoryStockService`
- **Frontend**: components never call `fetch` directly; always through `lib/api/`
- **`lib/store/`** holds only cross-cutting client state; server state lives in the TanStack Query cache
- **`EchoProvider`** is a Client Component rendered inside the authenticated layout, not the root layout

## Feature Registry

| # | Spec | Backend status | Frontend status |
|---|---|---|---|
| 01 | Project Foundation | Complete | Complete |
| 02 | Roles & Permissions | Complete | Complete |
| 03 | Branch & Tenant | Complete | Complete |
| 04 | Menu & Products | Complete | Complete |
| 05 | Student Management | Complete | Complete |
| 06 | POS & Checkout | Complete | Complete |
| 07 | Parent Portal | Complete | Complete |
| 08 | Reports & Dashboard | Complete | Complete |
| 09 | System Configuration | Complete | Complete |
| 10 | Notifications | Complete | Complete |
| 11 | Payment Reminders | Complete | Complete |
| 12 | Announcements | Complete | Complete |
| 13 | Pre-Registration | Complete | Complete |
| 14 | Credit Settlement | Complete | Complete |
| 15 | Wallet Top-up Void | Complete | Complete |
| 16 | Backend Code Quality (Laravel 13) | Requirements drafted | n/a (no FE change) |

## Shared Contracts

### API response shapes

Paginated lists (built by `Controller::paginationMeta()`):
```json
{ "data": [...], "meta": { "current_page": 1, "last_page": 5, "per_page": 25, "total": 47, "from": 1, "to": 25 } }
```
Exceptions: `InventoryController::history`, the logs block of `InventoryReportController`, and `ReminderController::eligibleParents` emit `meta` without `from`/`to`. `StudentController::index` and `UserManagementController::index` return Laravel's native resource-collection pagination (`data`, `links`, `meta`).

Single resources: there is no single convention. Some endpoints return the object directly (`StudentResource`, profile), some wrap it (`{ "data": {...} }` for pre-registrations/announcements, `{ "student": {...} }` for lookup, `{ "message": "...", "order": {...} }` for checkout/void). Treat each endpoint's current shape as its contract.

Error responses:
```json
{ "message": "Validation failed.", "errors": { "field": ["..."] } }
```
Some endpoints return `{ "errors": {...} }` with no `message` (subscription end-before-start checks, portal change-password), and portal login adds `"error": "account_not_activated" | "account_disabled"`.

Numeric serialisation: `decimal:2` casts serialise as strings (`"150.00"`); values explicitly cast with `(float)` serialise as numbers. Both occur, and each field's form is part of its contract.

### Branch context

All kitchen API requests include `X-Branch-Id`. `SetActiveBranch` (prepended to the `api` group, before route-model binding) validates access and binds `app('active_branch')`, or returns 403 for a branch the user cannot access. An absent header binds nothing.

### Auth token storage

- Staff: Zustand store `lib/store/auth.ts` in `~/sunbites-pos`, persisted to `sessionStorage` (see tech.md Known Discrepancies D1); sent as `Authorization: Bearer {token}`
- Parent: Zustand `persist` to `sessionStorage` in `~/sunbites-portal`, so that `EchoProvider` can re-subscribe after a refresh
