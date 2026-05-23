# Spec 03 — Branch & Tenant Management

## Overview

All data in the application is scoped to a branch. Two branches exist: **Antipolo** and **Iloilo**. A branch acts as the tenant. Students, orders, staff, inventory, and reports are always filtered by the active branch.

---

## Branch Data Model

```
branches
  id              (int, auto-increment)
  name            (string)           — e.g. "Antipolo Branch"
  slug            (string, unique)   — e.g. "antipolo"
  gcash_number    (string, nullable) — GCash number for payments
  address         (string, nullable)
  is_active       (boolean, default true)
  created_at, updated_at
```

### Seeded Branches
| id | name | slug | gcash_number |
|---|---|---|---|
| 1 | Antipolo Branch | antipolo | 09074984172 |
| 2 | Iloilo Branch | iloilo | 09922761801 |

---

## Tenant Scoping Strategy

### Active Branch Resolution
The active branch is resolved on every API request via the `SetActiveBranch` middleware:
1. Reads the `X-Branch-Id` header sent by the Next.js app
2. Validates the authenticated user has access to that branch
3. Binds the branch to the container: `app()->instance('active_branch', $branch)`
4. Available within the request via `app('active_branch')`

The Next.js apps store the active branch in Zustand. Every API request automatically includes the `X-Branch-Id` header from the auth store. When staff changes branches, the Zustand store is updated and subsequent API requests use the new branch.

### Model Scoping
All branch-scoped models use a `BranchScope` global scope via the `HasBranch` trait:
- Automatically applies `WHERE branch_id = active_branch_id` on all queries
- Automatically sets `branch_id` on `create()` calls

**Null active branch handling:** `BranchScope` checks whether `app('active_branch')` is bound and non-null before applying the WHERE clause. When null (during seeding, Artisan commands, queue workers, or tests), the scope is **skipped silently** — no error, no empty result.

For queries that must bypass the scope:
```php
Student::withoutBranch()->get();  // disables BranchScope for this query
```

Models that are branch-scoped:
- `Student`
- `Order` / `OrderItem`
- `PosMenuItem`
- `WeeklyMealPlan`
- `InventoryItem`
- `WalletTransaction` (via wallet package)

### Admin Branch Override
Admin users can view data from any branch. When admin selects a different branch in the POS app, the Zustand store updates and the next API request uses the new `X-Branch-Id`.

---

## Branch Selector (POS App)

### Page: `pos.sunbites.com.ph/branch`
Shown after login when staff has 2+ assigned branches. Uses `AuthLayout`.

**Content:**
- Logo top center
- Welcome message: "Welcome back, {name}"
- Subtitle: "Select your branch to continue"
- Branch cards in a flex row (wrap on mobile):
  - Branch name (large, bold)
  - Branch icon
  - Hover: card border highlights with primary color
  - Click: stores branch in Zustand store, sets `X-Branch-Id` for all subsequent API calls, redirects to dashboard

### Branch Switcher (Topbar — POS App)
- Only visible to Admin and multi-branch users
- Pill button showing current branch name with "⇄ Switch" label
- Click redirects to the branch selector page (no re-login required)
- Admin can switch to any active branch; other roles can only switch between their assigned branches
- Cashiers with a single branch: branch shown as a read-only pill (no switch option)

---

## Branch Management (Admin — References)

Located in **References > Branches** in the POS app.

### Features
- List branches with name, slug, GCash number, active status, student count, staff count
- Edit branch: update name, address, GCash number
- Toggle branch active/inactive

### Constraints
- Cannot deactivate the last active branch
- Slug is immutable after creation
- No "Add Branch" button — branches are fixed (Antipolo + Iloilo)

---

## Data Isolation Rules

| Rule | Description |
|---|---|
| Students are branch-scoped | A student in Antipolo cannot appear in Iloilo reports or POS |
| Menu items are branch-scoped | Each branch manages its own POS menu items |
| Orders are branch-scoped | Sales reports only show orders for the active branch |
| Inventory is branch-scoped | Each branch tracks its own inventory levels |
| Meal plans are branch-scoped | Each branch can maintain a different weekly meal schedule |
| Staff see only their branch | Non-admin staff cannot switch to another branch |
| Admin sees all | Admin can freely switch between branches |

---

## Requirements

- [ ] `branches` table seeded with Antipolo and Iloilo
- [ ] `SetActiveBranch` middleware reads `X-Branch-Id` header, validates user access, binds `app('active_branch')`
- [ ] `HasBranch` trait auto-scopes queries and auto-fills `branch_id` on create
- [ ] `BranchScope` skips silently when `app('active_branch')` is null (seeders, Artisan commands, queue workers, tests)
- [ ] `withoutBranch()` escape hatch available for queries that must bypass the scope
- [ ] Branch selector page at `pos.sunbites.com.ph/branch` — shown after login when staff has 2+ branches
- [ ] Branch selection stores active branch in Zustand; all subsequent API calls include `X-Branch-Id` header
- [ ] Branch switcher in `KitchenLayout` topbar redirects to branch selector page; Admin can switch to any branch
- [ ] Branch switcher visible to Admin and multi-branch users; Cashier/Supervisor with single branch see read-only pill
- [ ] Branch management API in References (admin only): list, edit name/address/gcash, toggle active/inactive
- [ ] Toggle active/inactive with guard against deactivating last active branch (returns 422)
- [ ] All scoped models tested — queries must not leak cross-branch data
- [ ] Log `branches.switched` when user switches active branch (properties: from_branch_id, to_branch_id)
