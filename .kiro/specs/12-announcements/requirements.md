# Spec 12 — Announcements

## Introduction

Announcements allow Admin, Manager, and Supervisor roles to compose and send custom notification messages to either parents or co-workers registered in the system. Recipients receive the message instantly via their notification bell (real-time through Reverb) and can read or delete it from their inbox. This is a staff-authored broadcast — distinct from the automated payment reminders in Spec 11.

**Depends on:** Spec 10 (Notifications — Reverb, notifications table, EchoProvider, NotificationBell infrastructure)

---

## Requirements

### Requirement 1 — Create and Send Announcement

**User Story:** As an Admin, Manager, or Supervisor, I want to compose an announcement and send it to selected recipients, so that I can communicate directly with parents or co-workers.

#### Acceptance Criteria

1. WHEN a staff member with role admin, manager, or supervisor submits a valid announcement form THEN the system SHALL create an `announcements` record and deliver a notification to each selected recipient.
2. WHEN creating an announcement THEN the system SHALL require the staff member to choose exactly one recipient type: `parents` or `staff` — not both.
3. WHEN `recipient_type` is `parents` THEN the selectable recipients SHALL be limited to parents who have at least one student enrolled in the active branch.
4. WHEN `recipient_type` is `staff` THEN the selectable recipients SHALL be limited to users assigned to the active branch, excluding the sender.
5. WHEN the `parent_ids` or `staff_ids` array is empty THEN the system SHALL return a 422 validation error.
6. WHEN `message` is missing or blank THEN the system SHALL return a 422 validation error.
7. WHEN an announcement is sent THEN the system SHALL record `recipient_count` equal to the number of successfully notified recipients.
8. WHEN `title` is omitted THEN the system SHALL accept the announcement and store `title` as null.
9. IF the authenticated staff member has role cashier THEN the system SHALL return 403.

### Requirement 2 — Announcement History

**User Story:** As an Admin, Manager, or Supervisor, I want to see a history of all announcements sent from the active branch, so that I can track past communications.

#### Acceptance Criteria

1. WHEN a staff member requests the announcements list THEN the system SHALL return announcements scoped to the active branch, newest first.
2. WHEN returning the announcements list THEN each entry SHALL include: sender name, recipient type, message preview (first 100 chars), recipient count, read count, and sent date.
3. IF the active branch has no announcements THEN the system SHALL return an empty `data` array.

### Requirement 3 — Announcement Detail

**User Story:** As an Admin, Manager, or Supervisor, I want to view the full detail of an announcement including who has read it, so that I can confirm delivery.

#### Acceptance Criteria

1. WHEN a staff member requests an announcement by ID THEN the system SHALL return the full message, sender, recipient type, sent date, and a list of recipients with their `read_at` status.
2. IF the announcement does not belong to the active branch THEN the system SHALL return 404.

### Requirement 4 — Staff Notification Inbox

**User Story:** As a staff member, I want to see notifications I have received, so that I can read announcements addressed to me.

#### Acceptance Criteria

1. WHEN a staff member requests their notifications THEN the system SHALL return all notifications where `notifiable_id = user.id`, newest first, regardless of the active branch.
2. WHEN returning the notification list THEN each entry SHALL include: id, title, message, sender name, read_at, created_at.
3. WHEN a staff member requests their unread count THEN the system SHALL return `{ count: N }` where N is notifications with `read_at IS NULL`.
4. WHEN a staff member marks a notification as read THEN the system SHALL set `read_at = now()` and return 200.
5. IF the notification does not belong to the authenticated staff member THEN the system SHALL return 404.
6. WHEN a staff member deletes a notification THEN the system SHALL hard-delete it and return 204.

### Requirement 5 — Real-Time Delivery

**User Story:** As a recipient (parent or staff), I want my notification bell to update instantly when I receive an announcement, so that I am aware of new messages without refreshing.

#### Acceptance Criteria

1. WHEN an announcement is sent to a staff member THEN the system SHALL broadcast on `PrivateChannel("staff.{$user->id}")`.
2. WHEN a staff member's POS app receives the broadcast event THEN the client SHALL invalidate the unread-count query and update the bell badge in real time.
3. WHEN an announcement is sent to a parent THEN the system SHALL broadcast on `PrivateChannel("parents.{$parent->id}")` using the same channel established in Spec 10.
4. WHILE a staff member is not authenticated THEN the system SHALL NOT open a WebSocket channel for that staff member.

---

## Cross-Cutting Requirements

- **Authorization**: Only admin, manager, supervisor can send announcements. All staff roles can read their own notification inbox.
- **Data isolation**: Announcement creation and recipient picker are branch-scoped. Staff notification inbox is user-scoped (all branches).
- **No mixing**: A single announcement must target either parents or staff — never both. Enforced at the model level via `recipient_type` enum.
- **No editing/deleting sent announcements**: Announcements are immutable after sending (audit integrity).
- **Performance**: Recipient notifications are dispatched via the queue (ShouldQueue) — the HTTP response returns immediately after creating the announcement record.
- **Error handling**: If a recipient ID in the list does not belong to the active branch, it is silently skipped and not counted in `recipient_count`.

---

## POS Notification Design (MagicBell reference) — Added 2026-06-13

### Single Bell Rule

The POS header contains **exactly one** bell (`NotificationBell`). It covers all inbound staff notification types. The `ReminderBell` component must **not** appear in the header — it is a workflow tool accessible only via the Reminders nav item.

### POS Notification Page Design

Each notification card displays:
- Left unread dot (purple, hidden once read)
- Bold type-aware title
- 2-line message preview (type-aware)
- Right-aligned relative timestamp
- `...` context menu: "Mark as read", "Delete"
- Click navigates to the notification's subject page
- Empty state: bell illustration + "You're all caught up"

Type-aware rendering:

| Notification type | Title | Preview | Click destination |
|---|---|---|---|
| `AnnouncementNotification` | `data.title ?? "Announcement"` | `data.message` (truncated) | `/announcements/{data.announcement_id}` |
| `PreRegistrationNotification` | `"New Pre-Registration"` | `"{data.student_name} — {data.enrollment_type} at {data.branch_name}"` | `/pre-registrations/{data.pre_registration_id}` |

### TypeScript Discriminated Union

`types/staff-notification.ts` must use a discriminated union on the `type` FQCN field, not a single flat type. This prevents undefined field access when rendering different notification types.

---

## Out of Scope

- Replies or threaded conversations
- Combining parents and staff in a single announcement
- Editing or recalling a sent announcement
- Email delivery of announcements (database + broadcast only)
- Parent-to-staff or parent-to-parent messaging
- Cross-branch announcements (a single send cannot target multiple branches)
