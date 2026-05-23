# Tasks 02 — Roles, Permissions & Authentication

## 1. Database
- [x] `users` table with all fields: personal info, contact, address, employment, PH government IDs
- [x] `is_active` boolean field on users (default true)
- [x] `deleted_at` soft delete on users
- [x] `branch_user` pivot table: `user_id`, `branch_id`, `assigned_at`, `assigned_by`
- [x] All government ID fields nullable: `sss_number`, `pagibig_number`, `philhealth_number`, `tin_number`

## 2. Models
- [x] `User` model with `HasFactory`, `SoftDeletes`, `HasRoles` (Spatie), `HasApiTokens` (Sanctum)
- [x] `full_name` computed accessor on `User` model (`first_name . ' ' . last_name`)
- [x] `User::branches()` belongsToMany relationship
- [x] `Branch::users()` belongsToMany relationship

## 3. Seeders
- [x] Spatie roles seeded via `PermissionSeeder`: `admin`, `manager`, `supervisor`, `cashier`
- [x] All permissions defined and seeded

## 4. Authentication — Staff

### 4.1 Backend
- [x] `POST /api/v1/auth/login` — validates credentials, checks branch assignment, issues Sanctum token with `staff` ability; rate limited max 5 attempts/minute per IP
- [x] `POST /api/v1/auth/logout` — revokes current access token (`$request->user()->currentAccessToken()->delete()`)
- [x] `GET /api/v1/auth/user` — returns authenticated staff user with branches
- [x] `POST /api/v1/auth/password/email` — dispatches password reset email (admin-triggered)
- [x] `POST /api/v1/auth/password/reset` — sets new password using reset token; enforces password policy (min 8 chars, 1 uppercase, 1 number)
- [x] Login returns user data including assigned branches array for branch selector logic

### 4.2 Frontend — POS App (`~/sunbites-pos`)
- [x] Login page at `app/(auth)/login/page.tsx` — uses `AuthLayout`; no "Forgot password?" link; shows informational message about contacting admin
- [x] Auth Zustand store: `useAuthStore` in `lib/store/auth.ts` — stores token in memory, clears on logout
- [x] After login: if single branch → store branch in Zustand, redirect to dashboard; if multiple branches → redirect to branch selector
- [x] Token sent as `Authorization: Bearer {token}` on all authenticated API requests

## 5. Middleware
- [x] `SetActiveBranch` middleware reads `X-Branch-Id` request header, validates user has access to that branch, binds `app('active_branch')` for the request lifecycle
- [x] Applied to all API routes that require branch context

## 6. User Management API (Admin only)

### 6.1 Backend
- [x] `UserResource` with role-based field filtering
  - [x] Admin: full user data including government IDs
  - [x] Manager / Supervisor / Cashier: government ID fields omitted from response
- [x] `UserController::index()` — paginated, filterable by role/status/search
- [x] `UserController::store()` — create user, assign role, assign branches; enforce password policy
- [x] `UserController::show()` — full user detail
- [x] `UserController::update()` — update profile fields
- [x] `UserController::destroy()` — soft delete (via deactivate; hard delete not a feature)
- [x] `UserController::deactivate()` — sets `is_active = false`
- [x] `UserController::reactivate()` — sets `is_active = true`
- [x] `UserController::sendResetEmail()` — dispatches password reset email to staff
- [x] `UserController::assignBranch()` / `detachBranch()` — manage branch assignments
- [x] Photo upload endpoint: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation, stored in `storage/app/private/photos/`
- [x] `UserPolicy` covering all actions

## 7. User Management Frontend (POS App — Admin only)
- [x] User list page at `app/(kitchen)/references/users/page.tsx`
  - [x] Table: name, role badge, position, branch assignments, active status
  - [x] Search by name or email (debounced 300ms)
  - [x] Filter by role, filter by status
  - [x] `[+ Add New User]` button
- [x] Create user form at `app/(kitchen)/references/users/create/page.tsx`
  - [x] Sections: Personal, Contact, Address, Employment, Account, Gov't IDs, Branch Assignment
  - [x] Government IDs section clearly marked as optional with info banner
  - [x] Role selector, branch checkboxes, required field indicators
  - [x] Inline validation errors
- [x] User detail/edit page at `app/(kitchen)/references/users/[id]/page.tsx`
  - [x] Header card: photo, name, role badge, status
  - [x] Tabs: Personal Info | Employment | Gov't IDs | Branches
  - [x] Actions menu: Deactivate / Send Reset Email / Reactivate
  - [x] Edit form with all same fields as create

## 8. Activity Logging
- [x] `auth.password_reset` logged when staff password reset email is triggered
- [x] `users.created` logged on new user creation (properties: role assigned)
- [x] `users.updated` logged on profile edit (dirty-tracked)
- [x] `users.deleted` logged on user deactivation/soft-delete
- [x] `users.role_changed` logged when role changes (properties: old role, new role)
- [x] `branches.switched` logged when user switches active branch (properties: from_branch_id, to_branch_id)

## 9. Tests
- [x] Authentication tests: login success, login failure, rate limiting, no-branch rejection, token revocation on logout
- [x] User management tests: create, update, deactivate, reactivate, password reset email
- [x] Role/permission access tests: admin-only endpoints return 403 for other roles
- [x] `UserResource` test: government IDs excluded from non-admin responses
