# Tasks 02 — Roles, Permissions & Authentication

## 1. Database
- [ ] `users` table with all fields: personal info, contact, address, employment, PH government IDs
- [ ] `is_active` boolean field on users (default true)
- [ ] `deleted_at` soft delete on users
- [ ] `branch_user` pivot table: `user_id`, `branch_id`, `assigned_at`, `assigned_by`
- [ ] All government ID fields nullable: `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`

## 2. Models
- [ ] `User` model with `HasFactory`, `SoftDeletes`, `HasRoles` (Spatie), `HasApiTokens` (Sanctum)
- [ ] `full_name` computed accessor on `User` model (`first_name . ' ' . last_name`)
- [ ] `User::branches()` belongsToMany relationship
- [ ] `Branch::users()` belongsToMany relationship

## 3. Seeders
- [ ] Spatie roles seeded via `PermissionSeeder`: `admin`, `manager`, `supervisor`, `cashier`
- [ ] All permissions defined and seeded

## 4. Authentication — Staff

### 4.1 Backend
- [ ] `POST /api/v1/auth/login` — validates credentials, checks branch assignment, issues Sanctum token with `staff` ability; rate limited max 5 attempts/minute per IP
- [ ] `POST /api/v1/auth/logout` — revokes current access token (`$request->user()->currentAccessToken()->delete()`)
- [ ] `GET /api/v1/auth/user` — returns authenticated staff user with branches
- [ ] `POST /api/v1/auth/password/email` — dispatches password reset email (admin-triggered)
- [ ] `POST /api/v1/auth/password/reset` — sets new password using reset token; enforces password policy (min 8 chars, 1 uppercase, 1 number)
- [ ] Login returns user data including assigned branches array for branch selector logic

### 4.2 Frontend — POS App (`~/sunbites-pos`)
- [ ] Login page at `app/(auth)/login/page.tsx` — uses `AuthLayout`; no "Forgot password?" link; shows informational message about contacting admin
- [ ] Auth Zustand store: `useAuthStore` in `lib/store/auth.ts` — stores token in memory, clears on logout
- [ ] After login: if single branch → store branch in Zustand, redirect to dashboard; if multiple branches → redirect to branch selector
- [ ] Token sent as `Authorization: Bearer {token}` on all authenticated API requests

## 5. Middleware
- [ ] `SetActiveBranch` middleware reads `X-Branch-Id` request header, validates user has access to that branch, binds `app('active_branch')` for the request lifecycle
- [ ] Applied to all API routes that require branch context

## 6. User Management API (Admin only)

### 6.1 Backend
- [ ] `UserResource` with role-based field filtering
  - [ ] Admin: full user data including government IDs
  - [ ] Manager / Supervisor / Cashier: government ID fields omitted from response
- [ ] `UserController::index()` — paginated, filterable by role/status/search
- [ ] `UserController::store()` — create user, assign role, assign branches; enforce password policy
- [ ] `UserController::show()` — full user detail
- [ ] `UserController::update()` — update profile fields
- [ ] `UserController::destroy()` — soft delete
- [ ] `UserController::deactivate()` — sets `is_active = false`
- [ ] `UserController::reactivate()` — sets `is_active = true`
- [ ] `UserController::sendResetEmail()` — dispatches password reset email to staff
- [ ] `UserController::assignBranch()` / `detachBranch()` — manage branch assignments
- [ ] Photo upload endpoint: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation, stored in `storage/app/private/photos/`
- [ ] `UserPolicy` covering all actions

## 7. User Management Frontend (POS App — Admin only)
- [ ] User list page at `app/(kitchen)/references/users/page.tsx`
  - [ ] Table: name, role badge, position, branch assignments, active status
  - [ ] Search by name or email (debounced 300ms)
  - [ ] Filter by role, filter by status
  - [ ] `[+ Add New User]` button
- [ ] Create user form at `app/(kitchen)/references/users/create/page.tsx`
  - [ ] Sections: Personal, Contact, Address, Employment, Account, Gov't IDs, Branch Assignment
  - [ ] Government IDs section clearly marked as optional with info banner
  - [ ] Role selector, branch checkboxes, required field indicators
  - [ ] Inline validation errors
- [ ] User detail/edit page at `app/(kitchen)/references/users/[id]/page.tsx`
  - [ ] Header card: photo, name, role badge, status
  - [ ] Tabs: Personal Info | Employment | Gov't IDs | Branches
  - [ ] Actions menu: Deactivate / Send Reset Email / Delete
  - [ ] Edit form with all same fields as create

## 8. Activity Logging
- [ ] `auth.password_reset` logged when staff password reset email is triggered
- [ ] `users.created` logged on new user creation (properties: role assigned)
- [ ] `users.updated` logged on profile edit (dirty-tracked)
- [ ] `users.deleted` logged on user deactivation/soft-delete
- [ ] `users.role_changed` logged when role changes (properties: old role, new role)
- [ ] `branches.switched` logged when user switches active branch (properties: from_branch_id, to_branch_id)

## 9. Tests
- [ ] Authentication tests: login success, login failure, rate limiting, no-branch rejection, token revocation on logout
- [ ] User management tests: create, update, deactivate, reactivate, password reset email
- [ ] Role/permission access tests: admin-only endpoints return 403 for other roles
- [ ] `UserResource` test: government IDs excluded from non-admin responses
