# Spec 01 — Project Foundation

## Overview

This spec establishes the foundation for all three Sunbites projects:

| Project | Repository | Purpose |
|---|---|---|
| `~/sunbites-api` | Laravel 13 REST API | Data layer, business logic, auth, activity logging |
| `~/sunbites-pos` | Next.js (App Router) | POS & administrative app for staff |
| `~/sunbites-portal` | Next.js (App Router) | Read-only portal for parents |

All staff and parent authentication goes through the Laravel API using **Laravel Sanctum token-based auth**. The Next.js apps are fully independent frontends that communicate with the API over HTTPS.

---

## Domain Strategy

### Local Development

The Laravel API runs via **Laravel Sail** (Docker). The two Next.js apps run separately as local dev servers.

```
api.sunbites.test       → Laravel API (Sail, port 80)
localhost:3000          → sunbites-pos (Next.js dev server)
localhost:3001          → sunbites-portal (Next.js dev server)
```

Add to `/etc/hosts`:
```
127.0.0.1  api.sunbites.test
```

### Staging

```
api-staging.sunbites.com.ph → Laravel API (staging)
```

### Production

```
api.sunbites.com.ph     → Laravel API
pos.sunbites.com.ph     → POS & admin app (Next.js)
portal.sunbites.com.ph  → Parent portal (Next.js)
```

### Environment Variable
```env
APP_DOMAIN=sunbites.test          # local (API served at api.sunbites.test)
APP_DOMAIN=sunbites.com.ph        # production (API served at api.sunbites.com.ph)
```

`config/app.php` exposes a `domain` key derived from `APP_DOMAIN`. The API subdomain is always `api.{APP_DOMAIN}`.

---

## Laravel API — Packages

| Package | Purpose |
|---|---|
| `laravel/sanctum` | Token-based API authentication |
| `spatie/laravel-permission` | Role and permission management |
| `bavix/laravel-wallet` | Student wallet (balance, deposits, charges) |
| `maatwebsite/excel` | Excel/CSV report exports |
| `spatie/laravel-activitylog` | Audit trail for all kitchen app actions |
| `binafy/laravel-cart` | POS cart management |

---

## Laravel API — CORS & Auth Setup

### CORS (`config/cors.php`)
The API must allow requests from both Next.js app origins:

```
Local:      http://localhost:3000, http://localhost:3001
Production: https://pos.sunbites.com.ph, https://portal.sunbites.com.ph
```

- `allowed_origins`: explicit list — never `*` in production
- `allowed_methods`: `GET, POST, PUT, PATCH, DELETE, OPTIONS`
- `allowed_headers`: `Content-Type, Authorization, Accept, X-Requested-With`
- `supports_credentials`: `false` — token-based auth, no cookies

### Sanctum
- Token expiration configured in `config/sanctum.php`
- Tokens issued on login, invalidated on logout
- All protected routes use the `auth:sanctum` middleware
- Staff tokens and parent tokens use separate abilities to enforce role boundaries

---

## Laravel API — Route Structure

All API routes live in `routes/api.php` and are prefixed with `/api/v1/`.

```
/api/v1/auth/login           POST    — staff login
/api/v1/auth/logout          POST    — staff logout (auth:sanctum)
/api/v1/auth/user            GET     — get authenticated user

/api/v1/portal/auth/login    POST    — parent login
/api/v1/portal/auth/logout   POST    — parent logout

/api/v1/branches             ...     — branch management (Spec 03)
/api/v1/students             ...     — student management (Spec 05)
/api/v1/enrollment           ...     — enrollment (Spec 05)
/api/v1/pos                  ...     — POS & checkout (Spec 06)
...
```

No web routes are used for the Next.js apps. `routes/web.php` serves only the main website placeholder.

---

## Application Configuration (`config/sunbites.php`)

All Sunbites-specific constants live here — never hardcoded inline:

