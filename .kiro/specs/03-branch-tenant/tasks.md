# Tasks 03 — Branch & Tenant Management

## 1. Database
- [ ] `branches` table: `id`, `name`, `slug`, `gcash_number`, `address`, `is_active`, timestamps

## 2. Models & Traits
- [ ] `Branch` model with factory
- [ ] `BranchScope` — applies `WHERE branch_id = active_branch_id`; silently skips when `app('active_branch')` is null (no exception, no empty result)
- [ ] `HasBranch` trait (in `App\Models\Concerns\`) — applies `BranchScope` globally; auto-fills `branch_id` on create; provides `withoutBranch()` escape hatch

## 3. Seeders
- [ ] `BranchSeeder` — Antipolo Branch (gcash: 09074984172) and Iloilo Branch (gcash: 09922761801) via `updateOrCreate`
- [ ] `DatabaseSeeder` seeds in order: `PermissionSeeder` → `BranchSeeder`

## 4. Middleware
- [ ] `SetActiveBranch` middleware — reads `X-Branch-Id` request header; validates authenticated user has access to that branch (admin bypasses check); binds `app('active_branch')`; applies to all API routes requiring branch context
- [ ] Returns 403 if `X-Branch-Id` is provided but the user does not have access to that branch

## 5. Branch Selector (POS App)
- [ ] Branch selector page at `app/(auth)/branch/page.tsx` in `~/sunbites-pos`
  - [ ] Shown after login when staff has 2+ assigned branches
  - [ ] Branch cards with name, icon, hover highlight
  - [ ] On select: stores `activeBranch` in Zustand auth store; redirects to `/dashboard`
- [ ] Auth store (`lib/store/auth.ts`) includes `activeBranch` field
- [ ] API client (`lib/api/client.ts`) reads `activeBranch` from Zustand and includes `X-Branch-Id` header on every request
- [ ] After login with single assigned branch: skip selector, store branch in Zustand, redirect to dashboard

## 6. Branch Switcher (Topbar)
- [ ] `BranchSwitcher` component in the `KitchenLayout` topbar
- [ ] Admin and multi-branch users: clickable pill → navigates to `/branch` (branch selector page)
- [ ] Single-branch Supervisor/Cashier: read-only pill showing current branch name
- [ ] Hidden when no `activeBranch` is set in Zustand

## 7. Branch Management API (Admin only)
- [ ] `BranchController::index()` — list branches with stats (student count, staff count, orders today)
- [ ] `BranchController::update()` — edit name, address, gcash_number
- [ ] `BranchController::toggleActive()` — toggle with guard: cannot deactivate last active branch (returns 422)
- [ ] Routes under `role:admin` middleware:
  - `GET /api/v1/branches`
  - `PUT /api/v1/branches/{branch}`
  - `POST /api/v1/branches/{branch}/toggle`

## 8. Branch Management Frontend (POS App — Admin only)
- [ ] Branch management page at `app/(kitchen)/references/branches/page.tsx`
- [ ] Branch cards: name, slug, GCash number, stats
- [ ] Edit branch dialog: name, address, gcash_number
- [ ] Toggle active/inactive with confirmation

## 9. Activity Logging
- [ ] `branches.switched` logged when user switches to a different active branch — properties: `from_branch_id`, `to_branch_id`

## 10. Tests
- [ ] `BranchScopeTest` — scope skips silently with no active branch; `withoutBranch()` removes scope; auto-fills `branch_id` on create
- [ ] `BranchManagementTest` — admin can view and edit; non-admin returns 403; last-branch deactivate guard returns 422; branch-scoped data does not leak across branches
- [ ] `SetActiveBranchMiddlewareTest` — valid branch in header binds correctly; user without access to branch returns 403; null header skips binding
