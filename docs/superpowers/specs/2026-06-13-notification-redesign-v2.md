# Design: Notification & Announcements Redesign v2

**Date:** 2026-06-13
**Branch:** `feat/parents-portal-update`
**Apps affected:** `~/sunbites-pos` (POS/admin), `~/sunbites-portal` (parent portal)
**Approach:** Extracted component system (Approach B)

---

## Overview

Three surfaces are being redesigned across both apps:

1. **Bell dropdown** — the bell currently navigates to a full `/notifications` page. It must open an inline Popover panel instead.
2. **Notifications full page** — reuses the same `NotificationItem` component from the dropdown; gets consistent visual treatment.
3. **Announcements page** — POS only; redesigned from a flat text list into a proper card-based layout.

The key architectural decision is extracting a shared `NotificationItem` component that renders identically in the dropdown and on the full page. This eliminates duplication and enforces consistent visual language across all surfaces.

---

## Scope

| Surface | POS (`~/sunbites-pos`) | Portal (`~/sunbites-portal`) |
|---|---|---|
| Bell dropdown | Redesign | Redesign |
| Notifications full page | Redesign | Redesign |
| Announcements page | Redesign | Not applicable |
| Announcement detail page (`/announcements/[id]`) | Keep as-is | Not applicable |

---

## Architecture

### New files

```
components/ui/popover.tsx          (POS)     ← shadcn Popover primitive
components/ui/popover.tsx          (Portal)  ← shadcn Popover primitive
components/notification-item.tsx   (POS)     ← shared notification row
components/notification-item.tsx   (Portal)  ← shared notification row
```

### Modified / rewritten files

```
components/notification-bell.tsx   (POS)     ← rewrite: Popover + inline panel
components/notification-bell.tsx   (Portal)  ← rewrite: Popover + inline panel
app/(kitchen)/notifications/page.tsx  (POS)  ← rewrite: uses NotificationItem
app/(portal)/notifications/page.tsx  (Portal)← rewrite: uses NotificationItem
app/(kitchen)/announcements/page.tsx  (POS)  ← rewrite: card-based layout
```

### Unchanged files

```
app/(kitchen)/announcements/[id]/page.tsx  (POS)   ← keep as-is
lib/api/staff-notifications.ts             (POS)   ← keep as-is
lib/api/notifications.ts                   (Portal)← keep as-is
types/staff-notification.ts                (POS)   ← keep as-is
types/notification.ts                      (Portal)← keep as-is
components/providers/echo-provider.tsx     (both)  ← keep as-is
```

---

## Component 1: `components/ui/popover.tsx`

Standard shadcn Popover built on `@radix-ui/react-popover`, which is already installed in both apps as a transitive dependency. No new packages required.

```tsx
"use client";

import * as PopoverPrimitive from "@radix-ui/react-popover";
import { cn } from "@/lib/utils";

const Popover = PopoverPrimitive.Root;
const PopoverTrigger = PopoverPrimitive.Trigger;

const PopoverContent = React.forwardRef<
  React.ElementRef<typeof PopoverPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>
>(({ className, align = "center", sideOffset = 4, ...props }, ref) => (
  <PopoverPrimitive.Portal>
    <PopoverPrimitive.Content
      ref={ref}
      align={align}
      sideOffset={sideOffset}
      className={cn(
        "z-50 rounded-xl border bg-popover text-popover-foreground shadow-xl outline-none",
        "data-[state=open]:animate-in data-[state=closed]:animate-out",
        "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
        "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        "data-[side=bottom]:slide-in-from-top-2",
        className
      )}
      {...props}
    />
  </PopoverPrimitive.Portal>
));
PopoverContent.displayName = PopoverPrimitive.Content.displayName;

export { Popover, PopoverTrigger, PopoverContent };
```

---

## Component 2: `components/notification-item.tsx`

The core shared component. Used in both the bell dropdown panel and the full notifications page. Accepts a generic `NotificationItem` shape so both apps can use their own typed notifications.

### Props interface (POS version)

```tsx
interface NotificationItemProps {
  notification: StaffNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
  isMarkingRead: boolean;
  isDeleting: boolean;
  onNavigate?: () => void; // called after navigation (e.g. close the popover)
}
```

### Props interface (Portal version)

```tsx
interface NotificationItemProps {
  notification: ParentNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
  isMarkingRead: boolean;
  isDeleting: boolean;
  onNavigate?: () => void;
}
```

### Visual anatomy

