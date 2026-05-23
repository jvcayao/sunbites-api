# Tasks 07 — Parent Portal

## 1. Database
- [ ] Migration: `parents` table — `first_name`, `last_name`, `email` (unique), `password`, `phone` (nullable), `address` (nullable), `profile_photo_path` (nullable), `email_verified_at` (nullable), `remember_token` (nullable), timestamps
- [ ] Migration: `parent_student_requests` table — `parent_id` (FK), `student_id` (FK), `branch_id` (FK), `status` (enum: Pending/Approved/Rejected — TitleCase), `rejection_reason` (nullable), `requested_at`, `reviewed_at` (nullable), `reviewed_by` (nullable FK → users)
- [ ] Migration: `parent_student` pivot — `parent_id` (FK), `student_id` (FK, unique), `linked_at`, `linked_by` (FK → users), `wallet_alert_threshold` (decimal, default 0); UNIQUE KEY on `student_id`
- [ ] Migration: `feedbacks` table — `parent_id` (FK), `student_id` (nullable FK), `branch_id` (FK), `rating` (int 1–5), `category` (enum: FoodQuality/Service/PortionSize/Cleanliness/General — TitleCase), `message` (text, nullable), `is_read` (bool, default false), `admin_reply` (text, nullable), `replied_at` (nullable), `created_at`

## 2. Models
- [ ] `ParentUser` model (use `ParentUser` to avoid PHP reserved word conflict for `Parent`)
  - [ ] Implements `MustVerifyEmail`
  - [ ] `students()` belongsToMany via `parent_student` pivot
  - [ ] `linkRequests()` hasMany `ParentStudentRequest`
- [ ] `ParentStudentRequest` model
- [ ] `Feedback` model

## 3. Authentication

### 3.1 Backend
- [ ] `Portal\AuthController` — custom Sanctum token controller for parent auth (not Fortify)
  - [ ] `login()` — validates email/password against `parents` table; returns Sanctum token with `parent` ability; checks email verified before issuing token
  - [ ] `register()` — creates `ParentUser`; sends verification email
  - [ ] `logout()` — revokes current token
  - [ ] `forgotPassword()` — always returns same generic response regardless of email existence
  - [ ] `resetPassword()` — validates reset token and updates password
- [ ] Rate limiting: login, register, forgot-password — max 5 attempts per 5 minutes per IP (Laravel `RateLimiter`)
- [ ] All portal API routes under `auth:sanctum` + `ability:parent` middleware — no separate `parent` guard needed
- [ ] Routes (public):
  - `POST /api/v1/portal/auth/login`
  - `POST /api/v1/portal/auth/register`
  - `POST /api/v1/portal/auth/forgot-password`
  - `POST /api/v1/portal/auth/reset-password`
- [ ] Routes (parent auth):
  - `POST /api/v1/portal/auth/logout`

### 3.2 Frontend (`~/sunbites-portal`)
- [ ] Zustand auth store (`lib/store/auth.ts`): `token`, `parent` user object; token in memory only
- [ ] API client (`lib/api/client.ts`): attaches `Authorization: Bearer {token}` on every request; logs out on 401
- [ ] Login page at `app/(auth)/login/page.tsx`
- [ ] Register page at `app/(auth)/register/page.tsx`
- [ ] Email verification notice page at `app/(auth)/verify-email/page.tsx`
- [ ] Forgot password page at `app/(auth)/forgot-password/page.tsx`
- [ ] Reset password page at `app/(auth)/reset-password/page.tsx`
- [ ] All auth pages use `AuthLayout` (centered card, no top nav)

## 4. Parent Profile

### 4.1 Backend
- [ ] `Portal\ProfileController`
  - [ ] `show()` — returns parent profile
  - [ ] `update()` — updates first name, last name, phone, address, password (current password required)
  - [ ] `uploadPhoto()` — MIME whitelist (jpeg/png/webp), max 2MB, server-side validation
- [ ] Routes under `auth:sanctum` + `ability:parent`:
  - `GET /api/v1/portal/profile`
  - `PUT /api/v1/portal/profile`
  - `POST /api/v1/portal/profile/photo`

