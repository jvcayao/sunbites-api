# Design: Unified Notification System — Bug Fixes + MagicBell Redesign

**Date:** 2026-06-13
**Branch:** `feat/parents-portal-update`
**Specs affected:** Spec 10 (Notifications & Reminders), Spec 11 (Announcements)

---

## Overview

This design addresses three issues discovered after Specs 10 and 11 were fully implemented:

1. **Bug** — Announcements sent from POS appear as "Payment Reminder" on the parent portal
2. **Bug** — POS header shows two visually identical notification bells (`NotificationBell` + `ReminderBell`); should be one
3. **UX** — Notification page design is misaligned and needs to match the approved MagicBell reference design

The fix introduces a discriminated union notification type system, consolidates the POS header to a single bell, redesigns both notification list pages to match the MagicBell reference screenshot, and adds click-to-navigate routing per notification type (Option B, user-confirmed).

---

## Root Cause Analysis

### Bug #1 — Announcements render as "Payment Reminder"

`~/sunbites-portal/types/notification.ts` defines `ParentNotification.data` as `PaymentReminderData` only — no `AnnouncementData` type exists.

The `NotificationItem` component in `app/(portal)/notifications/page.tsx` unconditionally:
- Sets title to `"Payment reminder — {data.school_month} {data.school_year}"` (both undefined for announcements)
- Accesses `data.students` and `data.total_amount` (also undefined for announcements)

The portal's `components/notification-bell.tsx` only subscribes to `.listen("PaymentReminderNotification", ...)` — the bell badge does **not** update in real time when an announcement arrives.

### Bug #2 — Two bells in POS header

Spec 10 Task 6.4 added `<ReminderBell />` (outbound payment reminder urgency count, navigates to `/reminders`).
Spec 11 Task 7.4 added `<NotificationBell />` (inbound staff announcements, navigates to `/notifications`) alongside it.

Both use the `Bell` icon from lucide-react and are visually identical to the user. The `ReminderBell` bell belongs exclusively in the Reminders nav item — not the header.

---

## Architecture

### Notification type discrimination

Laravel stores the notification class FQCN in the `notifications.type` column:

| Column value | Source |
|---|---|
| `App\Notifications\PaymentReminderNotification` | Portal only |
| `App\Notifications\AnnouncementNotification` | Portal (parent targets) + POS (staff targets) |
| `App\Notifications\PreRegistrationNotification` | POS only |

TypeScript must discriminate on this `type` field to render the correct card UI and route clicks correctly. A discriminated union on `type` provides compile-time safety and eliminates the undefined-field bug.

### Single bell principle

One bell per app. The badge count = total unread notifications for the authenticated user across all notification types. The bell navigates to `/notifications` in both apps.

The existing `ReminderBell` in POS is about **outbound urgency** (how many parents have not been sent a reminder yet) — this is a workflow tool, not an inbox notification. It belongs in the Reminders page nav entry only.

### No backend changes required

Both `parents.{id}` and `staff.{id}` broadcast channels are already authenticated. `PreRegistrationNotification` already broadcasts on `staff.{id}` via `User::receivesBroadcastNotificationsOn()`. Only frontend changes are needed.

---

## Portal Changes (`~/sunbites-portal`)

### `types/notification.ts`

Replace current `PaymentReminderData`-only definition with a discriminated union:

```typescript
interface PaymentReminderData {
  school_month: string;
  school_year: number;
  due_date: string;
  students: Array<{ name: string; amount: number }>;
  total_amount: number;
}

interface AnnouncementData {
  announcement_id: number;
  title: string | null;
  message: string;
  sender_name: string;
  sent_at: string;
}

export type ParentNotification =
  | {
      id: string;
      type: "App\\Notifications\\PaymentReminderNotification";
      data: PaymentReminderData;
      read_at: string | null;
      created_at: string;
    }
  | {
      id: string;
      type: "App\\Notifications\\AnnouncementNotification";
      data: AnnouncementData;
      read_at: string | null;
      created_at: string;
    };
```

### `components/notification-bell.tsx`

Add `AnnouncementNotification` listener so the badge increments in real time for both notification types:

```typescript
channel
  .listen("PaymentReminderNotification", () => refetch())
  .listen("AnnouncementNotification",    () => refetch());
```

### `app/(portal)/notifications/page.tsx`

Full redesign to MagicBell style. Each `NotificationCard` renders:

