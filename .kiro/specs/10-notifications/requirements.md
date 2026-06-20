# Spec 10 — Notifications

## Introduction

This spec establishes the notification infrastructure for the entire Sunbites system. It covers Laravel Reverb WebSocket setup, the database `notifications` table, private channel authorization, and the shared client-side components — EchoProvider and NotificationBell — used by both the POS app and the parent portal.

All domain-specific notification classes (payment reminders, announcements, pre-registration alerts) depend on this infrastructure. Specs 11, 12, and 13 all depend on this spec being complete first.

---

## Scope

**In scope:**
- Laravel Reverb installation and configuration
- `notifications` table (Laravel built-in polymorphic)
- Private channel authorization for `parents.{id}` and `staff.{userId}`
- Broadcast auth routes (portal + kitchen)
- `EchoProvider` Client Component for both Next.js apps
- `NotificationBell` component for both Next.js apps (real-time badge)
- Portal notification management endpoints (list, unread count, mark read, delete, clear)
- Staff notification management endpoints (unread count, list, mark read, delete, mark all read)
- Portal notifications page (MagicBell-style design with discriminated union rendering)
- POS notifications page (MagicBell-style design with discriminated union rendering)
- Relative timestamp helper (`lib/relative-time.ts`) shared by both apps

**Out of scope:**
- Domain notification classes (handled by specs 11, 12, 13)
- Email delivery (all notifications are database + broadcast only)
- Push notifications to mobile devices

---

## Requirements

### Requirement 1 — Reverb WebSocket Server

**User Story:** As the system, I need a WebSocket server so that real-time notification delivery is possible for both parents and staff.

#### Acceptance Criteria

1. WHEN Laravel Reverb is installed THEN the system SHALL have `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` environment variables configured in `.env` and `.env.example`.
2. WHEN a queued notification implements `ShouldBroadcast` THEN the system SHALL deliver the event over Reverb to the appropriate private channel.
3. WHERE Laravel Cloud deployment is used THEN a `type: reverb` worker SHALL be declared so Cloud provisions the Reverb server automatically.

---

### Requirement 2 — Database Notification Storage

**User Story:** As the system, I need a polymorphic `notifications` table so that both `ParentUser` and `User` can receive and persist in-app notifications.

#### Acceptance Criteria

1. WHEN `php artisan notifications:table` is run and migrated THEN the `notifications` table SHALL exist with columns: `id` (UUID), `type`, `notifiable_type`, `notifiable_id`, `data` (JSON), `read_at` (nullable timestamp), `created_at`, `updated_at`.
2. WHEN a notification is dispatched with `via(['database'])` THEN the system SHALL write a row to `notifications` where `notifiable_type` is either `App\Models\ParentUser` or `App\Models\User`.
3. WHERE a notification is deleted THEN the system SHALL hard-delete the row — no soft deletes on `notifications`.

---

### Requirement 3 — Private Channel Authorization

**User Story:** As a parent or staff member, I need my WebSocket connection to be restricted to my own private channel so that I cannot receive other users' notifications.

#### Acceptance Criteria

1. WHEN a parent client requests access to `PrivateChannel("parents.{parentId}")` THEN the system SHALL authorize only if the authenticated `ParentUser` id matches `parentId`.
2. WHEN a staff client requests access to `PrivateChannel("staff.{userId}")` THEN the system SHALL authorize only if the authenticated `User` id matches `userId`.
3. WHEN an unauthorized user attempts to subscribe to a private channel THEN the system SHALL return 403.
4. WHERE the portal uses `auth:parents` guard THEN the broadcast auth route SHALL be `POST /api/v1/portal/broadcasting/auth` with `auth:parents` middleware.
5. WHERE the kitchen uses `auth:sanctum` guard THEN the broadcast auth route SHALL be `POST /api/v1/broadcasting/auth` with `auth:sanctum` middleware.

---

### Requirement 4 — Frontend Echo Provider (Both Apps)

**User Story:** As a logged-in user, I need the WebSocket connection to initialize automatically when I log in so that I receive real-time notification pushes without manual action.

#### Acceptance Criteria

1. WHEN a parent logs into the portal THEN the system SHALL initialize a `laravel-echo` client pointing at the Reverb server using the Sanctum Bearer token from the Zustand auth store.
2. WHEN a staff member logs into the POS app THEN the system SHALL initialize a `laravel-echo` client pointing at the Reverb server using the staff Bearer token.
3. WHEN the authenticated user logs out THEN the Echo client SHALL disconnect.
4. WHERE the portal EchoProvider is placed THEN it SHALL be inside `app/(portal)/layout.tsx` — inside the auth-protected layout, not the root layout.
5. WHERE the POS EchoProvider is placed THEN it SHALL be inside `app/(kitchen)/layout.tsx`.
6. WHEN `NEXT_PUBLIC_REVERB_SCHEME` is `https` THEN the Echo client SHALL use TLS (`forceTLS: true`).