### 4.2 Frontend
- [ ] Profile page at `app/(portal)/profile/page.tsx`: first name, last name, phone, address, photo upload, password change; `useMutation` for save

## 5. Student Linking

### 5.1 Backend
- [ ] `Portal\LinkStudentController`
  - [ ] `search()` — authenticated parent only; rate limited max 10/minute per parent account; returns minimal data: partial first name (e.g. "Juan D."), grade level, branch name — never QR code, full last name, birthday, student number, or photo
  - [ ] `store()` — creates `parent_student_requests` (status=Pending); rejects if student already has a linked parent (unique constraint on `student_id`)
  - [ ] `index()` — returns parent's own link requests with status
- [ ] `Kitchen\LinkRequestController` (POS app staff routes)
  - [ ] `index()` — branch-scoped list of pending/reviewed requests for kitchen staff
  - [ ] `approve()` — Admin/Manager/Supervisor; creates `parent_student` record; sends approval email; logs `parents.link_approved`
  - [ ] `reject()` — requires reason; sends rejection email; logs `parents.link_rejected`
- [ ] `student_contacts.email` is informational only — never auto-matched to `parents.email` (enforced by never querying parents by student_contacts.email)
- [ ] Portal routes under `auth:sanctum` + `ability:parent`:
  - `POST /api/v1/portal/link-requests/search`
  - `POST /api/v1/portal/link-requests`
  - `GET /api/v1/portal/link-requests`
- [ ] Kitchen staff routes under `auth:sanctum` + `ability:staff` + `role:admin,manager,supervisor`:
  - `GET /api/v1/link-requests`
  - `POST /api/v1/link-requests/{request}/approve`
  - `POST /api/v1/link-requests/{request}/reject`

### 5.2 Frontend
- [ ] Link a Student page at `app/(portal)/link-student/page.tsx`: branch radio cards, student search input with dropdown (minimal data only), relationship selector; `useMutation` for submit
- [ ] Pending requests section on dashboard with status badges (Approved/Pending/Rejected)
- [ ] POS app: Link Requests review page at `app/(kitchen)/references/link-requests/page.tsx`
  - [ ] Table: parent name, email, requested student, branch, status, approve/reject actions via `useMutation`
  - [ ] Reject: reason input dialog
  - [ ] Unread badge count on sidebar

## 6. Parent Dashboard

### 6.1 Backend
- [ ] `Portal\DashboardController::index()` — returns linked students, each with: wallet balance, credit_balance, today/week/month spend totals, last 5 wallet transactions, today's orders
- [ ] Route: `GET /api/v1/portal/dashboard`

### 6.2 Frontend
- [ ] Dashboard page at `app/(portal)/dashboard/page.tsx`
  - [ ] Student selector dropdown/tabs when multiple students linked via `useQuery`
  - [ ] No students state: onboarding card with "Link a Student" button
  - [ ] Outstanding credit alert card (`bg-red-50 border-red-300`) when `credit_balance > 0` — displayed first
  - [ ] Wallet balance card: large balance, last top-up, last 5 transactions, "Set Alert Threshold" link
  - [ ] Spending summary cards: Today / This Week / This Month
  - [ ] Today's purchases list

## 7. Child Activity & Wallet

### 7.1 Backend
- [ ] `ParentStudentPolicy::view()` — verifies `parent_student` link ownership; all student-scoped portal endpoints call this before returning data (403 if not linked)
- [ ] `Portal\ActivityController::index()` — IDOR-protected; orders for student in date range; returns total spent, visit count, most purchased item, avg/day
- [ ] `Portal\WalletController::index()` — IDOR-protected; wallet transactions for student
- [ ] `Portal\WalletController::setAlert()` — IDOR-protected via `ParentStudentPolicy`; updates `wallet_alert_threshold` on `parent_student` pivot
- [ ] Routes under `auth:sanctum` + `ability:parent`:
  - `GET /api/v1/portal/students/{id}/activity`
  - `GET /api/v1/portal/students/{id}/wallet`
  - `PUT /api/v1/portal/students/{id}/wallet-alert`

