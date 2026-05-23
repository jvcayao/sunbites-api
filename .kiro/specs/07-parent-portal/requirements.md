# Spec 07 — Parent Portal

## Overview

The Parent Portal lives at `portal.sunbites.com.ph` (production) and `localhost:3001` (local). It is a separate Next.js application (`~/sunbites-portal`) for parents to monitor their child's canteen activity, wallet balance, and communicate with the canteen. Parents authenticate separately from kitchen staff, using a dedicated Sanctum token endpoint that issues tokens with the `parent` ability.

---

## Authentication

### Token-Based Auth (Sanctum)
- Parent login: `POST /api/v1/portal/auth/login` — returns a Sanctum token with `parent` ability
- Parent logout: `POST /api/v1/portal/auth/logout` — revokes token
- Parent registration: `POST /api/v1/portal/auth/register` — creates parent account; requires email verification before portal access
- Forgot password: `POST /api/v1/portal/auth/forgot-password` — always returns the same generic response regardless of email existence (prevents account enumeration)
- Reset password: `POST /api/v1/portal/auth/reset-password`
- All portal API routes are protected by `auth:sanctum` + `ability:parent` middleware

### Token Storage
- Parent token stored in Zustand memory only — never `localStorage` or `sessionStorage`
- `~/sunbites-portal` Zustand auth store holds the token; the API client reads it and attaches `Authorization: Bearer {token}` to every request

### Rate Limiting
- Login, register, forgot-password endpoints: max 5 attempts per 5 minutes per IP

### Parents Table
```
parents
  id
  first_name         (string)
  last_name          (string)
  email              (string, unique)
  password           (string)
  phone              (string, nullable)
  address            (string, nullable)
  profile_photo_path (string, nullable)
  email_verified_at  (timestamp, nullable)
  remember_token     (string, nullable)
  created_at, updated_at
```

### Registration
- Self-registration at `portal.sunbites.com.ph/register`
- Fields: First name, Last name, Email, Phone (optional), Password, Confirm Password
- Email verification required before accessing the portal (link emailed via Laravel `MustVerifyEmail`)
- After verification: redirect to dashboard (wallet alert preference prompt on first login if no students linked)

---

## Parent Profile

Accessible at: `portal.sunbites.com.ph/profile`

### Editable Fields
- First name, Last name
- Phone number
- Address
- Profile photo upload — MIME whitelist: `image/jpeg`, `image/png`, `image/webp` only; max 2MB; server-side validation
- Password change (current password required)
- Wallet alert threshold (₱ amount — triggers email when any linked student wallet drops below)

---

## Student Linking System

### How It Works
Parents request to link to a student by providing the student's information. Kitchen staff approve the request.

### parent_student_requests Table
```
parent_student_requests
  id
  parent_id              (FK → parents)
  student_id             (FK → students)
  branch_id              (FK → branches)
  status                 (enum: Pending, Approved, Rejected — TitleCase)
  rejection_reason       (string, nullable)
  requested_at           (timestamp)
  reviewed_at            (timestamp, nullable)
  reviewed_by            (FK → users, nullable)
```

### parent_student (approved links)
```
parent_student
  id
  parent_id                (FK → parents)
  student_id               (FK → students, unique — only 1 parent per student)
  linked_at                (timestamp)
  linked_by                (FK → users)
  wallet_alert_threshold   (decimal, default 0)
```

### Relationship Between student_contacts and parents
`student_contacts.email` (defined in Spec 05) is informational contact data entered at enrollment — it is **not linked** to the `parents` table and is never used to auto-match or auto-link a parent account. The `parent_student` pivot is the canonical, staff-approved relationship.

### One-to-Many: 1 Parent → Many Students
- A parent can link to multiple students
- A student can only be linked to ONE parent (enforced via unique constraint on `student_id`)
- If a student is already linked, a new request is rejected: "Student already has a linked parent account"

### Parent Request Flow (Portal Side)
1. Parent clicks "Link a Student" on dashboard
2. Form: Branch selector + Student name or student number + relationship
3. System finds the student in the selected branch — **rate limited: max 10 search requests per minute per parent account**
4. Student search results return **minimal data only**: partial first name (e.g. "Juan D."), grade level, branch name — **never** return QR code, full last name, birthday, student number, or photo in search results
5. Creates `parent_student_requests` record with `status = Pending`
6. Toast: "Your request has been submitted. You will be notified once approved."
7. Parent sees pending requests in "Linked Students" section with status badge

### Kitchen Staff Approval Flow
- Pending requests shown as badge notification on sidebar in POS app
- Review page lists: parent name, email, requested student, branch, relationship, date submitted
- Actions: Approve (creates `parent_student` record) or Reject (requires reason)
- On approval: email notification sent to parent
- On rejection: email notification sent to parent with reason

---

## Parent Dashboard

Accessible at: `portal.sunbites.com.ph/dashboard`

### When No Students Linked
- Onboarding card: "You haven't linked any students yet" with "Request Student Link" button

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
All student-scoped routes (`/api/v1/portal/students/{id}/activity`, `/api/v1/portal/students/{id}/wallet`) must verify **before returning any data** that a confirmed `parent_student` link exists between the currently authenticated parent and the `{id}` in the URL. If no approved link exists, return 403 — never return data based on URL parameter alone.

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
- Month tabs: pill buttons for each school month (Jun–Mar)
- Week tabs: Week 1 / Week 2 / Week 3 / Week 4
- Meal grid: rows = Monday–Friday, columns = Ulam / Vegetables / Fruit / Soup
- All cells rendered as plain text (no inputs)
- Empty cells shown as "—"

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

