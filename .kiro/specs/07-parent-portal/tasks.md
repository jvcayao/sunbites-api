# Tasks 07 — Parent Portal

> **Design change from original:** Parent accounts are provisioned automatically at enrollment (no self-registration, no link-request flow). The `parent_student_requests` table and manual approval workflow are removed entirely. Linking is driven by enrollment guardian data.

## 1. Database
- [x] Migration: `parents` table — `first_name`, `last_name`, `email` (unique), `password` (nullable — null until activated), `phone` (nullable), `address` (nullable), `profile_photo_path` (nullable), `email_verified_at` (nullable — null = not yet activated), `remember_token` (nullable), timestamps
- [x] Migration: `parent_student` pivot — `parent_id` (FK → parents), `student_id` (FK → students), `linked_at` (timestamp), `linked_by` (FK → users), `wallet_alert_threshold` (decimal, default 0); UNIQUE KEY on `(parent_id, student_id)`
- [x] Migration: `feedbacks` table — `parent_id` (FK), `student_id` (nullable FK), `branch_id` (FK), `rating` (int 1–5), `category` (enum: FoodQuality/Service/PortionSize/Cleanliness/General — TitleCase), `message` (text, nullable), `is_read` (bool, default false), `admin_reply` (text, nullable), `replied_at` (nullable), `created_at`

## 2. Models
- [x] `ParentUser` model (use `ParentUser` to avoid PHP reserved word conflict for `Parent`)
  - [x] `HasApiTokens` trait (Sanctum)
  - [x] Custom `MustVerifyEmail`-style activation check — `isActivated()` method returns `email_verified_at !== null`
  - [x] `students()` belongsToMany via `parent_student` pivot (with `wallet_alert_threshold`, `linked_at`, `linked_by`)
  - [x] `feedbacks()` hasMany `Feedback`
- [x] `Feedback` model — `$fillable`, `category` cast to `FeedbackCategory` enum, `strip_tags()` on `message` and `admin_reply` via controller

## 3. Enums
- [x] `FeedbackCategory` enum — `FoodQuality`, `Service`, `PortionSize`, `Cleanliness`, `General` (TitleCase, string-backed)

## 4. Services
- [x] `ParentProvisioningService::provision(string $email, string $name, int $studentId, int $enrolledBy): void`
  - [x] Find or create `parents` record by email (find-or-create pattern: `firstOrCreate(['email' => $email])`)
  - [x] If newly created: set `first_name`, `last_name`, `password = null`, `email_verified_at = null`; dispatch `ParentWelcomeMail`
  - [x] If already exists and not yet activated: do NOT resend welcome mail (staff can resend via Contacts tab)
  - [x] Create `parent_student` pivot row if it does not already exist (idempotent via `firstOrCreate` on `(parent_id, student_id)`)
  - [x] Set `linked_at = now()`, `linked_by = $enrolledBy` on pivot creation only
  - [x] Service is called from: `EnrollmentController::store()` and `StudentContactController::store()/update()`

## 5. Mail
- [x] `ParentWelcomeMail` — activation link email to new parent accounts
  - [x] Uses Laravel `PasswordBroker` to generate reset/activation token
  - [x] Activation URL: `{PORTAL_URL}/activate?token={token}&email={email}` (from env `PORTAL_APP_URL`)
  - [x] Token expires: 60 minutes (Laravel default `passwords.expires` config)
  - [x] Subject: "Welcome to Sunbites! Please activate your account"
- [x] `WalletAlertMail` — sent by `WalletAlertJob` when wallet drops below threshold
  - [x] Content: student name, current balance, threshold, link to portal
- [x] `FeedbackReplyMail` — queued, sent after kitchen staff reply

## 6. Jobs
- [x] `WalletAlertJob` — queued job
  - [x] Dispatched from `CheckoutController` after successful wallet withdrawal when `balance < wallet_alert_threshold`
  - [x] Query `parent_student` pivot for this student where `wallet_alert_threshold > 0`
  - [x] Only dispatch if current balance < threshold
  - [x] Send `WalletAlertMail` to linked parent

## 7. Policies
- [x] `ParentStudentPolicy::view(ParentUser $parent, Student $student): bool`
  - [x] Returns `true` only if `parent_student` pivot exists for `(parent->id, student->id)`
  - [x] Called on all portal student-scoped endpoints before returning data (403 if fails)

## 8. Portal Auth Controller