```php
return [
    'credit_limit'            => 300,
    'loyalty_point_threshold' => 1000,
    'daily_meal_rate'         => 135,
    'school_months' => [
        'june'      => ['label' => 'June',      'days' => 22, 'amount' => 2970],
        'july'      => ['label' => 'July',      'days' => 22, 'amount' => 2970],
        'august'    => ['label' => 'August',    'days' => 18, 'amount' => 2430],
        'september' => ['label' => 'September', 'days' => 22, 'amount' => 2970],
        'october'   => ['label' => 'October',   'days' => 22, 'amount' => 2970],
        'november'  => ['label' => 'November',  'days' => 16, 'amount' => 2160],
        'december'  => ['label' => 'December',  'days' => 15, 'amount' => 2025],
        'january'   => ['label' => 'January',   'days' => 20, 'amount' => 2700],
        'february'  => ['label' => 'February',  'days' => 18, 'amount' => 2430],
        'march'     => ['label' => 'March',     'days' => 7,  'amount' => 945],
    ],
    'grade_levels' => [
        'Nursery', 'Kinder 1', 'Kinder 2',
        'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6',
        'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12',
    ],
];
```

Reference via `config('sunbites.school_months')`, `config('sunbites.credit_limit')`, etc.

---

## Seeders & Initial Data

Seeder execution order in `DatabaseSeeder`:

| Seeder | Contents |
|---|---|
| `BranchSeeder` | Antipolo Branch + Iloilo Branch (slugs, GCash numbers) |
| `PermissionSeeder` | Spatie roles: admin, manager, supervisor, cashier + all permissions |
| `PosMenuItemSeeder` | 7 default menu items per branch (defined in Spec 04) |
| `WeeklyMealPlanSeeder` | Default week pattern × all 10 months × 4 weeks × all branches |
| `InventoryItemSeeder` | Default ingredient items per branch |

**Admin user creation:** `php artisan sunbites:create-admin` — interactive Artisan command that prompts for first name, last name, email, and password. Validates email uniqueness and password policy (min 8 chars, 1 uppercase, 1 number). Creates the user with the `admin` role and optionally assigns a branch. Works in any environment including production. No hardcoded credentials anywhere.

---

## Activity Logging

Powered by `spatie/laravel-activitylog`. Every meaningful write action in the system is recorded: who did it, when, and on what subject. This forms an end-of-day operational audit trail.

### Log Names & Events

| Log Name | Area | Events |
|---|---|---|
| `auth` | Authentication | Login, failed login (with IP), logout, password reset |
| `users` | User Management | User created, edited, deleted, role changed |
| `branches` | Branches | Active branch switched |
| `students` | Student Management | Enrolled, profile updated, status changed (with reason), deleted |
| `wallet` | Wallet | Top-up (amount, method, by whom), credit settled |
| `payments` | Subscription Payments | Month marked paid/unpaid, amount recorded |
| `pos` | POS & Checkout | Order created, order voided (reason), discount applied |
| `menu` | Menu Items | Item added, toggled, deleted |
| `meal_planner` | Meal Planner | Week saved (month + week number) |
| `inventory` | Inventory | Stock adjusted (item, delta, reason, new qty) |

### What Is Not Logged
- Read-only requests (GET endpoints)
- Parent portal activity
- Automated system/queue events

### Sensitive Fields
Each model using the `LogsActivity` trait must define an explicit `$logAttributes` **allowlist** — never a denylist. Fields that must never appear in `activity_log.properties`:
- `password`, `remember_token` — `User` model
- `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number` — `User` model
- `qr_code`, `photo_path` — `Student` model

### Implementation
- Auto-tracked models (`User`, `Student`, `PosMenuItem`, `InventoryItem`) use the `LogsActivity` trait with `$recordEvents = ['created', 'updated', 'deleted']`
- Action-based events (wallet top-up, order creation, status changes) are logged manually via `activity()->causedBy($user)->performedOn($subject)->log('...')`
- Auth events logged via Laravel event listeners (`Login`, `Failed`, `Logout`)
- Every log entry includes `branch_id` in `properties`