## API Routes

All portal routes under `auth:sanctum` + `ability:parent` middleware unless noted.

| Method | Route | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/portal/auth/login` | public | Parent login — returns token with `parent` ability |
| POST | `/api/v1/portal/auth/register` | public | Parent registration |
| POST | `/api/v1/portal/auth/logout` | parent | Revoke token |
| POST | `/api/v1/portal/auth/forgot-password` | public | Send reset link (generic response) |
| POST | `/api/v1/portal/auth/reset-password` | public | Reset password |
| GET | `/api/v1/portal/profile` | parent | Get parent profile |
| PUT | `/api/v1/portal/profile` | parent | Update parent profile |
| GET | `/api/v1/portal/dashboard` | parent | Dashboard data for linked students |
| GET | `/api/v1/portal/students` | parent | Linked students list |
| POST | `/api/v1/portal/link-requests` | parent | Submit student link request |
| GET | `/api/v1/portal/link-requests` | parent | View own link requests |
| GET | `/api/v1/portal/students/{id}/activity` | parent | Spending activity (IDOR-protected) |
| GET | `/api/v1/portal/students/{id}/wallet` | parent | Wallet history (IDOR-protected) |
| PUT | `/api/v1/portal/students/{id}/wallet-alert` | parent | Set alert threshold (IDOR-protected) |
| GET | `/api/v1/portal/meal-planner` | parent | Read-only meal planner |
| GET | `/api/v1/portal/feedback` | parent | Own feedback list |
| POST | `/api/v1/portal/feedback` | parent | Submit feedback |

Kitchen staff routes for link request review and feedback reply are in the POS app under `ability:staff`.

---

## Requirements

- [ ] `parents` table with all fields
- [ ] `parent_student_requests` table with `status` enum (Pending/Approved/Rejected — TitleCase)
- [ ] `parent_student` pivot table with `wallet_alert_threshold`
- [ ] `feedbacks` table with `category` enum (TitleCase) and `admin_reply` field
- [ ] Parent Sanctum token endpoint: `POST /api/v1/portal/auth/login` issues token with `parent` ability
- [ ] All portal API routes protected by `auth:sanctum` + `ability:parent`
- [ ] Parent token stored in `~/sunbites-portal` Zustand auth store (memory only — not localStorage)
- [ ] Registration with email verification (`MustVerifyEmail`)
- [ ] Login/logout/forgot-password/reset-password flows
- [ ] Forgot password always returns same generic response regardless of email existence
- [ ] Rate limiting: login, register, forgot-password — max 5 attempts per 5 minutes per IP
- [ ] Parent profile edit page — photo upload: MIME whitelist (jpeg/png/webp), max 2MB
- [ ] Student link request form: branch + student lookup (minimal data only); rate limited max 10/min per parent
- [ ] Student search results return minimal data only: partial first name, grade level, branch — no QR code, full last name, birthday, student number, or photo exposed
- [ ] Pending request status visible on parent dashboard
- [ ] Kitchen staff link request review page in POS app (References > Link Requests): approve/reject
- [ ] Unique constraint: one parent per student (`student_id` unique on `parent_student`)
- [ ] Email notifications: approval, rejection, wallet alert (`WalletAlertJob`)
- [ ] `WalletAlertJob` queued when wallet withdrawal drops balance below `wallet_alert_threshold`
- [ ] Parent dashboard: outstanding credit alert card (when `credit_balance > 0`), wallet card, spending summary, recent purchases
- [ ] IDOR protection: `/api/v1/portal/students/{id}/activity` and `/api/v1/portal/students/{id}/wallet` verify `parent_student` link ownership via `ParentStudentPolicy::view()` before returning any data (403 if not linked)
- [ ] Wallet alert threshold update: `ParentStudentPolicy` ownership check before updating `parent_student` record
- [ ] Spending breakdown with date range filter (day/week/month view)
- [ ] Wallet transaction history table
- [ ] Meal planner read-only page at `portal.sunbites.com.ph/meal-plan` — reads from `weekly_meal_plans`; branch-scoped via linked student; branch selector if multiple branches
- [ ] "Meal Plan" link in portal top nav
- [ ] Feedback form: rating, category, message (`strip_tags()` before storage); student selector
- [ ] `admin_reply` sanitized with `strip_tags()` before storage; rendered as plain text
- [ ] Feedback list and reply functionality in POS app (References > Feedback)
- [ ] Feedback reply email sent to parent
- [ ] Unread feedback count badge in POS app sidebar
- [ ] `student_contacts.email` is informational only — no auto-matching to `parents.email`; enforced by never querying parents by student_contacts.email
- [ ] Portal layout (`PortalLayout`) in `~/sunbites-portal` — top nav, no sidebar, mobile-responsive
- [ ] Auth pages in `app/(auth)/` route group in `~/sunbites-portal`
- [ ] Dashboard and portal pages in `app/(portal)/` route group