### 8.1 Backend
- [x] `Portal\AuthController::login()` — validate email/password against `parents` table; if account not activated (`email_verified_at` is null), return 401 with `{"error": "account_not_activated"}`; otherwise return Sanctum token with `parent` ability
- [x] `Portal\AuthController::forgotPassword()` — always return 200 with generic message; server-side: resends `ParentWelcomeMail` with fresh broker token
- [x] `Portal\AuthController::resetPassword()` — validate token + email + password + password_confirmation; if valid: set password, set `email_verified_at = now()`, invalidate token; return 200
- [x] `Portal\AuthController::logout()` — revokes current access token
- [x] Rate limiting: `portal-login` and `portal-forgot-password` limiters in `AppServiceProvider`

### 8.2 Routes (public + portal-api.php)
- [x] `POST /api/v1/portal/auth/login` — public, rate limited
- [x] `POST /api/v1/portal/auth/password/email` — public, rate limited
- [x] `POST /api/v1/portal/auth/password/reset` — public
- [x] `POST /api/v1/portal/auth/logout` — `auth:parents`, `ability:parent`

### 8.3 Frontend (`~/sunbites-portal`)
- [ ] Zustand auth store token storage — **DECISION NEEDED**: original spec said memory-only, but `lib/store/auth.ts` still uses `persist` + `sessionStorage`. With Reverb (Spec 10), memory-only causes logout on page refresh. Options: (a) keep sessionStorage, (b) memory-only + reconnect Echo on re-auth. Resolve before Spec 10 Task 7.
- [x] `lib/api/auth.ts` — add `forgotPassword(email)` and `resetPassword(token, email, password, passwordConfirmation)` methods — done: implemented in `lib/api/portal.ts` as `portalAuthApi.forgotPassword/resetPassword`
- [x] Login page — handle `account_not_activated` error: show message "Your account has not been activated yet. Check your email or contact the canteen."
- [x] Forgot password page at `app/(auth)/forgot-password/page.tsx` — email input, generic success message on submit
- [x] Activate/reset password page at `app/(auth)/activate/page.tsx` — reads `?token=&email=` from URL, password + confirm password fields, calls `resetPassword` on submit, redirects to login on success
- [x] Remove register page (`app/(auth)/register/` if it exists — replace with redirect to login or "Contact the canteen to get access") — no register page exists

## 9. Portal Profile

### 9.1 Backend
- [x] `Portal\ProfileController::show()` — returns auth parent with all profile fields
- [x] `Portal\ProfileController::show()` — returns `profile_photo_url` via `Storage::url()`; `profile_photo_path` removed from response
- [x] `Portal\ProfileController::update()` — updates name, phone, address only; password change removed from this endpoint
- [x] `Portal\ProfileController::changePassword()` — dedicated method; 422 on wrong current password; uses `Password::defaults()`
- [x] `Portal\ProfileController::uploadPhoto()` — public disk; returns `profile_photo_url`; deletes old photo before upload
- [x] Routes: `GET /api/v1/portal/profile`, `PATCH /api/v1/portal/profile`, `POST /api/v1/portal/profile/photo`, `POST /api/v1/portal/profile/change-password`

### 9.2 Frontend
- [x] `lib/api/profile.ts` — `profileApi.show()`, `profileApi.update(payload)`, `profileApi.uploadPhoto(file)` — done: implemented as `profileApi` in `lib/api/portal.ts`
- [x] Profile page at `app/(portal)/profile/page.tsx`: editable fields, photo upload with preview, password change section
- [x] `lib/api/portal.ts` — `profileApi.changePassword` already calls `POST /portal/profile/change-password`; route registered on backend
- [x] `lib/api/portal.ts` — `profileApi.uploadPhoto` return type updated to `{ profile_photo_url: string }`
- [x] `app/(portal)/profile/page.tsx` — `ProfilePhotoSection`: uses `profile.profile_photo_url` as `<Image src>`; `next.config.ts` updated with `remotePatterns` for S3/MinIO
- [x] `types/auth.ts` — `profile_photo_path` renamed to `profile_photo_url` in `AuthParent` interface

## 10. Linked Students

### 10.1 Backend
- [x] `Portal\StudentController::index()` — returns all students linked to parent with wallet balance, branch, pivot data
- [x] Route: `GET /api/v1/portal/students`

### 10.2 Frontend
- [x] `types/parent.ts` — `LinkedStudent` interface — done: implemented as `StudentSummary` in `types/portal.ts`
- [x] `lib/api/portal-students.ts` — `portalStudentApi.list()` — done: implemented as `studentsApi.list()` in `lib/api/portal.ts`

