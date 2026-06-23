# Tasks 01 — Project Foundation

## 1. Laravel API — Configuration

- [x] `config/sunbites.php` with all constants: `credit_limit`, `loyalty_point_threshold`, `daily_meal_rate`, `school_months`, `grade_levels`
- [x] `APP_DOMAIN` env variable used to derive `config('app.domain')`
- [x] `APP_DEBUG=false` noted in `.env.example` — API must never expose stack traces in production
- [x] `config/cors.php` allows `http://localhost:3000`, `http://localhost:3001` locally and production Next.js domains; no wildcard `*`

## 2. Laravel API — Sanctum Auth

- [x] `laravel/sanctum` installed and configured
- [x] Token expiry set in `config/sanctum.php`
- [x] All protected routes use `auth:sanctum` middleware
- [x] Staff auth routes: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/user`
- [x] Parent auth routes: `POST /api/v1/portal/auth/login`, `POST /api/v1/portal/auth/logout`
- [x] Token issued on login, revoked on logout (`$request->user()->currentAccessToken()->delete()`)

## 3. Laravel API — Routes

- [x] All app routes in `routes/api.php` under `/api/v1/` prefix — requires `routes/kitchen-api.php` and `routes/portal-api.php`
- [x] `routes/kitchen-api.php` — kitchen/staff routes (no `portal` prefix)
- [x] `routes/portal-api.php` — parent portal routes (mounted under `/portal` prefix in `routes/api.php`)
- [x] `routes/web.php` serves only the main website placeholder — no frontend app routes

## 4. Laravel API — Seeders

- [x] `BranchSeeder` — Antipolo + Iloilo branches (slugs, GCash numbers)
- [x] `PermissionSeeder` — roles (admin, manager, supervisor, cashier) + all permissions via Spatie
- [x] `PosMenuItemSeeder` — 7 default items per branch (defined in Spec 04)
- [x] `WeeklyMealPlanSeeder` — default week pattern × 10 months × 4 weeks × all branches (Spec 04)
- [x] `InventoryItemSeeder` — default ingredient items per branch (Spec 04)
- [x] `DatabaseSeeder` calls all seeders in correct order; no hardcoded admin credentials

## 5. Laravel API — Admin Command

- [x] `php artisan sunbites:create-admin` interactive command
  - [x] Prompts: first name, last name, email, password
  - [x] Validates email uniqueness
  - [x] Validates password policy: min 8 chars, 1 uppercase, 1 number
  - [x] Creates user with `admin` role
  - [x] Optionally assigns a branch
  - [x] Works in all environments including production

## 6. Laravel API — Activity Logging

- [x] `spatie/laravel-activitylog` installed, `activity_log` table migrated
- [x] Auth event listeners registered via `AuthEventSubscriber`:
  - [x] Login success → `auth` log (causer = user, properties: IP, branch)
  - [x] Login failed → `auth` log (no causer, properties: email attempted, IP)
  - [x] Logout → `auth` log
- [x] `LogsActivity` trait on `User` model with explicit `$logAttributes` allowlist
  - [x] Excluded: `password`, `remember_token`, `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`
- [x] `LogsActivity` trait on `Student` model (Spec 05)
- [x] `LogsActivity` trait on `PosMenuItem` model (Spec 04)
- [x] `LogsActivity` trait on `InventoryItem` model (Spec 04)
- [x] Every log entry includes `branch_id` in `properties`
- [x] Activity Log Viewer API endpoint (Admin/Manager only) — implemented in Spec 08

## 7. Next.js — Project Initialisation (Both Apps)

- [x] `~/sunbites-pos` — Next.js initialised: TypeScript strict, App Router, Tailwind v4, ESLint
- [x] `~/sunbites-portal` — Next.js initialised: TypeScript strict, App Router, Tailwind v4, ESLint
- [x] `NEXT_PUBLIC_API_URL` in `.env.local` for each app pointing to `http://api.sunbites.test/api/v1`
- [x] `.env.example` with all required env vars documented

## 8. Next.js — Design System (Both Apps)

- [x] Tailwind v4 `@theme` color tokens in `globals.css` (all `--primary`, `--background`, `--foreground`, `--muted`, `--border`, `--sidebar` tokens)
- [x] Poppins font loaded via Google Fonts in `layout.tsx` — weights 400, 600, 700, 800
- [x] No dark mode — light mode only
- [x] shadcn/ui initialised with sunbites color tokens
- [x] `AppLogo` component with `full` and `icon` variants in each app (`components/app-logo.tsx`)

## 9. Next.js — API Client & Providers (Both Apps)

- [x] `lib/api/client.ts` — base fetch wrapper:
  - [x] Reads `NEXT_PUBLIC_API_URL` as base URL
  - [x] Attaches `Authorization: Bearer {token}` to all authenticated requests
  - [x] Parses API error responses into typed `ApiError`
  - [x] Triggers auth logout on 401 responses
- [x] TanStack Query v5 installed; `QueryClient` provider in `app/providers.tsx` (`"use client"`)
- [x] Zustand installed; auth store in `lib/store/auth.ts` — holds token in memory, clears on logout
- [x] Sonner installed; `<Toaster position="top-center" />` mounted in root `layout.tsx`

## 10. Next.js — Layout Shells (sunbites-pos)

- [x] `AppHeader` (`components/navigation/app-header.tsx`): Client Component
  - [x] Left: `☰` hamburger button (calls `onMenuOpen`) · `icon.png` (32px) · stacked brand text
  - [x] Center: current page name derived from `usePathname()` route map
  - [x] Right: branch badge · notification bell · user avatar + name + role
- [x] `AppNavSheet` (`components/navigation/app-nav-sheet.tsx`): shadcn/ui `Sheet`
  - [x] Sheet header: `icon.png` + brand text + branch badge
  - [x] Nav groups: Main → Reports → References (all items, role-filtered)
  - [x] Active nav item: `bg-primary/10 text-primary font-bold border-l-[3px] border-primary`
  - [x] All nav links call `onOpenChange(false)` on click
  - [x] Logout button at bottom
- [x] `KitchenLayout` (`components/layouts/kitchen-layout.tsx`):
  - [x] Holds `menuOpen` state; passes `onMenuOpen` to `AppHeader`, `open/onOpenChange` to `AppNavSheet`
  - [x] No static sidebar — full-width content area below `AppHeader`
- [x] `AuthLayout` (`components/layouts/auth-layout.tsx`): centered card, max-width 420px, no nav

## 11. Next.js — Layout Shells (sunbites-portal)

- [x] `PortalLayout` (`components/layouts/portal-layout.tsx`):
  - [x] Top nav bar: logo left, nav links center, user dropdown right
  - [x] Mobile-responsive: hamburger → slide-down nav drawer
- [x] `AuthLayout` (`components/layouts/auth-layout.tsx`): centered card, max-width 420px

## 12. Next.js — Auth Pages (Both Apps)

- [x] Login page (`app/(auth)/login/page.tsx`) in each app:
  - [x] Email + password fields with labels
  - [x] Error banner for failed login
  - [x] Submit button disabled while loading
  - [x] On success: stores token in Zustand, redirects to dashboard
- [x] Branch selector page (`app/(auth)/branch/page.tsx`) in sunbites-pos:
  - [x] Shown after login if staff has access to multiple branches
  - [x] Branch cards: primary border, hover fill `bg-primary/5`
  - [x] On select: stores active branch in Zustand, redirects to dashboard