```
┌──────────────────────────────────────────────────────────────┐
│  ●  [icon]  Title                              timestamp ···  │
│             Preview line 1                                    │
│             Preview line 2 (clamped)                          │
└──────────────────────────────────────────────────────────────┘
```

**Left column — unread dot:**
- 6px filled circle (`w-1.5 h-1.5 rounded-full bg-primary`) when `read_at === null`
- Transparent (`bg-transparent`) when read
- Always present to maintain alignment

**Type icon circle (32px):**
- `w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0`
- POS `AnnouncementNotification` → `Megaphone` icon, `bg-amber-100 text-amber-600`
- POS `PreRegistrationNotification` → `UserPlus` icon, `bg-blue-100 text-blue-600`
- Portal `AnnouncementNotification` → `Megaphone` icon, `bg-amber-100 text-amber-600`
- Portal `PaymentReminderNotification` → `CreditCard` icon, `bg-red-100 text-red-600`

**Content area:**
- Title: `text-sm font-semibold` when unread; `text-sm font-medium text-muted-foreground` when read
- Preview: `text-xs text-muted-foreground line-clamp-2 mt-0.5`

**Timestamp:**
- `text-xs text-muted-foreground` top-right
- Format via existing `relativeTime()` utility: `15m`, `2h`, `3d`, `Jun 10`

**Row container:**
- Unread: `bg-primary/5`
- Read: default background
- `hover:bg-muted/50 cursor-pointer transition-colors`
- Padding: `px-4 py-3`

**`···` menu (MoreHorizontal):**
- Visible only on row hover (`group-hover:opacity-100 opacity-0 transition-opacity`)
- Uses existing `DropdownMenu` component (already in both apps)
- Items: "Mark as read" (hidden when already read) and "Delete"

### Click behaviour by notification type

**POS:**
| Type | Action |
|---|---|
| `AnnouncementNotification` | `onMarkRead(id)` optimistic → `router.push("/announcements/{data.announcement_id}")` → `onNavigate?.()` |
| `PreRegistrationNotification` | `onMarkRead(id)` optimistic → `router.push("/pre-registrations/{data.pre_registration_id}")` → `onNavigate?.()` |

**Portal:**
| Type | Action |
|---|---|
| `AnnouncementNotification` | `onMarkRead(id)` optimistic → expand inline accordion (panel stays open, `onNavigate` not called) |
| `PaymentReminderNotification` | `onMarkRead(id)` optimistic → `router.push("/payments")` → `onNavigate?.()` |

---

## Component 3: `components/notification-bell.tsx` (rewrite, both apps)

Converts from a navigation button to a self-contained `<Popover>`.

```tsx
export function NotificationBell({ className }: Props) {
  const [open, setOpen] = useState(false);

  // unread count query (unchanged query key)
  // Echo listeners — now invalidate BOTH count AND list queries

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button aria-label="...">
          <Bell />
          {unreadCount > 0 && <Badge count={unreadCount} />}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-[380px] p-0">
        <NotificationPanel onClose={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  );
}
```

### `NotificationPanel` (internal, inside `notification-bell.tsx`)

Rendered inside the `PopoverContent`. Not exported.

**Header:**
```
Notifications                    [✓✓]
```
- "Notifications" in `text-sm font-semibold`
- `CheckCheck` icon button on the right, `aria-label="Mark all as read"`, only shown when `unreadCount > 0`
- Padding: `px-4 py-3`, `border-b`

**List area:**
- `max-h-[420px] overflow-y-auto`
- Renders `NotificationItem` rows
- Date section dividers between Today/Yesterday/Earlier groups:
  - `text-[10px] font-bold uppercase tracking-widest text-muted-foreground/50 px-4 py-1.5`
- No pagination — shows most recent 20 notifications (`per_page: 20`)

**Empty state:**
```
     🔔
You're all caught up
No new notifications
```
- `Bell` icon `h-8 w-8 text-muted-foreground mb-2`
- `text-sm font-medium` + `text-xs text-muted-foreground`
- Centered, `py-10`

**Footer:**
```
         View all notifications →
```
- `text-xs text-primary hover:underline` centered link to `/notifications`
- `border-t px-4 py-2.5`
- Always visible

### Echo listener update (both apps)

**POS — current:** invalidates `["staff-unread-count"]` only
**POS — new:** invalidates `["staff-unread-count"]` AND `["staff-notifications"]`

**Portal — current:** invalidates `["unread-count"]` only
**Portal — new:** invalidates `["unread-count"]` AND `["notifications"]`

