# Tasks 03 — Branch & Tenant Management

## 1. Database
- [x] `branches` table: `id`, `name`, `slug`, `gcash_number`, `address`, `is_active`, timestamps

## 2. Models & Traits
- [x] `Branch` model with factory
- [x] `BranchScope` — applies `WHERE branch_id = active_branch_id`; silently skips when `app('active_branch')` is null (no exception, no empty result)
- [x] `HasBranch` trait (in `App\Models\Concerns\`) — applies `BranchScope` globally; auto-fills `branch_id` on create; provides `withoutBranch()` escape hatch

## 3. Seeders
- [x] `BranchSeeder` — Antipolo Branch (gcash: 09074984172) and Iloilo Branch (gcash: 09922761801) via `updateOrCreate`
- [x] `DatabaseSeeder` seeds in order: `PermissionSeeder` → `BranchSeeder`

## 4. Middleware
- [x] `SetActiveBranch` middleware — reads `X-Branch-Id` request header; validates authenticated user has access to that branch (admin bypasses check); binds `app('active_branch')`; applies to all API routes requiring branch context
- [x] Returns 403 if `X-Branch-Id` is provided but the user does not have access to that branch

## 5. Branch Selector (POS App)
- [x] Branch selector page at `app/(auth)/branch/page.tsx` in `~/sunbites-pos`
  - [x] Shown after login when staff has 2+ assigned branches
  - [x] Branch cards with name, icon, hover highlight
  - [x] On select: stores `activeBranch` in Zustand auth store; redirects to `/dashboard`
- [x] Auth store (`lib/store/auth.ts`) includes `activeBranch` field
- [x] API client (`lib/api/client.ts`) reads `activeBranch` from Zustand and includes `X-Branch-Id` header on every request
- [x] After login with single assigned branch: skip selector, store branch in Zustand, redirect to dashboard

## 6. Branch Switcher (Topbar)
- [x] `BranchSwitcher` component in the `KitchenLayout` topbar
- [x] Admin and multi-branch users: clickable pill → navigates to `/branch` (branch selector page)
- [x] Single-branch Supervisor/Cashier: read-only pill showing current branch name
- [x] Hidden when no `activeBranch` is set in Zustand

## 7. Branch Management API (Admin only)
- [x] `BranchController::index()` — list branches with stats (student count, staff count, orders today)
- [x] `BranchController::update()` — edit name, address, gcash_number
- [x] `BranchController::toggleActive()` — toggle with guard: cannot deactivate last active branch (returns 422)
- [x] Routes under `role:admin` middleware:
  - `GET /api/v1/branches`
  - `PUT /api/v1/branches/{branch}`
  - `POST /api/v1/branches/{branch}/toggle`

## 8. Branch Management Frontend (POS App — Admin only)
- [x] Branch management page at `app/(kitchen)/references/branches/page.tsx`
- [x] Branch cards: name, slug, GCash number, stats
- [x] Edit branch dialog: name, address, gcash_number
- [x] Toggle active/inactive with confirmation

## 9. Activity Logging
- [x] `branches.switched` logged when user switches to a different active branch — properties: `from_branch_id`, `to_branch_id`

## 10. Tests
- [x] `BranchScopeTest` — scope skips silently with no active branch; `withoutBranch()` removes scope; auto-fills `branch_id` on create
- [x] `BranchManagementTest` — admin can view and edit; non-admin returns 403; last-branch deactivate guard returns 422; branch-scoped data does not leak across branches
- [x] `SetActiveBranchMiddlewareTest` — valid branch in header binds correctly; user without access to branch returns 403; null header skips binding
