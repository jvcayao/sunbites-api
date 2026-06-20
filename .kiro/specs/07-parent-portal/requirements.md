# Spec 07 — Parent Portal

## Overview

The Parent Portal lives at `portal.sunbites.com.ph` (production) and `localhost:3001` (local). It is a separate Next.js application (`~/sunbites-portal`) for parents to monitor their child's canteen activity, wallet balance, and communicate with the canteen. Parents authenticate separately from kitchen staff, using a dedicated Sanctum token endpoint that issues tokens with the `parent` ability.

Parent accounts are **provisioned automatically at enrollment** — there is no self-registration. When a guardian is enrolled with an email address, the system creates their account and emails them an activation link. The parent sets their password via that link before their first login.

---

## Authentication

### Token-Based Auth (Sanctum)
- Parent login: `POST /api/v1/portal/auth/login` — returns a Sanctum token with `parent` ability
- Parent logout: `POST /api/v1/portal/auth/logout` — revokes token
- Forgot password: `POST /api/v1/portal/auth/forgot-password` — always returns the same generic response regardless of email existence (prevents account enumeration); sends the appropriate email server-side (activation if not yet activated, password reset if already activated)
- Reset password / Activate: `POST /api/v1/portal/auth/reset-password` — shared endpoint for both initial activation and subsequent password resets
- All portal API routes are protected by `auth:sanctum` + `ability:parent` middleware

### Token Storage
- Parent token stored in `sessionStorage` via Zustand `persist` middleware — survives page refresh (required for `EchoProvider` to reinitialize Echo on reload without losing channel subscription)
- `~/sunbites-portal` Zustand auth store holds the token; the API client reads it and attaches `Authorization: Bearer {token}` to every request

### Rate Limiting
- Login and forgot-password endpoints: max 5 attempts per 5 minutes per IP

### Parents Table
```
parents
  id
  first_name         (string)
  last_name          (string)
  email              (string, unique)
  password           (string, nullable — null until activated)
  phone              (string, nullable)
  address            (string, nullable)
  profile_photo_path (string, nullable)
  email_verified_at  (timestamp, nullable — null = not yet activated; set on first password set)
  remember_token     (string, nullable)
  disabled_at        (timestamp, nullable — set when staff disables the account; null = active)
  created_at, updated_at, deleted_at (soft delete)
```

`email_verified_at` serves as the activation flag. An account with `email_verified_at = null` is provisioned but not yet activated — the parent cannot log in.

---

## Account Provisioning (via Enrollment)

Parent accounts are created by the system during enrollment or when a guardian contact with an email is added from the student's Contacts tab in the POS app. See Spec 05 for the full provisioning flow.

### Activation Flow
1. Parent receives `ParentWelcomeMail` with a signed activation link (uses Laravel `PasswordBroker` token, expires in 60 minutes)
2. Link opens: `portal.sunbites.com.ph/activate?token=...&email=...`
3. Parent sets a new password (min 8 chars, confirmed)
4. On submit: `POST /api/v1/portal/auth/reset-password` — sets password, sets `email_verified_at = now()`
5. Redirect to login page with success toast: "Account activated! You can now log in."

### Forgot Password (Pre-Activation)
- If a parent tries to log in before activating: return 401 with error `account_not_activated`
- Portal login page shows: "Your account has not been activated yet. Check your email for the activation link, or contact the canteen to resend it."
- Forgot-password link on portal: always returns generic "If an account exists, we'll send an email." Server sends the activation email (not a regular reset) if `email_verified_at` is null.

### Resend Activation (POS)
- Kitchen staff can resend the activation email from: Student detail → Contacts tab → `[Resend Activation Email]`
- Only available when the parent account has `email_verified_at = null`
- Rate-limited: max 3 resends per guardian per 24 hours (server-side)
- See Spec 05 for the API endpoint

---

## Parent Profile

Accessible at: `portal.sunbites.com.ph/profile`