This ensures the open panel list refreshes automatically when a broadcast arrives.

---

## Surface: Notifications Full Page (both apps)

### Query keys

**POS:** `["staff-notifications"]` — `staffNotificationApi.list({ per_page: 50 })`
**Portal:** `["notifications"]` — `notificationApi.list({ per_page: 50 })`

### Layout

```
Notifications
─────────────────────────────────────────────────────────
[All]  [Unread •3]               [✓✓ Mark all]  [🗑 Clear all]
─────────────────────────────────────────────────────────
TODAY ────────────────────────────────────────────────────
  <NotificationItem ... />
  <NotificationItem ... />

YESTERDAY ───────────────────────────────────────────────
  <NotificationItem ... />

EARLIER ─────────────────────────────────────────────────
  <NotificationItem ... />
```

**Header:** `h1 text-xl font-bold` + action buttons row

**Tabs:**
- Two tabs: "All" and "Unread"
- "Unread" tab shows a `bg-destructive text-destructive-foreground` pill with count when `unreadCount > 0`
- Tab underline indicator: `border-b-2 border-primary` on active tab

**Date section labels:**
- `text-xs font-bold uppercase tracking-wider text-muted-foreground/60 px-2 mb-1`
- Thin `<Separator />` before each group label

**"Mark all read" button:** outline, `sm` size, `CheckCheck` icon — only shown when `unreadCount > 0`

**"Clear all" button:** outline, `sm` size, `Trash2` icon — only shown when `notifications.length > 0`; triggers existing `AlertDialog` confirmation

**Empty state:**
```
     🔔
You're all caught up
```
- `Bell h-10 w-10` + `text-sm font-medium text-muted-foreground`
- `py-16` centered

**Loading state:** existing `NotificationSkeleton` component kept as-is

**`onNavigate` prop for `NotificationItem`:** on the full page, `onNavigate` is not needed (there is no panel to close) — pass `undefined`.

---

## Surface: Announcements Page (POS only)

Route: `app/(kitchen)/announcements/page.tsx`

### Layout

```
Announcements                          [+ New Announcement]
────────────────────────────────────────────────────────────
TODAY ──────────────────────────────────────────────────────
┌──────────────────────────────────────────────────────────┐
│  [📣]  tesst                                        1h   │
│        asdsadasdas                                       │
│        [Parents]  Jhersonn Cayao · 1 sent · 1 read       │
└──────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────┐
│  [📣]  test 10                                      2h   │
│        qwewqewqewqe                                      │
│        [Parents]  Jhersonn Cayao · 1 sent · 0 read       │
└──────────────────────────────────────────────────────────┘

EARLIER ────────────────────────────────────────────────────
┌──────────────────────────────────────────────────────────┐
│  [📣]  this is second                            Jun 12  │
│        test. 3                                           │
│        [Parents]  Jhersonn Cayao · 1 sent · 0 read       │
└──────────────────────────────────────────────────────────┘
```

### Announcement card anatomy

Each announcement is a `<Card>` wrapping a clickable `<div>` that navigates to `/announcements/{id}`.

**Left: Icon circle (32px)**
- `Megaphone` icon, `bg-amber-100 text-amber-600 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0`

**Content:**
- Title: `text-sm font-semibold`
- Preview: `text-xs text-muted-foreground line-clamp-2 mt-0.5`

**Top-right: Timestamp**
- `text-xs text-muted-foreground`
- Relative format via `relativeTime()`

**Bottom meta row:**
- Audience badge: `[Parents]` / `[Staff]` / `[All]` — small badge using existing `Badge` component variant `secondary`
- Sender: `text-xs text-muted-foreground`
- Stat: `· {sent_count} sent · {read_count} read`
  - "0 read" stays `text-muted-foreground`; non-zero read count uses default foreground

**Card styles:**
- `hover:bg-muted/50 cursor-pointer transition-colors`
- `border rounded-lg p-4`

**Date grouping:**
- Two groups: "Today" and "Earlier" (no "Yesterday" needed for announcements — simpler)
- Same thin section label style as notifications page

**"+ New Announcement" button:** kept exactly as-is in position (top-right of page header)

**Empty state:**
```
     📣
No announcements yet
```
- Centered, `py-16`

**Loading state:** skeleton rows matching card height

---

## Data Flow Summary