---

### Requirement 5 — Notification Bell (Both Apps)

**User Story:** As a logged-in user, I want a bell icon in the header that shows how many unread notifications I have and updates in real time.

#### Acceptance Criteria

1. WHEN a notification is received over the WebSocket THEN the system SHALL invalidate the unread-count query so the badge updates without a manual page refresh.
2. WHEN the unread count is 0 THEN the badge SHALL be hidden.
3. WHEN the user clicks the bell THEN they SHALL be navigated to the notifications page (`/notifications`).
4. WHERE the portal bell is placed THEN it SHALL listen on `PrivateChannel("parents.{parentId}")` for all parent notification event types.
5. WHERE the POS bell is placed THEN it SHALL listen on `PrivateChannel("staff.{userId}")` for all staff notification event types.
6. WHERE the POS header is concerned THEN there SHALL be exactly ONE notification bell — it covers all inbound staff notification types. The `ReminderBell` (outbound, payment-reminder workflow) is separate and lives in the sidebar nav, not the header.

---

### Requirement 6 — Portal Notification Management

**User Story:** As a parent, I want to view, read, and delete my notifications so that I can manage my inbox.

#### Acceptance Criteria

1. WHEN a parent requests `GET /api/v1/portal/notifications` THEN the system SHALL return all notifications for that parent, newest first, excluding deleted rows.
2. WHEN a parent requests `GET /api/v1/portal/notifications/unread-count` THEN the system SHALL return `{ count: N }` where N is notifications with `read_at IS NULL`.
3. WHEN a parent calls `PATCH /api/v1/portal/notifications/{id}/read` THEN the system SHALL set `read_at = now()` and return 200.
4. WHEN a parent calls `POST /api/v1/portal/notifications/mark-all-read` THEN the system SHALL set `read_at = now()` on all unread notifications for that parent.
5. WHEN a parent calls `DELETE /api/v1/portal/notifications/{id}` THEN the system SHALL hard-delete that notification.
6. WHEN a parent calls `DELETE /api/v1/portal/notifications` THEN the system SHALL hard-delete all notifications for that parent.
7. IF a notification does not belong to the authenticated parent THEN the system SHALL return 404.

---

### Requirement 7 — Staff Notification Management (POS)

**User Story:** As a staff member, I want to view, read, and delete notifications I have received so that I can manage my inbox.

#### Acceptance Criteria

1. WHEN a staff member requests `GET /api/v1/staff/notifications` THEN the system SHALL return all notifications where `notifiable_id = user.id`, newest first.
2. WHEN a staff member requests `GET /api/v1/staff/notifications/unread-count` THEN the system SHALL return `{ count: N }` for bell badge.
3. WHEN a staff member calls `PATCH /api/v1/staff/notifications/{id}/read` THEN the system SHALL set `read_at = now()` and return 200.
4. WHEN a staff member calls `POST /api/v1/staff/notifications/mark-all-read` THEN the system SHALL mark all of that user's notifications as read.
5. WHEN a staff member calls `DELETE /api/v1/staff/notifications/{id}` THEN the system SHALL hard-delete it.
6. IF the notification does not belong to the authenticated staff member THEN the system SHALL return 404.

---

### Requirement 8 — Notification Page Design (Both Apps)

**User Story:** As a user, I want a well-designed notification page that shows different notification types distinctly and lets me act on each.

#### Acceptance Criteria

1. WHEN the notification list is rendered THEN each card SHALL show: an unread dot (hidden once read), a bold type-aware title, a 2-line message preview, and a right-aligned relative timestamp.
2. WHEN a relative timestamp is shown THEN it SHALL use a human-readable format: "just now" / "{N}m" / "{N}h" / "{N}d" / "Jun 10".
3. WHEN multiple notification types exist THEN the frontend SHALL use a **discriminated union** on the `type` FQCN field to render type-aware content — never a single flat type with optional fields.
4. WHEN the notification list is empty THEN the system SHALL show an empty-state illustration with the message "You're all caught up."
5. WHEN a notification card has a `...` context menu THEN it SHALL offer "Mark as read" and "Delete".

---

## API Routes

### Portal (`auth:parents` + ability `parent`)

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/portal/notifications` | List all notifications, newest first |
| GET | `/api/v1/portal/notifications/unread-count` | Bell badge count `{ count: N }` |
| PATCH | `/api/v1/portal/notifications/{id}/read` | Mark individual as read |
| POST | `/api/v1/portal/notifications/mark-all-read` | Mark all as read |
| DELETE | `/api/v1/portal/notifications/{id}` | Delete individual (hard delete) |
| DELETE | `/api/v1/portal/notifications` | Clear all (hard delete) |
| POST | `/api/v1/portal/broadcasting/auth` | Reverb channel auth (auth:parents guard) |

### Kitchen (`auth:sanctum` + ability `staff`)

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/staff/notifications/unread-count` | all staff | Bell badge count |
| GET | `/api/v1/staff/notifications` | all staff | Staff inbox |
| POST | `/api/v1/staff/notifications/mark-all-read` | all staff | Mark all as read |
| PATCH | `/api/v1/staff/notifications/{id}/read` | all staff | Mark individual as read |
| DELETE | `/api/v1/staff/notifications/{id}` | all staff | Delete notification |
| POST | `/api/v1/broadcasting/auth` | all staff | Reverb channel auth (auth:sanctum guard) |