### Editable Fields
- First name, Last name
- Phone number
- Address
- Profile photo upload — MIME whitelist: `image/jpeg`, `image/png`, `image/webp` only; max 2MB; server-side validation
- Password change (current password required) — via a **dedicated** `POST /api/v1/portal/profile/change-password` endpoint; not mixed into the profile PATCH

### Profile Photo Storage & Serving
- Photos are stored on the **public** disk under `photos/parents/`
- The API returns `profile_photo_url` — a fully-qualified public URL (e.g. `https://api.sunbites.com.ph/storage/photos/parents/abc.jpg`) — not a raw file path
- The portal renders this URL directly in `<Image src={profile.profile_photo_url} />`
- `profile_photo_path` (raw path) must never be returned to the frontend; the controller resolves it to a URL before returning

---

## Linked Students

A parent can be linked to multiple students (e.g. siblings enrolled at the same canteen). Links are established automatically at enrollment or when a contact is added in the POS Contacts tab. There is no manual link-request flow.

### parent_student (pivot table)
```
parent_student
  id
  parent_id                (FK → parents)
  student_id               (FK → students)
  linked_at                (timestamp)
  linked_by                (FK → users — the staff member who enrolled/added the contact)
  wallet_alert_threshold   (decimal, default 0)

  UNIQUE KEY: (parent_id, student_id)
```

### One-to-Many: 1 Parent → Many Students
- A parent can be linked to multiple students (siblings)
- A student is linked based on the guardian contacts registered at enrollment or added via the Contacts tab
- Multiple contacts on one student may share the same parent email — only one `parent_student` pivot row is created (idempotent)

---

## Parent Dashboard

Accessible at: `portal.sunbites.com.ph/dashboard`

### When No Students Are Linked
- Informational card: "Your account is set up but no students are linked yet. Please contact the canteen if you believe this is an error."
- No self-service linking available

### When Students Are Linked
- Student selector (tabs or dropdown) if multiple students linked
- Per-student dashboard showing:

#### Outstanding Credit Alert
- Shown only when `student.credit_balance > 0`
- Warning card: `bg-red-50 border-red-300` — "Your child has an outstanding canteen credit of ₱X. Please settle with the canteen."
- Displayed above the wallet card

#### Wallet Card
- Current wallet balance (large, prominent)
- Last top-up date and amount
- Quick view of last 5 transactions
- "Set Alert Threshold" link

#### Spending Summary Cards
- Today's total spent
- This week's total spent
- This month's total spent

#### Recent Purchases (today)
- List of items purchased today with time and amount

---

## Child Activity Tracking

### Authorization (IDOR Prevention)
All student-scoped routes (`/api/v1/portal/students/{id}/activity`, `/api/v1/portal/students/{id}/wallet`) must verify **before returning any data** that a confirmed `parent_student` link exists between the currently authenticated parent and the `{id}` in the URL. If no link exists, return 403 — never return data based on URL parameter alone.

Implemented via `ParentStudentPolicy::view()` checked on every student-scoped portal endpoint.

### Per-Student Spending Breakdown

#### Filters
- Date range: Today / This Week / This Month / Custom Range
- View: By Day / By Week / By Month

#### Table View
Columns: Date, Item Purchased, Quantity, Amount, Running Balance

#### Summary Panel
- Total spent in selected period
- Number of visits (unique order days)
- Most purchased item
- Average spend per day

---

## Wallet Tracking

### Wallet Balance Card
- Current balance
- Total topped up (all time)
- Total spent (all time)

### Transaction History Table
Columns: Date, Type (Credit / Debit), Amount, Balance After, Note/Reference

### Wallet Alert Setting
- Parent can set a minimum balance threshold per linked student
- When wallet balance drops below threshold at POS withdrawal, a queued email job is dispatched (`WalletAlertJob`)
- Default threshold: ₱0 (no alert)
- Stored on `parent_student` pivot: `wallet_alert_threshold`
- **Ownership validation required:** endpoint that updates `wallet_alert_threshold` must verify via `ParentStudentPolicy` that the authenticated parent owns the `parent_student` link — never accept a raw `parent_student_id` from the client without policy check

---

## Meal Planner (Read-Only)