```
Bell clicked
  → Popover opens
  → useQuery(["staff-notifications"], { per_page: 20 }) fetches (or returns cache)
  → NotificationPanel renders NotificationItem rows

NotificationItem clicked (POS — Announcement)
  → optimistic markRead in cache
  → PATCH /staff/notifications/{id}/read
  → router.push("/announcements/{announcement_id}")
  → onNavigate() → setOpen(false) closes popover

NotificationItem clicked (POS — Pre-Registration)
  → optimistic markRead in cache
  → PATCH /staff/notifications/{id}/read
  → router.push("/pre-registrations/{pre_registration_id}")
  → onNavigate() → setOpen(false) closes popover

NotificationItem clicked (Portal — Announcement)
  → optimistic markRead in cache
  → PATCH /portal/notifications/{id}/read
  → inline accordion expands (panel stays open)

NotificationItem clicked (Portal — Payment Reminder)
  → optimistic markRead in cache
  → PATCH /portal/notifications/{id}/read
  → router.push("/payments")
  → onNavigate() → setOpen(false) closes popover

Echo broadcast arrives
  → invalidate ["staff-unread-count"] + ["staff-notifications"]  (POS)
  → invalidate ["unread-count"] + ["notifications"]              (Portal)
  → badge updates; panel list refreshes if open

Mark all read (dropdown header or full page button)
  → POST /staff/notifications/mark-all-read  (POS)
  → POST /portal/notifications/mark-all-read  (Portal)
  → invalidate both count + list queries
  → badge clears, all rows lose unread styling

Delete notification (··· menu)
  → DELETE /staff/notifications/{id}  (POS)
  → optimistic remove from list cache
  → toast.success("Notification deleted")

Clear all (full page only)
  → AlertDialog confirmation
  → DELETE /staff/notifications  (POS) — existing endpoint
  → invalidate both queries
  → toast.success("All notifications cleared")
```

---

## Optimistic Update Strategy

All mark-read and delete mutations use optimistic updates via `queryClient.setQueryData()` before the API call fires. On error, roll back via `onError` with the previous snapshot. This matches the pattern already used in the existing notifications page.

---

## Testing

### POS `notification-bell.tsx`

- Click bell → dropdown opens (assert `PopoverContent` is visible, NOT `router.push` to `/notifications`)
- Unread badge renders correct count
- Badge disappears when `unreadCount` is 0
- `CheckCheck` button only appears when `unreadCount > 0`
- Click outside → dropdown closes
- Mark all read → badge clears, all rows lose `bg-primary/5` tint
- Click announcement row → navigates to `/announcements/{id}`, dropdown closes
- Click pre-registration row → navigates to `/pre-registrations/{id}`, dropdown closes
- Echo broadcast → unread count increments without page reload
- "View all notifications →" footer link renders with correct href

### POS notifications page

- All `NotificationItem` rows render correctly for both announcement and pre-registration types
- Unread tab filters to unread only; count badge matches
- Date grouping shows Today / Yesterday / Earlier correctly
- Mark all read clears badge and unread styling
- Clear all triggers confirmation dialog; on confirm, list empties
- Empty state shown when no notifications
- Skeleton shown during loading

### POS announcements page

- Cards render with title, preview, audience badge, sender, sent/read counts
- Click card navigates to `/announcements/{id}`
- Date grouping shows Today / Earlier
- Empty state shown when no announcements
- Skeleton shown during loading

### Portal `notification-bell.tsx`

- Same bell/dropdown tests as POS
- Click announcement row → inline accordion expands, panel stays open
- Click payment reminder row → navigates to `/payments`, dropdown closes

### Portal notifications page

- All `NotificationItem` rows render correctly for announcement and payment reminder types
- Same tab, grouping, mark-all-read tests as POS

---

## Files Summary

| File | App | Action |
|---|---|---|
| `components/ui/popover.tsx` | POS | Create |
| `components/ui/popover.tsx` | Portal | Create |
| `components/notification-item.tsx` | POS | Create |
| `components/notification-item.tsx` | Portal | Create |
| `components/notification-bell.tsx` | POS | Rewrite |
| `components/notification-bell.tsx` | Portal | Rewrite |
| `app/(kitchen)/notifications/page.tsx` | POS | Rewrite |
| `app/(portal)/notifications/page.tsx` | Portal | Rewrite |
| `app/(kitchen)/announcements/page.tsx` | POS | Rewrite |
| `app/(kitchen)/announcements/[id]/page.tsx` | POS | No change |
| `lib/api/staff-notifications.ts` | POS | No change |
| `lib/api/notifications.ts` | Portal | No change |
| `types/staff-notification.ts` | POS | No change |