## 11. Parent Dashboard

### 11.1 Backend
- [x] `Portal\DashboardController::index()` — per-student spend totals, today's orders, recent wallet transactions
- [x] Route: `GET /api/v1/portal/dashboard`

### 11.2 Frontend
- [x] Dashboard page at `app/(portal)/dashboard/page.tsx` — student tabs, wallet balance card, spending summary, today's purchases

## 12. Child Activity

### 12.1 Backend
- [x] `Portal\ActivityController::index(Student $student)` — IDOR-protected, date range filter, paginated orders with items
- [x] Route: `GET /api/v1/portal/students/{student}/activity`

### 12.2 Frontend
- [x] Activity page at `app/(portal)/students/[id]/activity/page.tsx` — done: implemented as "Activity" tab inside `app/(portal)/students/[id]/page.tsx`

## 13. Wallet (Portal)

### 13.1 Backend
- [x] `Portal\WalletController::index(Student $student)` — IDOR-protected, wallet balance + transaction history
- [x] `Portal\WalletController::setAlert(Student $student)` — updates `wallet_alert_threshold` on pivot
- [x] Routes: `GET /api/v1/portal/students/{student}/wallet`, `PATCH /api/v1/portal/students/{student}/wallet/alert`

### 13.2 Frontend
- [x] Wallet page at `app/(portal)/students/[id]/wallet/page.tsx` — done: implemented as "Wallet" tab inside `app/(portal)/students/[id]/page.tsx`
  - [x] Current wallet balance card
  - [x] Transaction history list (type, amount, date) via `useQuery`
  - [x] "Alert Setting" section: number input "Alert me when balance drops below ₱___"; pre-filled with current `wallet_alert_threshold` from pivot; save via `useMutation` → `PATCH /api/v1/portal/students/{student}/wallet/alert`; set to 0 to disable

## 14. Meal Planner (Portal)

### 14.1 Backend
- [x] `Portal\MealPlannerController::show()` — read-only; derives branch from linked student; rejects request if parent has no linked students
- [x] Update `Portal\MealPlannerController::show()` to use `meal_planner_week_visibility`:
  - If `visible_to_parents = false` for the requested month+week: return `{ visible_to_parents: false, days: [] }`
  - If `visible_to_parents = true` (or no record = default true): return `{ visible_to_parents: true, days: [...] }` with all 5 category fields including snacks
- [x] Route: `GET /api/v1/portal/meal-planner`

### 14.2 Frontend
- [x] Meal planner page at `app/(portal)/meal-plan/page.tsx`
  - [x] Month tab row: all 10 school months rendered as pill buttons in order (Jun → Mar); `overflow-x-auto flex gap-2` container; `whitespace-nowrap` pills; active = `bg-primary text-primary-foreground`
  - [x] Week tabs: Week 1–4
  - [x] All 5 columns always shown (Ulam, Vegetables, Fruit, Soup, Snacks); Snacks cell bg = `bg-purple-50`
  - [x] Empty cells display "—"
  - [x] No Save, Reset, or visibility toggle controls
  - [x] Update to use new response shape `{ visible_to_parents: boolean, days: [] }`:
    - When `visible_to_parents = false`: hide table, show "Meal plan for this week is not yet available." card (`bg-muted rounded-xl p-6 text-center text-muted-foreground`)
    - When `visible_to_parents = true`: render full meal grid with all 5 columns (no dynamic column logic needed anymore)
  - [x] Remove `category_visibility` map handling and dynamic column logic — columns are now fixed; `COLUMNS` constant replaces `CATEGORY_CONFIG`; `MealGrid` no longer accepts `categoryVisibility` prop
  - [x] Update `types/portal.ts` — removed `CategoryVisibility` and old `MealPlanResponse`; new `MealPlanResponse` shape is `{ visible_to_parents: boolean; days: MealPlanDay[] }`; `lib/api/portal.ts` unchanged (same method signature, new return type flows through)
  - [ ] Branch selector shown above month tabs when parent has students in multiple branches — TODO: pending backend multi-branch response support

## 15. Feedback

