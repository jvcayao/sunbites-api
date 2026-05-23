# Spec 02 — Roles, Permissions & Authentication

## Overview

Two completely separate authentication systems:
1. **Staff auth** — staff accounts managed by admin, uses `users` table, authenticated via the POS app
2. **Parent auth** — parent accounts self-registered, uses `parents` table, authenticated via the Portal app

Both use **Sanctum token-based auth**. Staff tokens carry the `staff` ability; parent tokens carry the `parent` ability. This enforces role boundaries at the API level — a parent token cannot call staff-only routes and vice versa.

---

## Staff Authentication

### Auth Endpoints
```
POST /api/v1/auth/login        — staff login → returns Sanctum token
POST /api/v1/auth/logout       — revoke current token
GET  /api/v1/auth/user         — get authenticated staff user
POST /api/v1/auth/password/email  — send password reset email
POST /api/v1/auth/password/reset  — set new password via token
```

All protected staff routes require `auth:sanctum` middleware with `ability:staff` check.

**No self-registration** — all staff accounts are created by Admin via User Management.

**No self-service forgot password** — users must contact Admin or Manager. The admin triggers the reset email from User Management. The POS login page does not expose a "Forgot password?" link.

### Login Flow
1. Staff submits email + password to `POST /api/v1/auth/login`
2. Rate limited: **max 5 attempts per minute per IP**
3. On success: check if user has at least one branch assigned
   - If no branches → reject login with message: *"Your account has no branch assigned. Contact your administrator."*
   - Return Sanctum token and user data (including assigned branches)
4. POS Next.js app stores the token in Zustand (memory only)
5. If staff has one branch: store that branch in Zustand, redirect to dashboard
6. If staff has multiple branches: redirect to branch selector page — user picks a branch, stored in Zustand

### Password Reset (Admin-Initiated Only)
1. Staff informs Admin or Manager they have forgotten their password
2. Admin/Manager navigates to **References > User Management > [user profile]**
3. Admin/Manager clicks **"Send Password Reset Email"** — calls the backend which dispatches a reset email
4. Staff receives email with a secure reset link
5. Staff clicks the link, sets a new password (min 8 chars, 1 uppercase, 1 number)
6. Staff returns to login

The POS login page renders an informational message directing users to contact their Admin or Manager — no self-service password request form.

### Roles (via spatie/laravel-permission)

| Role | Key |
|---|---|
| Admin | `admin` |
| Manager | `manager` |
| Supervisor | `supervisor` |
| Cashier | `cashier` |

### Role Capability Matrix

| Capability | Admin | Manager | Supervisor | Cashier |
|---|:---:|:---:|:---:|:---:|
| Access any branch | ✅ | ❌ | ❌ | ❌ |
| Switch active branch | ✅ | ✅ (assigned only) | ✅ (assigned only) | ❌ |
| Dashboard & analytics | ✅ | ✅ | ✅ | ❌ |
| POS — process orders | ✅ | ✅ | ✅ | ✅ |
| POS — apply discounts | ✅ | ✅ | ✅ | ❌ |
| POS — void/cancel transaction | ✅ | ✅ | ✅ | ❌ |
| Student enrollment | ✅ | ✅ | ✅ | ❌ |
| Student wallet top-up | ✅ | ✅ | ✅ | ❌ |
| Approve parent–student link requests | ✅ | ✅ | ✅ | ❌ |
| Menu & product management | ✅ | ✅ | ❌ | ❌ |
| User management (create/edit staff) | ✅ | ❌ | ❌ | ❌ |
| Branch assignment (add user to branch) | ✅ | ❌ | ❌ | ❌ |
| View sales reports | ✅ | ✅ | ✅ ¹ | ❌ |
| Export reports | ✅ | ✅ | ❌ | ❌ |
| Inventory management | ✅ | ✅ | ✅ | ❌ |
| Reference data management | ✅ | ✅ | ❌ | ❌ |

¹ **Supervisor report access exception:** Supervisors can view Sales, Student, and Inventory reports but are **explicitly excluded** from the Wallet Report — that is Admin/Manager only (see Spec 08).

---

## User Data Model

```
users
  -- Authentication
  id
  email                   (string, unique)
  password                (string)
  email_verified_at       (timestamp, nullable)
  remember_token          (string, nullable)
  is_active               (boolean, default true)

  -- Personal Information
  first_name              (string)
  last_name               (string)
  middle_name             (string, nullable)
  nickname                (string, nullable)
  birthday                (date, nullable)
  gender                  (enum: male, female, other, nullable)
  civil_status            (enum: single, married, widowed, separated, nullable)
  profile_photo_path      (string, nullable)

  -- Contact
  phone                   (string, nullable)
  emergency_contact_name  (string, nullable)
  emergency_contact_phone (string, nullable)
  emergency_contact_relationship (string, nullable)

  -- Address
  address_line            (string, nullable)
  city                    (string, nullable)
  province                (string, nullable)
  zip_code                (string, nullable)

  -- Employment
  position                (string, nullable)
  employment_type         (enum: full_time, part_time, contractual, nullable)
  date_hired              (date, nullable)
  daily_rate              (decimal 8,2, nullable)

  -- Philippine Government IDs (all nullable)
  sss_number              (string, nullable)
  pagibig_number          (string, nullable)
  philhealth_number       (string, nullable)
  tin_number              (string, nullable)

  created_at, updated_at, deleted_at (soft delete)

branch_user (pivot)
  user_id, branch_id, assigned_at, assigned_by
```