### 7.2 Frontend
- [ ] Activity page at `app/(portal)/students/[id]/activity/page.tsx`: date filter tabs (Today/Week/Month/Custom), summary cards, activity table via `useQuery`
- [ ] Wallet page at `app/(portal)/students/[id]/wallet/page.tsx`: balance summary cards, alert threshold input + save via `useMutation`, transaction table with Credit/Debit badges

## 8. Wallet Alert
- [ ] `WalletAlertJob` — queued when POS `withdraw()` reduces balance below parent's `wallet_alert_threshold`
- [ ] Dispatched from `CheckoutController` after successful wallet payment
- [ ] Email content: student name, current balance, threshold amount, link to portal

## 9. Meal Planner (Read-Only)

### 9.1 Backend
- [ ] `Portal\MealPlannerController::show()` — read-only; branch-scoped to parent's linked student branch; returns weekly meal plan data
- [ ] Route under `auth:sanctum` + `ability:parent`:
  - `GET /api/v1/portal/meal-planner`

### 9.2 Frontend
- [ ] Meal planner page at `app/(portal)/meal-plan/page.tsx`
  - [ ] Same month/week tab structure as kitchen app (Spec 04) via `useQuery`
  - [ ] All cells rendered as plain text (no inputs); empty cells shown as "—"
  - [ ] Branch-scoped via linked student; branch selector shown if multiple branches
  - [ ] "Meal Plan" link in `PortalLayout` top nav

## 10. Feedback

### 10.1 Backend
- [ ] `Portal\FeedbackController`
  - [ ] `index()` — parent's own feedback list
  - [ ] `store()` — creates feedback; `strip_tags()` on `message` before storage
- [ ] `Kitchen\FeedbackController` (POS app)
  - [ ] `index()` — branch-scoped feedback list with filters; unread count
  - [ ] `markRead()` — marks feedback as read
  - [ ] `reply()` — saves `admin_reply` (`strip_tags()` before storage); sends reply email to parent
- [ ] Portal routes: `GET|POST /api/v1/portal/feedback`
- [ ] Kitchen routes: `GET /api/v1/feedback`, `POST /api/v1/feedback/{id}/read`, `POST /api/v1/feedback/{id}/reply`

### 10.2 Frontend
- [ ] Feedback page at `app/(portal)/feedback/page.tsx`: star rating (1–5), category select, student selector (optional), message textarea; `useMutation` for submit
- [ ] POS app: Feedback review page at `app/(kitchen)/references/feedback/page.tsx`
  - [ ] List with filters (rating, category, date, read/unread) via `useQuery`
  - [ ] Mark as read; reply dialog with textarea; `useMutation` for reply
  - [ ] Unread badge count on sidebar

## 11. Email Notifications
- [ ] Link approval email: "Your request to link {student name} has been approved."
- [ ] Link rejection email: "Your request to link {student name} was not approved. Reason: {reason}."
- [ ] Wallet alert email via `WalletAlertJob` (queued)
- [ ] Feedback reply email (queued)

## 12. Portal Layout (`~/sunbites-portal`)
- [ ] `PortalLayout` component: top nav (Dashboard, Meal Plan, Feedback links), user dropdown, no sidebar, mobile-responsive
- [ ] `AuthLayout` component: centered card, Sunbites logo, no nav
- [ ] Route groups: `app/(auth)/` for public auth pages, `app/(portal)/` for authenticated portal pages

## 13. Tests
- [ ] `PortalAuthTest` — login returns token with `parent` ability; register creates parent; email verification required; rate limiting (5/5min); forgot-password always returns generic response; logout revokes token
- [ ] `StudentLinkTest` — search returns minimal data only (no QR/full name/birthday/student_number); one-parent-per-student enforcement; request/approval/rejection flow
- [ ] `PortalDashboardTest` — no students state returns onboarding; with students returns wallet + spending data; credit alert included when credit_balance > 0
- [ ] `PortalActivityTest` — IDOR protection returns 403 for unlinked student; date filters work; data is branch-scoped
- [ ] `WalletAlertTest` — `WalletAlertJob` dispatched when balance drops below threshold; not dispatched when threshold is 0; alert threshold update requires ownership
- [ ] `FeedbackTest` — submit stores sanitized message; kitchen staff can reply; admin_reply is sanitized; unread count reflects new submissions