| Element | Position | Detail |
|---|---|---|
| Unread dot | Far left | Purple `#5224C1`, hidden when `read_at !== null` |
| Title | Top-left bold | Type-aware (see below) |
| Preview | Below title | 2 lines max, type-aware (see below) |
| Relative timestamp | Top-right | `relativeTime(notification.created_at)` |
| `...` menu button | Right | "Mark as read" / "Delete" |

**Type-aware title and preview:**

| Type | Title | Preview |
|---|---|---|
| `PaymentReminderNotification` | `"Payment Reminder — {Month} {Year}"` | `"{N} student(s) — ₱{total}"` |
| `AnnouncementNotification` | `data.title ?? "Announcement"` | `data.message` (truncated) |

**Header row:** "Notifications" heading + mark-all-read icon (✓) + cog icon (⚙).

**Click behavior (Option B — redirect):**
- `PaymentReminderNotification` → mark read (optimistic) + `router.push("/payments")`
- `AnnouncementNotification` → mark read (optimistic) + inline accordion expansion showing full `data.message` and `"From: {data.sender_name}"`

**Empty state:** Bell illustration + "You're all caught up" when notification list is empty.

---

## POS Changes (`~/sunbites-pos`)

### `components/layouts/kitchen-layout.tsx`

Remove `<ReminderBell />` from the header. The Reminders nav item in the sidebar remains the entry point for the outbound reminders workflow. `<NotificationBell />` is the sole bell in the header.

```tsx
// Remove:
{canSeeReminders && <ReminderBell />}

// Keep:
<NotificationBell />
```

### `components/notification-bell.tsx` (POS)

Add `PreRegistrationNotification` listener so the single bell badge reflects all inbound staff notifications:

```typescript
channel
  .listen("AnnouncementNotification",    () => refetch())
  .listen("PreRegistrationNotification", () => refetch());
```

No backend changes needed — `PreRegistrationNotification` already uses `receivesBroadcastNotificationsOn()` on `User` which returns `staff.{id}`.

### `app/(kitchen)/notifications/page.tsx`

Redesign to MagicBell style. Type-aware rendering for all three notification types:

| Type | Title | Preview |
|---|---|---|
| `AnnouncementNotification` | `data.title ?? "Announcement"` | `data.message` (truncated) |
| `PreRegistrationNotification` | `"New Pre-Registration"` | `"{data.student_name} — {data.enrollment_type} at {data.branch_name}"` |

**Type-safe TypeScript:** Add `StaffNotification` discriminated union to `types/staff-notification.ts`.

**Click routing:**
- `AnnouncementNotification` → mark read (optimistic) + navigate to `/announcements/{data.announcement_id}`
- `PreRegistrationNotification` → mark read (optimistic) + navigate to `/pre-registrations/{data.pre_registration_id}`

**Empty state:** same as portal — bell illustration + "You're all caught up".

---

## Shared Additions (both apps)

### `lib/utils/relative-time.ts`

New utility function returning human-relative timestamps matching the MagicBell screenshot:

```
< 1 min  → "just now"
< 1 hr   → "{N}m"
< 24 hr  → "{N}h"
< 7 days → "{N}d"
≥ 7 days → "Jun 10"
```

### Optimistic mark-as-read

Row click immediately updates local query cache (`read_at = now()`) before the API response, with revert on error. Implemented via TanStack Query's `onMutate` / `onError` callbacks.

---

## Data Flow

```
Portal parent receives announcement:
  Backend → broadcast on parents.{id} channel
         → Echo listener in notification-bell.tsx fires
         → unread-count query invalidated → badge updates
         → parent opens /notifications
         → discriminated union renders AnnouncementNotification card
         → parent clicks → optimistic mark-as-read → inline accordion expands

POS staff receives pre-registration:
  Backend → broadcast on staff.{id} channel
         → Echo listener in notification-bell.tsx fires (new listener added)
         → unread-count query invalidated → badge updates
         → staff opens /notifications
         → PreRegistrationNotification card renders
         → staff clicks → optimistic mark-as-read → navigate to /pre-registrations/{id}
```

---

## Security Considerations

- No new backend routes or controllers — all API endpoints and broadcast channels are already implemented and authenticated
- Optimistic mark-as-read is UI-only; actual `read_at` is persisted by the backend `PATCH /notifications/{id}/read` endpoint
- No new authorization surface introduced
- Type narrowing prevents accessing undefined fields from mismatched notification shapes

