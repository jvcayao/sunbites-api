---
inclusion: always
---

# Structure Steering

## Directory Layout

### Laravel API (`~/sunbites-api`)

```
app/
  Http/
    Controllers/
      Kitchen/          ← POS/admin controllers (auth:sanctum)
      Portal/           ← Parent portal controllers (auth:parents)
      Auth/             ← Staff auth endpoints
    Requests/           ← Form Request validation classes
    Resources/          ← Eloquent API Resources
  Models/               ← Eloquent models
  Notifications/        ← Laravel notification classes (e.g. PaymentReminderNotification)
  Policies/             ← Authorization policies
routes/
  kitchen-api.php       ← /api/v1/ routes — POS/admin
  portal-api.php        ← /api/v1/portal/ routes — parent portal
  channels.php          ← Reverb private channel authorization
config/
  sunbites.php          ← school months, fallback business rule defaults
resources/views/emails/ ← Transactional email Blade templates only
tests/
  Feature/
    Auth/
    Kitchen/
    Portal/
```

### POS & Admin App (`~/sunbites-pos`)

```
app/
  (auth)/login/         ← AuthLayout
  (kitchen)/            ← KitchenLayout (collapsible sidebar)
    dashboard/
    pos/
    enrollment/
    students/
    reminders/          ← Spec 10
    reports/
    references/
      users/
      parents/
      branches/
      system-settings/  ← Spec 09
components/
  layouts/
    kitchen-layout.tsx
    auth-layout.tsx
  ui/                   ← shadcn/ui base components
hooks/                  ← use-{name}.ts
lib/
  api/                  ← typed service modules per domain
  store/                ← Zustand stores
  validation/           ← Zod schemas per domain
types/                  ← TypeScript interfaces per domain
__tests__/
  test-utils.tsx
  mocks/handlers.ts
  mocks/server.ts
```

### Parent Portal (`~/sunbites-portal`)

```
app/
  (auth)/login/
  (portal)/             ← PortalLayout (top nav)
    dashboard/
    students/
    notifications/      ← Spec 10
    profile/
components/
  layouts/
    portal-layout.tsx
    auth-layout.tsx
  providers/
    echo-provider.tsx   ← Reverb Echo client init (Client Component, wraps portal layout)
  ui/
hooks/
lib/
  api/portal.ts         ← all portal API calls in one file
  store/
  validation/
types/
__tests__/
```

## Naming Conventions

| Artifact | Convention | Example |
|---|---|---|
| PHP controllers | `PascalCase` + `Controller` suffix | `EnrollmentController` |
| PHP models | `PascalCase` singular | `StudentMonthlyPayment` |
| PHP traits | `PascalCase` + descriptive verb/noun | `HasBranch`, `LogsActivity` |
| Migrations | `snake_case` timestamp prefix | `2026_06_01_create_students_table` |
| Route files | `kebab-case-api.php` | `kitchen-api.php` |
| Next.js pages | `page.tsx` per route segment | `app/(kitchen)/students/page.tsx` |
| Next.js hooks | `use-{noun}.ts` kebab-case | `use-student-payments.ts` |
| API service modules | `{domain}.ts` | `lib/api/students.ts` |
| Zod schemas | `{noun}Schema` camelCase | `enrollStudentSchema` |
| TypeScript types | `PascalCase` | `StudentMonthlyPayment` |
| Zustand stores | `use{Noun}Store` | `useAuthStore` |

## Module Boundaries

- **Kitchen controllers** never import from `Portal` namespace and vice versa
- **`app('active_branch')`** is the only sanctioned way to read the active branch in controllers — never read the header directly
- **Wallet mutations** must go through `bavix/laravel-wallet` API — no direct `wallets` table writes
- **Frontend**: components never call `fetch` directly — always through `lib/api/` service layer
- **`lib/store/`** holds only cross-cutting client state; server state lives in TanStack Query cache
- **`EchoProvider`** must be a Client Component; it reads the Sanctum token from Zustand store and initialises the Echo instance — it must be rendered inside the auth-protected portal layout, not the root layout

## Feature Registry

| # | Spec | Backend status | Frontend status |
|---|---|---|---|
| 01 | Project Foundation | Complete | Complete |
| 02 | Roles & Permissions | Complete | Complete |
| 03 | Branch & Tenant | Complete | Complete |
| 04 | Menu & Products | Complete | Complete |
| 05 | Student Management | Complete (tasks 12–13 pending) | Complete (tasks 12–13 pending) |
| 06 | POS & Checkout | Complete | Complete |
| 07 | Parent Portal | Complete | Complete |
| 08 | Reports & Dashboard | Complete | Complete |
| 09 | System Configuration | Complete | Complete |
| 10 | Notifications & Reminders | Not started (includes Reverb broadcasting) | Not started (includes Echo provider) |
| 11 | Announcements | Not started | Not started (includes POS Echo provider + staff inbox) |
| 12 | Pre-Registration | Not started | Not started (public portal page + POS approval queue) |

**Spec 05 pending tasks:**
- Task 12: Soft-deleted student filter & restore (backend migration + controller + frontend toggle)
- Task 13: Nullable/editable student number (migration + controller + frontend)

**Spec 10 scope (notifications-and-reminders):**
- Laravel `notifications` table + `parent_payment_reminders` tracking table
- `PaymentReminderNotification` class (database channel, queued)
- Portal: notification bell, notifications page, student payment history
- POS: reminder bell count, Reminders nav item, Reminders page (eligible parents + send), reminder detail page

## Shared Contracts

### API Response shapes (enforce in both backend Resources and frontend `types/`)

Paginated lists:
```json
{ "data": [...], "meta": { "current_page": 1, "last_page": 5, "total": 47 } }
```

Single resource: the resource object directly (no envelope).

Error responses:
```json
{ "message": "...", "errors": { "field": ["..."] } }
```

### Branch context

All kitchen API requests include `X-Branch-Id` header. The `SetActiveBranch` middleware validates access and binds `app('active_branch')` for the request lifecycle.

### Auth token storage

- Staff token: in-memory Zustand store (`lib/store/auth.ts`) — Bearer token sent as `Authorization: Bearer {token}` header
- Parent token: currently `sessionStorage` via Zustand `persist` middleware (Spec 07 task 8.3 is unresolved — original spec said memory-only but code was not updated). **Decision pending before Spec 10**: memory-only causes Echo to lose its token on page refresh; sessionStorage survives refresh but is less secure. `EchoProvider` reads the token from `useAuthStore` — whichever storage is chosen, the token must be present when Echo initialises.