Accessible at: `portal.sunbites.com.ph/meal-plan`

Parents view the canteen's weekly meal schedule. Same data managed by kitchen staff in Spec 04 — read-only.

### Layout
- **Month tabs**: pill buttons for all 10 school months explicitly shown in order — Jun, Jul, Aug, Sep, Oct, Nov, Dec, Jan, Feb, Mar. Horizontally scrollable on mobile. Active month highlighted with primary color fill.
- **Week tabs**: Week 1 / Week 2 / Week 3 / Week 4
- **Meal grid**: rows = Monday–Friday; columns = Day, Ulam, Vegetables, Fruit, Soup, Snacks (all 5 always shown)
  - All cells rendered as plain text (no inputs)
  - Empty cells shown as "—"
- **Unpublished week**: when the canteen has not published the selected week (`visible_to_parents = false`), show an informational card — "Meal plan for this week is not yet available." — in place of the table
- No Save, Reset, or visibility toggle controls visible

### Branch Scoping
- Branch derived from the parent's linked student's branch
- If a parent has students in multiple branches, show a branch selector above the month tabs

---

## Feedback System

Accessible at: `portal.sunbites.com.ph/feedback`

### Feedback Form
- Rating (1–5 stars)
- Category: Food Quality / Service / Portion Size / Cleanliness / General
- Message (textarea, optional) — sanitized server-side with `strip_tags()` before storage
- Student selector (which child this is about, optional)

### Feedback Table
```
feedbacks
  id
  parent_id        (FK → parents)
  student_id       (FK → students, nullable)
  branch_id        (FK → branches)
  rating           (int 1–5)
  category         (enum: FoodQuality, Service, PortionSize, Cleanliness, General — TitleCase)
  message          (text, nullable)
  is_read          (boolean, default false)
  admin_reply      (text, nullable) — sanitized with strip_tags() before storage
  replied_at       (timestamp, nullable)
  created_at
```

### Kitchen Staff View (References > Feedback in POS app)
- List of all feedback for the active branch
- Unread badge count on sidebar
- Mark as read, reply to feedback (reply sent as email to parent)
- Filter by rating, category, date, read/unread

---

## Parent Management Page (POS App)

Accessible at: `pos.sunbites.com.ph/references/parents`, roles: Admin, Manager, Supervisor.

This page gives kitchen staff a full view of all parent accounts and their linked children, without needing to navigate per-student.

### Parent List
- Table columns: Name, Email, Activation Status (`Activated` / `Pending`), Linked Students (count + names), Registered Date, Last Login
- Filter by: activation status, branch (filters parents whose students belong to the branch)
- Search by parent name or email
- Default sort: registered date descending

### Parent Detail (drawer or page)
- Parent profile info: name, email, phone, address, activation status
- **Linked Students** list: each linked student shown as a card with name, grade, branch, and a link to the student detail page
- **Actions:**
  - `Resend Activation Email` — visible only when `email_verified_at` is null; rate-limited max 3 per 24 hours
  - `Send Password Reset Email` — visible only when `email_verified_at` is not null

### Account Management Actions (Admin/Manager Only)
- **Disable** — sets `disabled_at`; prevents parent from logging in (blocked at auth middleware) without removing their data or links
- **Enable** — clears `disabled_at`; restores login access
- **Soft-delete** — sets `deleted_at`; hides account from active list; parent cannot log in
- **Restore** — clears `deleted_at`; recovers soft-deleted account

Parent profile info (name, email) is not editable from this page; parents edit their own profile from the portal.

---

## API Routes

All portal routes under `auth:sanctum` + `ability:parent` middleware unless noted.