**Notes:**
- `full_name` is a computed accessor: `first_name . ' ' . last_name`
- Government ID fields are nullable and optional — never format-validated
- `daily_rate` is for future payroll reference only — not computed in this phase
- Soft delete retains history

**Government ID Access Restriction:**
`sss_number`, `pagibig_number`, `philhealth_number`, `tin_number` must **never** appear in API responses for Manager, Supervisor, or Cashier roles. Enforced in `UserResource` with role-based field filtering:
- Admin: full user data including government IDs
- Manager / Supervisor / Cashier: government ID fields omitted from the response

---

## Branch Assignment

- Admin assigns branches to users via User Management
- A user with no branches cannot log in to the POS app
- Admin is implicitly allowed all branches — no pivot record required
- A user can be assigned multiple branches
- On login, the POS app resolves the user's assigned branches from the API response

---

## User Management (Admin only)

Located in **References > User Management** in the sidebar.

### Features
- List all staff users with role badge, position, and branch assignments
- Create new user with full profile
- Edit user: all personal, contact, address, employment, and government ID fields
- Government ID fields shown in a dedicated "Government IDs" section — clearly marked as optional
- Assign/remove branches from a user
- Deactivate a user account (soft-disable)
- Reactivate a user account
- Password reset: admin can trigger reset email to staff
- Profile photo upload per user

### Constraints
- Admin cannot deactivate or delete their own account
- At least one Admin account must exist at all times

### Password Policy
New staff passwords must pass validation:
- Minimum 8 characters
- At least one uppercase letter
- At least one number
- Applied on account creation and on password reset

### Staff Profile Photo Upload
- Accepted MIME types: `image/jpeg`, `image/png`, `image/webp` only
- Max file size: 2MB
- Validate server-side before storage — reject invalid uploads with 422 error
- Store in `storage/app/private/photos/` (not publicly accessible)

---

## Parent Authentication (Separate System)

Full spec in Spec 07. Summary:
- Auth via `POST /api/v1/portal/auth/login`
- Token carries `parent` ability — cannot call staff API routes
- Self-registration via `POST /api/v1/portal/auth/register`
- Login, logout, forgot password, reset password via `/api/v1/portal/auth/*` endpoints
- Parents cannot access staff API routes and vice versa

---

## API Middleware Reference

| Middleware | Purpose |
|---|---|
| `auth:sanctum` | Requires valid Sanctum Bearer token |
| `ability:staff` | Token must carry `staff` ability — enforces staff-only routes |
| `ability:parent` | Token must carry `parent` ability — enforces portal-only routes |
| `role:admin` | Requires admin Spatie role |
| `role:admin,manager` | Admin or Manager |
| `SetActiveBranch` | Reads `X-Branch-Id` header, validates user access, binds active branch |

---

## Requirements

- [ ] `users` table with all fields: personal info, contact, address, employment, PH government IDs
- [ ] All government ID fields nullable — no format enforcement
- [ ] Government IDs excluded from API responses for all roles except Admin — enforced via `UserResource`
- [ ] Rate limiting on staff login endpoint: max 5 attempts/minute per IP
- [ ] Password policy on account creation and reset: min 8 chars, 1 uppercase, 1 number
- [ ] Staff profile photo: MIME whitelist (jpeg/png/webp), max 2MB, server-side validation
- [ ] `full_name` computed accessor on User model
- [ ] Soft delete on `users` table; `is_active` boolean for deactivation
- [ ] `branch_user` pivot table for branch assignments
- [ ] Sanctum token issued on login with `staff` ability; revoked on logout
- [ ] Login rejects users with no branch assignment with clear error message in API response
- [ ] Login returns user data including assigned branches — POS app routes to branch selector when 2+ branches
- [ ] Password reset flow: admin triggers reset email from User Management; `/api/v1/auth/password/email` and `/api/v1/auth/password/reset` endpoints
- [ ] POS login page has no self-service forgot password link — shows informational message only
- [ ] Spatie roles seeded: `admin`, `manager`, `supervisor`, `cashier`
- [ ] All permissions defined and seeded via `PermissionSeeder`
- [ ] `SetActiveBranch` middleware reads `X-Branch-Id` header, validates access, binds branch to container
- [ ] `UserResource` with role-based field filtering (government IDs hidden from non-admin)
- [ ] User management CRUD API endpoints (admin only)
- [ ] Branch assignment API on each user (admin only)
- [ ] Deactivate/reactivate account endpoints
- [ ] `UserPolicy` covering view, create, update, deactivate, delete
- [ ] Log `auth.login` on successful login (properties: IP, branch)
- [ ] Log `auth.failed` on failed login attempt (properties: email attempted, IP)
- [ ] Log `auth.logout` on logout
- [ ] Log `auth.password_reset` when a staff password is reset (properties: reset triggered by)
- [ ] Log `users.created` when a new user account is created (properties: role assigned)
- [ ] Log `users.updated` when user profile is edited (dirty-tracked)
- [ ] Log `users.deleted` when a user is deactivated/soft-deleted
- [ ] Log `users.role_changed` when a user's role is changed (properties: old role, new role)