---

## Requirements Checklist

### Backend

- [x] Run `php artisan notifications:table` and migrate — `notifications` table created
- [x] `routes/channels.php` — `parents.{parentId}` channel auth: `$user->id === $parentId` (ParentUser guard)
- [x] `routes/channels.php` — `staff.{userId}` channel auth: `$user->id === $userId` (User guard)
- [x] `POST /api/v1/portal/broadcasting/auth` — registered in `routes/portal-api.php` with `auth:parents` guard
- [x] `POST /api/v1/broadcasting/auth` — registered in `routes/kitchen-api.php` with `auth:sanctum` guard
- [x] `App\Http\Controllers\Portal\NotificationController` — index, unreadCount, markRead, markAllRead, destroy, clearAll
- [x] `App\Http\Controllers\Kitchen\StaffNotificationController` — unreadCount, index, markAllRead, markRead, destroy
- [x] Register all 6 portal notification routes in `routes/portal-api.php`
- [x] Register all 5 staff notification routes in `routes/kitchen-api.php`
- [x] Feature tests: `NotificationTest` (portal, 9 tests pass)
- [x] Feature tests: `StaffNotificationTest` (kitchen, 7 tests pass)
- [x] Laravel Reverb installed via Composer; `REVERB_*` env vars in `.env` and `.env.example`

### Frontend Portal (`~/sunbites-portal`)

- [x] `npm install laravel-echo pusher-js`
- [x] `NEXT_PUBLIC_REVERB_APP_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME` added to `.env.example`
- [x] `components/providers/echo-provider.tsx` — Client Component; initialises Echo with Reverb config + Bearer token from `useAuthStore`; auth endpoint at `/api/v1/portal/broadcasting/auth`; disconnects on token change/logout
- [x] `<EchoProvider>` added to `app/(portal)/layout.tsx` (inside auth-protected layout)
- [x] `components/notification-bell.tsx` — subscribes to `PrivateChannel("parents.{id}")`; listens for all parent notification event types; invalidates `["unread-count"]` query on any event; badge hidden when count is 0; navigates to `/notifications`
- [x] `NotificationBell` added to `components/layouts/portal-layout.tsx` header
- [x] `types/notification.ts` — discriminated union on `type` FQCN field: `PaymentReminderData` + `AnnouncementData` interfaces; `ParentNotification` union type
- [x] `lib/api/notifications.ts` — all 6 portal notification API calls typed
- [x] `lib/relative-time.ts` — relative timestamp helper: "just now" / "{N}m" / "{N}h" / "{N}d" / "Jun 10"; tested
- [x] `app/(portal)/notifications/page.tsx` — MagicBell design: unread dot, type-aware title/preview, relative timestamp, `...` context menu; click routing by type; empty state
- [x] `app/(portal)/notifications/loading.tsx` — skeleton

### Frontend POS (`~/sunbites-pos`)

- [x] `npm install laravel-echo pusher-js`
- [x] `NEXT_PUBLIC_REVERB_*` env vars added to `.env.example`
- [x] `components/providers/echo-provider.tsx` — Client Component; reads staff Bearer token from `useAuthStore`; auth endpoint at `/api/v1/broadcasting/auth`
- [x] `<EchoProvider>` added to `app/(kitchen)/layout.tsx`
- [x] `types/staff-notification.ts` — discriminated union on `type` FQCN field: `AnnouncementData` + `PreRegistrationData` interfaces
- [x] `lib/api/staff-notifications.ts` — all 5 staff notification API calls typed
- [x] `lib/relative-time.ts` — same relative-time helper as portal
- [x] `components/notification-bell.tsx` — subscribes to `PrivateChannel("staff.{userId}")`; listens for `AnnouncementNotification` and `PreRegistrationNotification` events; invalidates `["staff-notifications-unread"]` query; badge hidden when count is 0; navigates to `/notifications`
- [x] Header in `kitchen-layout.tsx` — exactly ONE `NotificationBell` (inbound); `ReminderBell` stays in sidebar nav, not header
- [x] `app/(kitchen)/notifications/page.tsx` — MagicBell design: type-aware cards for both notification types; click routing to `/announcements/{id}` and `/pre-registrations/{id}`; empty state
- [x] `app/(kitchen)/notifications/loading.tsx` — skeleton
- [x] POS notification page tests (5/5 pass): type-aware rendering, click routing