---

## Testing Strategy

**Portal:**
- Update `notifications/page.tsx` tests: render `AnnouncementNotification` fixture → assert correct title/preview rendered (not "Payment reminder")
- Update `notification-bell.tsx` tests: assert badge increments on `AnnouncementNotification` event
- New test: click announcement card → assert accordion expands with full message

**POS:**
- Update `notification-bell.tsx` tests: assert badge increments on `PreRegistrationNotification` event
- Update `notifications/page.tsx` tests: cover `AnnouncementNotification` + `PreRegistrationNotification` card rendering and click routing
- Update `kitchen-layout.tsx` tests: assert only one bell (`NotificationBell`) renders in header

---

## POS Announcements Page Redesign

### Current state (from screenshots)

The Announcements list (`/announcements`) is a plain text list with no visual hierarchy. The create form (`/announcements/create`) is a bare white form with plain radio buttons and a flat checkbox list.

### Redesigned Announcements List (`/announcements`)

Each announcement is a **card** with clear visual structure:

```
┌──────────────────────────────────────────────────────────────┐
│  [Parents]  badge (purple)               Jun 13   →          │
│  test 10  (bold title)                                       │
│  qwewqewqewqe  (message preview, 2 lines gray)               │
│  Sent by Jhersonnn Cayao  ·  1 sent  ·  1 read  (footer)    │
└──────────────────────────────────────────────────────────────┘
```

- Recipient type badge: "Parents" = purple, "Staff" = blue/indigo
- Title: bold, 1 line, truncated
- Message: gray, 2 lines max, truncated
- Footer: sender name · sent count · read count (with `Eye` icon)
- Timestamp: top-right, relative format ("Jun 13", "2h", "just now")
- Right arrow (`→`) navigates to detail page on click
- "+ New Announcement" button in page header (top-right)
- Empty state: Megaphone illustration + "No announcements yet"

### Redesigned Create Form (`/announcements/create`)

Contained in a card with proper sections:

**Section 1 — Content**
- `Title` (optional) — single-line input with placeholder "e.g. Canteen notice"
- `Message` — textarea, required, with character count in bottom-right corner

**Section 2 — Recipients**
- "Send to" — styled pill toggle buttons ("Parents" / "Staff"), not plain radio inputs
- Recipients — searchable multi-select checklist:
  - Search input at top
  - "Select all (N)" link
  - Each row: checkbox + recipient name + avatar initial
  - Shows selected count: "3 selected" badge near the list header

**Footer bar**
- `Cancel` button (left)
- `Send (N)` button (right, primary, disabled when 0 selected) — N reflects selected recipient count

### Redesigned Detail Page (`/announcements/[id]`)

Two-panel layout:

**Left / Top — Announcement info card**
- Badge (Parents / Staff)
- Full title + full message (no truncation)
- Sent by · Sent at · Total recipients

**Right / Bottom — Recipient list table**
- Columns: Name, Status (Read / Unread), Read at (relative time or "—")
- Row: unread rows have a colored dot, read rows are grayed
- Summary: "{N} read / {total}"

---

## Summary of Files Changed

| File | App | Change |
|---|---|---|
| `types/notification.ts` | Portal | Discriminated union replacing single type |
| `components/notification-bell.tsx` | Portal | Add `AnnouncementNotification` listener |
| `app/(portal)/notifications/page.tsx` | Portal | Full MagicBell redesign, type-aware cards, click routing |
| `lib/utils/relative-time.ts` | Portal | New shared utility |
| `components/layouts/kitchen-layout.tsx` | POS | Remove `<ReminderBell />` from header |
| `components/notification-bell.tsx` | POS | Add `PreRegistrationNotification` listener |
| `app/(kitchen)/notifications/page.tsx` | POS | Full MagicBell redesign, type-aware cards, click routing |
| `lib/utils/relative-time.ts` | POS | New shared utility |
| `types/staff-notification.ts` | POS | Add discriminated union |
| `app/(kitchen)/announcements/page.tsx` | POS | Card-based list redesign |
| `app/(kitchen)/announcements/create/page.tsx` | POS | Pill toggle recipients, character count, selected count |
| `app/(kitchen)/announcements/[id]/page.tsx` | POS | Two-panel detail with recipient read status table |