### 15.1 Backend
- [x] `Portal\FeedbackController::index()` — parent's own feedback list
- [x] `Portal\FeedbackController::store()` — validates student ownership; `strip_tags()` on message; sets `branch_id` from student
- [x] `Kitchen\FeedbackController::index()` — branch-scoped list with filters
- [x] `Kitchen\FeedbackController::markRead()` — sets `is_read = true`
- [x] `Kitchen\FeedbackController::reply()` — saves sanitized `admin_reply`; dispatches `FeedbackReplyMail`
- [x] Portal routes: `GET|POST /api/v1/portal/feedback`
- [x] Kitchen routes: `GET /api/v1/references/feedback`, `PATCH /api/v1/references/feedback/{feedback}/mark-read`, `POST /api/v1/references/feedback/{feedback}/reply`

### 15.2 Frontend (Portal)
- [x] `app/(portal)/feedback/page.tsx`: star rating, category select, student selector, feedback list

### 15.3 Frontend (POS)
- [x] `app/(kitchen)/references/feedback/page.tsx`: list with filters, mark-read, reply dialog
- [x] Add "Feedback" to References navigation

## 16. Wallet Alert

### 16.1 Backend
- [x] `WalletAlertJob` — queries parent_student pivot; sends WalletAlertMail per matching parent
- [x] Dispatch `WalletAlertJob` from `CheckoutController` after wallet withdrawal

## 17. Student Contact Management (Kitchen/POS)

### 17.1 Backend
- [x] `Kitchen\StudentContactController::index(Student $student)` — list all contacts
- [x] `Kitchen\StudentContactController::store(Student $student)` — add contact (max 3); calls provisioning service if email provided
- [x] `Kitchen\StudentContactController::update(Student $student, StudentContact $contact)` — edit; re-provisions on email change
- [x] `Kitchen\StudentContactController::destroy(Student $student, StudentContact $contact)` — delete; detaches pivot
- [x] `Kitchen\StudentContactController::resendActivation(Student $student, StudentContact $contact)` — sends appropriate mail based on activation status
- [x] Add `portal_status` field to contact index response: `"activated"` / `"pending_activation"` / `"no_email"`
- [x] Routes: CRUD on `/api/v1/students/{student}/contacts`
- [x] Route: `POST /api/v1/students/{student}/contacts/{contact}/resend-activation`

### 17.2 Frontend (POS)
- [x] Add "Contacts" tab to student detail page
- [x] `AddContactDialog`, `EditContactDialog`, delete confirmation
- [x] Resend Activation button when `portal_status === "pending_activation"`
- [x] `lib/api/contacts.ts`, update `types/student.ts`

## 18. Parent Management (Kitchen/POS)

### 18.1 Backend
- [x] `Kitchen\ParentController::index()` — paginated list, search by name/email, activation status
- [x] `Kitchen\ParentController::index()` — branch filter added via `whereHas('students', branch_id)`; `show()` returns `profile_photo_url`; `Portal\AuthController::login()` also returns `profile_photo_url`
- [x] `Kitchen\ParentController::show(ParentUser $parent)` — parent profile + linked students
- [x] Routes: `GET /api/v1/references/parents`, `POST /api/v1/references/parents/{parent}/resend-activation`
- [x] Route: `GET /api/v1/references/parents/{parent}`

### 18.2 Frontend (POS)
- [x] `app/(kitchen)/references/parents/page.tsx` — list table with search, status filter, click to detail drawer
- [x] Add "Parents" to References navigation
- [x] `lib/api/parents.ts`, `types/parent.ts`

## 19. Update EnrollmentController
- [x] Call `ParentProvisioningService::provision()` for each contact with non-null email
- [x] Contact email made nullable (migration + validation update)

## 20. Email Notifications
- [x] `ParentWelcomeMail` — activation link
- [x] `WalletAlertMail` — low balance alert
- [x] `FeedbackReplyMail` — admin reply notification

## 21. Portal Layout Updates (`~/sunbites-portal`)
- [x] Verify nav links: Dashboard, Meal Plan, Feedback route correctly — all 4 links present and correctly routed in `portal-layout.tsx`
- [x] Remove any "Link a Student" or "Register" links from portal nav — no such links exist

## 22. Tests
- [x] `PortalAuthTest` — login/logout/forgot-password/reset-password/ability scoping
- [x] `ParentProvisioningTest` — provision/idempotent/multi-student/enrollment integration
- [x] `StudentContactTest` — CRUD + provisioning side effects + ownership guard
- [x] `FeedbackTest` — portal submit/sanitize/kitchen reply/mark-read
- [x] `ParentManagementTest` — list/show/search/activation status
- [x] `WalletAlertTest` — job dispatched on low balance; not dispatched when threshold is 0