### Who Can View
- Activity Log Viewer (`/reports/activity` in the POS app) — Admin and Manager only
- Student-specific logs tab — Admin, Manager, Supervisor

---

## Next.js Apps — Shared Setup

Both `~/sunbites-pos` and `~/sunbites-portal` share the same foundational setup:

### Stack
- Next.js (App Router)
- React 19
- TypeScript (strict mode)
- Tailwind CSS v4
- shadcn/ui component library
- TanStack Query v5 — all API data fetching
- Zod v4 — form validation
- Sonner — toast notifications
- Zustand — cross-cutting client state (auth session, active branch)

### Environment Variables
```env
NEXT_PUBLIC_API_URL=http://api.sunbites.test/api/v1              # local
NEXT_PUBLIC_API_URL=https://api-staging.sunbites.com.ph/api/v1  # staging
NEXT_PUBLIC_API_URL=https://api.sunbites.com.ph/api/v1          # production
```

No secrets in `NEXT_PUBLIC_` variables. Server-only env vars (signing keys, etc.) use no prefix.

### API Service Layer
All API calls go through typed service modules in `lib/api/`:

```
lib/
  api/
    client.ts       ← base fetch wrapper: auth header, base URL, error parsing
    auth.ts
    students.ts
    payments.ts
    wallet.ts
    ...
```

The `client.ts` base wrapper:
- Reads `NEXT_PUBLIC_API_URL` for the base URL
- Attaches `Authorization: Bearer {token}` to every authenticated request
- Parses error responses into a typed `ApiError`
- Triggers auth logout flow on 401 responses

### Auth Token Storage
Sanctum tokens are stored in memory (Zustand store) only — never in `localStorage` or `sessionStorage`. On browser refresh, the user is required to log in again.

### TanStack Query Setup
A single `QueryClient` instance is created per app, provided via a Client Component wrapper at the root layout:

```tsx
// app/providers.tsx — "use client"
export function Providers({ children }: { children: React.ReactNode }) {
  const [queryClient] = useState(() => new QueryClient({
    defaultOptions: { queries: { staleTime: 60_000, retry: 1 } },
  }));
  return (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  );
}
```

---

## Design System (Both Next.js Apps)

The same design system applies to both Next.js apps.

### Color Tokens (Tailwind v4 `@theme` in `globals.css`)

| Token | Value | Usage |
|---|---|---|
| `--primary` | `oklch(0.577 0.245 27.325)` | Buttons, active states, links — tomato red |
| `--primary-foreground` | `oklch(0.971 0.013 17.38)` | Text on primary backgrounds |
| `--background` | `oklch(1 0 0)` | Page background |
| `--foreground` | `oklch(0.141 0.005 285.823)` | Body text |
| `--muted` | `oklch(0.967 0.001 286.375)` | Subtle backgrounds |
| `--muted-foreground` | `oklch(0.556 0.014 285.938)` | Labels, helper text |
| `--destructive` | `oklch(0.577 0.245 27.325)` | Errors, delete actions |
| `--border` | `oklch(0.92 0.004 286.32)` | Card and input borders |
| `--sidebar` | `oklch(0.985 0 0)` | Sidebar background |
| `--sidebar-primary` | `oklch(0.577 0.245 27.325)` | Sidebar active item |

No dark mode.

### Typography
- Font: **Poppins** loaded from Google Fonts — weights 400, 600, 700, 800
- Applied globally via `globals.css` as the default font family
- Scale: `text-xs` (badges/labels) → `text-sm` (body/table rows) → `text-base` (standard) → `text-lg`/`text-xl` (headings) → `text-2xl font-extrabold` (stat numbers)

### Component Library
**shadcn/ui** for all UI primitives: Button, Card, Input, Select, Badge, Dialog, Sheet, Tabs, Table, DropdownMenu. Toast via **Sonner**.

### Logo Component
A shared `AppLogo` component built as a React component in each app:
- **Full variant** — circle icon + "Sunbites" wordmark + "Your Healthy Kitchen" tagline (used in sidebar, auth pages)
- **Icon variant** — "S" circle only, 40×40px (used in collapsed sidebar state)

