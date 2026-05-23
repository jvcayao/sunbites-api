# Tasks 01 — Project Foundation

## 1. Laravel API — Configuration

- [ ] `config/sunbites.php` with all constants: `credit_limit`, `loyalty_point_threshold`, `daily_meal_rate`, `school_months`, `grade_levels`
- [ ] `APP_DOMAIN` env variable used to derive `config('app.domain')`
- [ ] `APP_DEBUG=false` noted in `.env.example` — API must never expose stack traces in production
- [ ] `config/cors.php` allows `http://localhost:3000`, `http://localhost:3001` locally and production Next.js domains; no wildcard `*`

## 2. Laravel API — Sanctum Auth

- [ ] `laravel/sanctum` installed and configured
- [ ] Token expiry set in `config/sanctum.php`
- [ ] All protected routes use `auth:sanctum` middleware
- [ ] Staff auth routes: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/user`
- [ ] Parent auth routes: `POST /api/v1/portal/auth/login`, `POST /api/v1/portal/auth/logout`
- [ ] Token issued on login, revoked on logout (`$request->user()->currentAccessToken()->delete()`)

## 3. Laravel API — Routes

- [ ] All app routes in `routes/api.php` under `/api/v1/` prefix — requires `routes/kitchen-api.php` and `routes/portal-api.php`
- [ ] `routes/kitchen-api.php` — kitchen/staff routes (no `portal` prefix)
- [ ] `routes/portal-api.php` — parent portal routes (mounted under `/portal` prefix in `routes/api.php`)
- [ ] `routes/web.php` serves only the main website placeholder — no frontend app routes

## 4. Laravel API — Seeders

- [ ] `BranchSeeder` — Antipolo + Iloilo branches (slugs, GCash numbers)
- [ ] `PermissionSeeder` — roles (admin, manager, supervisor, cashier) + all permissions via Spatie
- [ ] `PosMenuItemSeeder` — 7 default items per branch (defined in Spec 04)
- [ ] `WeeklyMealPlanSeeder` — default week pattern × 10 months × 4 weeks × all branches (Spec 04)
- [ ] `InventoryItemSeeder` — default ingredient items per branch (Spec 04)
- [ ] `DatabaseSeeder` calls all seeders in correct order; no hardcoded admin credentials

## 5. Laravel API — Admin Command

- [ ] `php artisan sunbites:create-admin` interactive command
  - [ ] Prompts: first name, last name, email, password
  - [ ] Validates email uniqueness
  - [ ] Validates password policy: min 8 chars, 1 uppercase, 1 number
  - [ ] Creates user with `admin` role
  - [ ] Optionally assigns a branch
  - [ ] Works in all environments including production

## 6. Laravel API — Activity Logging

- [ ] `spatie/laravel-activitylog` installed, `activity_log` table migrated
- [ ] Auth event listeners registered via `AuthEventSubscriber`:
  - [ ] Login success → `auth` log (causer = user, properties: IP, branch)
  - [ ] Login failed → `auth` log (no causer, properties: email attempted, IP)
  - [ ] Logout → `auth` log
- [ ] `LogsActivity` trait on `User` model with explicit `$logAttributes` allowlist
  - [ ] Excluded: `password`, `remember_token`, `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`
- [ ] `LogsActivity` trait on `Student` model (Spec 05)
- [ ] `LogsActivity` trait on `PosMenuItem` model (Spec 04)
- [ ] `LogsActivity` trait on `InventoryItem` model (Spec 04)
- [ ] Every log entry includes `branch_id` in `properties`
- [ ] Activity Log Viewer API endpoint (Admin/Manager only) — implemented in Spec 08

## 7. Next.js — Project Initialisation (Both Apps)

- [ ] `~/sunbites-pos` — Next.js initialised: TypeScript strict, App Router, Tailwind v4, ESLint
- [ ] `~/sunbites-portal` — Next.js initialised: TypeScript strict, App Router, Tailwind v4, ESLint
- [ ] `NEXT_PUBLIC_API_URL` in `.env.local` for each app pointing to `http://api.sunbites.test/api/v1`
- [ ] `.env.example` with all required env vars documented

## 8. Next.js — Design System (Both Apps)

- [ ] Tailwind v4 `@theme` color tokens in `globals.css` (all `--primary`, `--background`, `--foreground`, `--muted`, `--border`, `--sidebar` tokens)
- [ ] Poppins font loaded via Google Fonts in `layout.tsx` — weights 400, 600, 700, 800
- [ ] No dark mode — light mode only
- [ ] shadcn/ui initialised with sunbites color tokens
- [ ] `AppLogo` component with `full` and `icon` variants in each app (`components/app-logo.tsx`)

## 9. Next.js — API Client & Providers (Both Apps)

- [ ] `lib/api/client.ts` — base fetch wrapper:
  - [ ] Reads `NEXT_PUBLIC_API_URL` as base URL
  - [ ] Attaches `Authorization: Bearer {token}` to all authenticated requests
  - [ ] Parses API error responses into typed `ApiError`
  - [ ] Triggers auth logout on 401 responses
- [ ] TanStack Query v5 installed; `QueryClient` provider in `app/providers.tsx` (`"use client"`)
- [ ] Zustand installed; auth store in `lib/store/auth.ts` — holds token in memory, clears on logout
- [ ] Sonner installed; `<Toaster position="top-center" />` mounted in root `layout.tsx`

## 10. Next.js — Layout Shells (sunbites-pos)

- [ ] `KitchenLayout` (`components/layouts/kitchen-layout.tsx`):
  - [ ] Collapsible sidebar: 220px expanded, 60px icon-only collapsed
  - [ ] Sidebar: logo, branch indicator, role-aware nav items, collapse toggle, logout
  - [ ] Nav sections: main → Reports group → References group
  - [ ] Topbar: page title + branch switcher pill (admin) + user name + role badge
  - [ ] Active nav item: `bg-primary/10 text-primary font-bold border-l-[3px] border-primary`
- [ ] `AuthLayout` (`components/layouts/auth-layout.tsx`): centered card, max-width 420px, no sidebar

## 11. Next.js — Layout Shells (sunbites-portal)

- [ ] `PortalLayout` (`components/layouts/portal-layout.tsx`):
  - [ ] Top nav bar: logo left, nav links center, user dropdown right
  - [ ] Mobile-responsive: hamburger → slide-down nav drawer
- [ ] `AuthLayout` (`components/layouts/auth-layout.tsx`): centered card, max-width 420px

## 12. Next.js — Auth Pages (Both Apps)

- [ ] Login page (`app/(auth)/login/page.tsx`) in each app:
  - [ ] Email + password fields with labels
  - [ ] Error banner for failed login
  - [ ] Submit button disabled while loading
  - [ ] On success: stores token in Zustand, redirects to dashboard
- [ ] Branch selector page (`app/(auth)/branch/page.tsx`) in sunbites-pos:
  - [ ] Shown after login if staff has access to multiple branches
  - [ ] Branch cards: primary border, hover fill `bg-primary/5`
  - [ ] On select: stores active branch in Zustand, redirects to dashboard