| Method | Route | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/portal/auth/login` | public | Parent login — returns token with `parent` ability |
| POST | `/api/v1/portal/auth/logout` | parent | Revoke token |
| POST | `/api/v1/portal/auth/forgot-password` | public | Send activation or reset link (generic response always) |
| POST | `/api/v1/portal/auth/reset-password` | public | Set password and activate account (or reset) |
| GET | `/api/v1/portal/profile` | parent | Get parent profile — returns `profile_photo_url` (full public URL), not raw path |
| PATCH | `/api/v1/portal/profile` | parent | Update name, phone, address (no password) |
| POST | `/api/v1/portal/profile/change-password` | parent | Change password — requires `current_password`, `password`, `password_confirmation` |
| GET | `/api/v1/portal/dashboard` | parent | Dashboard data for linked students |
| GET | `/api/v1/portal/students` | parent | Linked students list |
| GET | `/api/v1/portal/students/{id}/activity` | parent | Spending activity (IDOR-protected) |
| GET | `/api/v1/portal/students/{id}/wallet` | parent | Wallet history (IDOR-protected) |
| PUT | `/api/v1/portal/students/{id}/wallet-alert` | parent | Set alert threshold (IDOR-protected) |
| GET | `/api/v1/portal/meal-planner` | parent | Read-only meal planner |
| GET | `/api/v1/portal/feedback` | parent | Own feedback list |
| POST | `/api/v1/portal/feedback` | parent | Submit feedback |

POS staff routes (under `ability:staff`):

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/references/parents` | admin, manager, supervisor | Paginated parent list with filters |
| GET | `/api/v1/references/parents/{parent}` | admin, manager, supervisor | Parent detail with linked students (including soft-deleted) |
| POST | `/api/v1/references/parents/{parent}/resend-activation` | admin, manager, supervisor | Resend activation email (rate-limited, only when not activated) |
| POST | `/api/v1/references/parents/{parent}/disable` | admin, manager | Disable parent account (`disabled_at` set) |
| POST | `/api/v1/references/parents/{parent}/enable` | admin, manager | Re-enable disabled parent account |
| DELETE | `/api/v1/references/parents/{parent}` | admin, manager | Soft-delete parent account (`deleted_at` set) |
| POST | `/api/v1/references/parents/{parent}/restore` | admin, manager | Restore soft-deleted parent account |

Feedback reply and read endpoints are in the POS app under `ability:staff` (defined alongside Feedback in Spec 07 implementation).

---

## Requirements

