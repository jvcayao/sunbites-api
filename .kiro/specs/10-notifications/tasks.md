# Spec 10 — Notifications Tasks

## Task 1: Backend — Reverb Setup & Notifications Table

- [x] 1.1 Run `php artisan notifications:table` and migrate — `notifications` table created
- [x] 1.2 Install Laravel Reverb via Composer (`php artisan reverb:install`); add `REVERB_*` env vars to `.env` and `.env.example`
- [x] 1.3 `routes/channels.php` — `Broadcast::channel('parents.{parentId}', ...)` with `ParentUser` guard; `Broadcast::channel('staff.{userId}', ...)` with `User` guard
- [x] 1.4 Register `POST /api/v1/portal/broadcasting/auth` in `routes/portal-api.php` (inside `auth:parents` group)
- [x] 1.5 Register `POST /api/v1/broadcasting/auth` in `routes/kitchen-api.php` (inside `auth:sanctum` group)
- [x] 1.6 Run Pint formatter; run existing test suite — green

---

## Task 2: Backend — Portal Notification Endpoints

- [x] 2.1 Create `App\Http\Controllers\Portal\NotificationController`:
  - `index()` — list all notifications for authenticated parent, newest first
  - `unreadCount()` — `{ count: N }` where N = notifications with `read_at IS NULL`
  - `markRead($id)` — set `read_at = now()` on individual notification (404 if not owner)
  - `markAllRead()` — mark all parent's notifications as read
  - `destroy($id)` — hard delete individual (404 if not owner)
  - `clearAll()` — hard delete all notifications for parent
- [x] 2.2 Register 6 routes in `routes/portal-api.php`
- [x] 2.3 Run Pint formatter

---

## Task 3: Backend — Staff Notification Endpoints

- [x] 3.1 Add `Notifiable` trait to `App\Models\User` (required for staff notifications)
- [x] 3.2 Create `App\Http\Controllers\Kitchen\StaffNotificationController`:
  - `unreadCount()` — `{ count: N }` for staff bell badge
  - `index()` — list all notifications for authenticated user, newest first
  - `markAllRead()` — mark all as read
  - `markRead($id)` — mark individual as read (404 if not owner)
  - `destroy($id)` — hard delete (404 if not owner)
- [x] 3.3 Register 5 routes in `routes/kitchen-api.php` (all staff roles)
- [x] 3.4 Run Pint formatter

---

## Task 4: Backend Tests

- [x] 4.1 `tests/Feature/Portal/NotificationTest.php` — 9 tests: list, unread count, mark read, mark all read, delete, clear all, 401 unauthenticated, 404 wrong owner
- [x] 4.2 `tests/Feature/Kitchen/StaffNotificationTest.php` — 7 tests: unread count, list, mark read, mark all read, delete, 401 unauthenticated, 404 wrong owner
- [x] 4.3 Full suite green (454/454)

---

## Task 5: Frontend Portal — Echo Provider & Notification Bell

- [x] 5.1 `npm install laravel-echo pusher-js` in `~/sunbites-portal`
- [x] 5.2 Add `NEXT_PUBLIC_REVERB_*` vars to `.env.example`
- [x] 5.3 `types/notification.ts` — discriminated union: `PaymentReminderData` + `AnnouncementData` interfaces; `ParentNotification` union type on `type` FQCN
- [x] 5.4 `lib/api/notifications.ts` — all 6 notification API calls typed
- [x] 5.5 `components/providers/echo-provider.tsx` — Client Component; initialises Echo with Reverb config + Bearer token; `authEndpoint` at portal broadcasting/auth; disconnects on token change
- [x] 5.6 `<EchoProvider>` added to `app/(portal)/layout.tsx` (inside auth-protected layout, not root)
- [x] 5.7 `components/notification-bell.tsx` — subscribes to `parents.{id}` channel; listens for `PaymentReminderNotification` + `AnnouncementNotification`; invalidates `["unread-count"]` query; badge hidden when 0; navigates to `/notifications`
- [x] 5.8 `NotificationBell` added to `components/layouts/portal-layout.tsx` header

---

## Task 6: Frontend Portal — Notifications Page

- [x] 6.1 `lib/relative-time.ts` — human-relative timestamp helper: "just now" / "{N}m" / "{N}h" / "{N}d" / "Jun 10"; 5 unit tests pass
- [x] 6.2 `app/(portal)/notifications/page.tsx` — MagicBell design:
  - Unread dot (left, purple, hidden when read)
  - Type-aware bold title (PaymentReminderNotification → "Payment Reminder — {Month} {Year}", AnnouncementNotification → title or "Announcement")
  - 2-line type-aware preview
  - Relative timestamp (right)
  - `...` context menu: "Mark as read" / "Delete"
  - Click routing: PaymentReminderNotification → `/payments`, AnnouncementNotification → inline accordion expansion
  - "Mark all read" and "Clear all" (with confirm dialog) header actions
  - Empty state: bell illustration + "You're all caught up"
- [x] 6.3 `app/(portal)/notifications/loading.tsx` — skeleton
- [x] 6.4 Portal notification page tests (6/6 pass): type-aware rendering, click routing, empty state, accordion expansion, regression for type confusion bug
- [x] 6.5 Portal lint: 0 errors

---

## Task 7: Frontend POS — Echo Provider & Notification Bell

- [x] 7.1 `npm install laravel-echo pusher-js` in `~/sunbites-pos`
- [x] 7.2 Add `NEXT_PUBLIC_REVERB_*` vars to `.env.example`
- [x] 7.3 `types/staff-notification.ts` — discriminated union: `AnnouncementData` + `PreRegistrationData` interfaces; `StaffNotification` union type
- [x] 7.4 `lib/api/staff-notifications.ts` — 5 staff notification API calls typed
- [x] 7.5 `lib/relative-time.ts` — same relative-time helper as portal
- [x] 7.6 `components/providers/echo-provider.tsx` — reads staff token from `useAuthStore`; `authEndpoint` at kitchen broadcasting/auth
- [x] 7.7 `<EchoProvider>` added to `app/(kitchen)/layout.tsx`
- [x] 7.8 `components/notification-bell.tsx` — subscribes to `staff.{userId}` channel; listens for `AnnouncementNotification` + `PreRegistrationNotification`; invalidates staff unread-count query; badge hidden when 0; navigates to `/notifications`
- [x] 7.9 Header in `kitchen-layout.tsx` — ONE `NotificationBell` in header (inbound); `ReminderBell` stays in sidebar nav only

---

## Task 8: Frontend POS — Staff Notifications Page

- [x] 8.1 `app/(kitchen)/notifications/page.tsx` — MagicBell design: type-aware cards for AnnouncementNotification (→ `/announcements/{id}`) and PreRegistrationNotification (→ `/pre-registrations/{id}`); unread dot; relative timestamps; context menu; empty state
- [x] 8.2 `app/(kitchen)/notifications/loading.tsx` — skeleton
- [x] 8.3 POS staff notification page tests (5/5 pass): type-aware rendering, click routing for both types
- [x] 8.4 POS lint: 0 errors