---

## Toast Notification System (Both Next.js Apps)

Provider: **Sonner** `<Toaster />` mounted at the root layout, `position="top-center"`.

| Method | Trigger |
|---|---|
| `toast.success()` | Successful save, create, delete, status change |
| `toast.error()` | API errors, validation failures |
| `toast.warning()` | Low wallet balance, low stock alerts |
| `toast.info()` | Informational messages |

Toast messages are triggered from:
- `useMutation` `onSuccess` / `onError` callbacks in TanStack Query
- API error handler in `lib/api/client.ts` for global error cases (401, 500)

---

## Layout Shells (sunbites-pos)

### KitchenLayout
- Collapsible sidebar: 220px expanded, 60px icon-only collapsed
- Sidebar: logo, branch indicator badge, role-aware nav items, collapse toggle, logout button
- Nav sections: main nav → Reports group → References group
- Topbar: page title + branch switcher pill (admin only) + user name + role badge
- Main content: scrollable

### AuthLayout (POS)
- Centered card, max-width 420px
- Logo above the form
- No sidebar, no topbar

---

## Layout Shells (sunbites-portal)

### PortalLayout
- Top navigation bar (no sidebar)
- Logo left, nav links center, user menu right
- Mobile-responsive with hamburger menu

### AuthLayout (Portal)
- Same centered card structure as POS auth layout

---

## Requirements

**Laravel API**
- [ ] `config/sunbites.php` with all constants: `credit_limit`, `loyalty_point_threshold`, `daily_meal_rate`, `school_months`, `grade_levels`
- [ ] `config/cors.php` allows `localhost:3000`, `localhost:3001` (local) and production Next.js domains; never wildcard
- [ ] Sanctum installed and configured: token expiry set, all protected routes use `auth:sanctum`
- [ ] API routes in `routes/api.php` prefixed `/api/v1/`
- [ ] `APP_DEBUG=false` in production — API never exposes stack traces or raw SQL errors
- [ ] `BranchSeeder`, `PermissionSeeder`, `PosMenuItemSeeder`, `WeeklyMealPlanSeeder`, `InventoryItemSeeder` run in correct order
- [ ] `php artisan sunbites:create-admin` command — interactive, validates email uniqueness and password policy
- [ ] `spatie/laravel-activitylog` installed, `activity_log` table migrated
- [ ] Auth event listeners registered: login success, failed login (with IP), logout → `auth` log
- [ ] `LogsActivity` trait on `User` with explicit `$logAttributes` allowlist; excludes sensitive fields
- [ ] All activity entries include `branch_id` in `properties`
- [ ] Soft-deleted records retained indefinitely — no auto-purge

**Next.js — Both Apps**
- [ ] Next.js project initialized with TypeScript strict mode, Tailwind v4, App Router
- [ ] `NEXT_PUBLIC_API_URL` environment variable configured for local and production
- [ ] `lib/api/client.ts` base fetch wrapper with auth header, error parsing, 401 auto-logout
- [ ] TanStack Query v5 `QueryClient` provider at root layout
- [ ] Sonner `<Toaster position="top-center" />` at root layout
- [ ] Zustand auth store: token in memory, cleared on logout
- [ ] Tailwind v4 color tokens configured in `globals.css` via `@theme`
- [ ] Poppins font loaded (weights 400, 600, 700, 800) from Google Fonts
- [ ] No dark mode
- [ ] `AppLogo` component with `full` and `icon` variants
- [ ] shadcn/ui initialized with sunbites color tokens

**Next.js — sunbites-pos only**
- [ ] `KitchenLayout` with collapsible sidebar and topbar
- [ ] `AuthLayout` centered card shell for login page

**Next.js — sunbites-portal only**
- [ ] `PortalLayout` with top navigation bar, mobile hamburger
- [ ] `AuthLayout` centered card shell for login page