- [x] `parents` table: `password` nullable (null until activated); `email_verified_at` null = not activated
- [x] `parent_student` pivot table: `parent_id`, `student_id`, `linked_at`, `linked_by`, `wallet_alert_threshold`; UNIQUE KEY `(parent_id, student_id)`
- [x] `feedbacks` table with `category` enum (TitleCase) and `admin_reply` field
- [x] Parent Sanctum token endpoint: `POST /api/v1/portal/auth/login` issues token with `parent` ability
- [x] Login blocked with 401 `account_not_activated` error if `email_verified_at` is null
- [x] Portal login page shows "not activated" message and directs parent to contact the canteen
- [x] All portal API routes protected by `auth:sanctum` + `ability:parent`
- [x] Parent token stored in `~/sunbites-portal` Zustand auth store via `sessionStorage` persist middleware — survives page refresh; required for `EchoProvider` to reinitialize on reload
- [x] `POST /api/v1/portal/auth/forgot-password` always returns generic "If an account exists, we'll send an email"; server sends activation email if not activated, reset email if activated
- [x] `POST /api/v1/portal/auth/reset-password` — sets password, sets `email_verified_at = now()`, works for both initial activation and password reset
- [x] Activation link opens `portal.sunbites.com.ph/activate?token=...&email=...`; on success redirects to login with toast
- [x] Rate limiting: login, forgot-password — max 5 attempts per 5 minutes per IP
- [x] Parent profile edit page — photo upload: MIME whitelist (jpeg/png/webp), max 2MB; stored on public disk; API returns `profile_photo_url` (full public URL)
- [x] `POST /api/v1/portal/profile/change-password` — dedicated endpoint; requires `current_password`, `password`, `password_confirmation`; 422 if current password is wrong
- [x] No self-registration — no `/register` route on the portal
- [x] No manual student link request flow — no `parent_student_requests` table
- [x] Parent dashboard: outstanding credit alert card (when `credit_balance > 0`), wallet card, spending summary, recent purchases
- [x] Student selector (tabs or dropdown) when parent has multiple linked students
- [x] "No students linked" informational card when `parent_student` is empty for authenticated parent
- [x] IDOR protection: `/api/v1/portal/students/{id}/activity` and `/api/v1/portal/students/{id}/wallet` verify `parent_student` link ownership via `ParentStudentPolicy::view()` before returning any data (403 if not linked)
- [x] Wallet alert threshold update: `ParentStudentPolicy` ownership check before updating `parent_student` record
- [x] `WalletAlertJob` queued when wallet withdrawal drops balance below `wallet_alert_threshold`
- [x] Spending breakdown with date range filter (day/week/month view)
- [x] Wallet transaction history table
- [x] Meal planner read-only page at `portal.sunbites.com.ph/meal-plan` — reads from `weekly_meal_plans`; branch-scoped via linked student; branch selector if multiple branches
- [x] Month tabs show all 10 school months (Jun–Mar) as pill buttons, horizontally scrollable on mobile
- [x] When selected week is published (`visible_to_parents = true`): render full meal grid with all 5 columns (Ulam, Vegetables, Fruit, Soup, Snacks)
- [x] When selected week is unpublished (`visible_to_parents = false`): show "Meal plan for this week is not yet available." card instead of the table
- [x] "Meal Plan" link in portal top nav
- [x] Feedback form: rating, category, message (`strip_tags()` before storage); student selector
- [x] `admin_reply` sanitized with `strip_tags()` before storage; rendered as plain text
- [x] Feedback list and reply functionality in POS app (References > Feedback)
- [x] Feedback reply email sent to parent
- [x] Unread feedback count badge in POS app sidebar
- [x] `student_contacts.email` is informational only — not used for portal auth or IDOR checks; `parent_student` pivot is the canonical link
- [x] Portal layout (`PortalLayout`) in `~/sunbites-portal` — top nav, no sidebar, mobile-responsive
- [x] Auth pages in `app/(auth)/` route group in `~/sunbites-portal`
- [x] Dashboard and portal pages in `app/(portal)/` route group
- [x] **Parent Management Page** at `pos.sunbites.com.ph/references/parents`: paginated table (Name, Email, Activation Status, Linked Students count, Registered Date, Last Login); filter by activation status and branch; search by name or email
- [x] Branch filter on parent list: `GET /api/v1/references/parents?branch_id=` — filters to parents who have at least one linked student in the given branch; when `X-Branch-Id` header is present, default to filtering by the active branch
- [x] Parent detail drawer/page: profile info, linked students list (with links to student detail), `Resend Activation Email` (when not activated, rate-limited) and `Send Password Reset Email` (when activated) actions
- [x] `GET /api/v1/references/parents` — staff-only; paginated, filterable; returns parent list with linked student count
- [x] `GET /api/v1/references/parents/{parent}` — staff-only; returns full parent detail with linked students array; includes soft-deleted parents
- [x] `parents` table `disabled_at` column (timestamp, nullable) — set when account is disabled
- [x] `parents` table soft-delete support (`deleted_at`) via Laravel `SoftDeletes` trait
- [x] `POST /api/v1/references/parents/{parent}/resend-activation` — resend activation email; admin/manager/supervisor; rate-limited max 3 per 24 hours; 422 if already activated
- [x] `POST /api/v1/references/parents/{parent}/disable` — admin/manager only; sets `disabled_at`; disabled parents cannot log in
- [x] `POST /api/v1/references/parents/{parent}/enable` — admin/manager only; clears `disabled_at`
- [x] `DELETE /api/v1/references/parents/{parent}` — admin/manager only; soft-deletes parent (`deleted_at` set)
- [x] `POST /api/v1/references/parents/{parent}/restore` — admin/manager only; restores soft-deleted parent (clears `deleted_at`)
- [x] Login endpoint rejects disabled parents (`disabled_at` is not null) with 401 error
- [x] Log `parent.activated` (properties: parent_email, activated_at)
- [x] Log `parent.password_reset` (properties: parent_email)
