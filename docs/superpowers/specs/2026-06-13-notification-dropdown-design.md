# Design: Notification Bell Dropdown

**Date:** 2026-06-13
**Branch:** `feat/parents-portal-update`
**Apps affected:** `~/sunbites-portal` (parent portal), `~/sunbites-pos` (POS/admin)

---

## Overview

Replace the current notification bell behaviour — which navigates to a full `/notifications` page — with an inline **dropdown panel** anchored directly below the bell icon. The panel matches the MagicBell reference design approved by the user.

The `/notifications` page is **kept** as a "View all" destination reachable from a footer link inside the dropdown. The bell no longer navigates to it on click.

---

## User-Facing Behaviour

### Bell button
- Displays `Bell` icon with a red unread-count badge (`bg-destructive`)
- Clicking opens a Popover panel anchored below-right of the button
- Clicking again, or clicking outside, closes the panel
- Badge disappears when unread count reaches 0
- Real-time: badge increments immediately when a broadcast arrives (Echo listeners unchanged)

### Dropdown panel
- Width: 380px, positioned `align="end"` with `sideOffset={8}`
- **Header row:** "Notifications" label + mark-all-read icon button (`CheckCheck`)
- **Notification list:** `max-h-[420px]`, `overflow-y-auto`, scrollable
- **Footer:** "View all notifications →" link to `/notifications` page

### Notification row
| Element | Detail |
|---|---|
| Unread dot (left) | `bg-primary` when `read_at === null`; transparent when read |
| Title | Bold (`font-semibold`) when unread; normal when read |
| Preview | 2-line clamp, `text-muted-foreground` |
| Timestamp | Top-right, relative format (`15m`, `2h`, `3d`, `Jun 10`) |
| `...` menu | Visible on row hover only; "Mark as read" + "Delete" |

Unread rows have a `bg-primary/5` background tint.

### Click behaviour by type

**Portal (`ParentNotification`):**

| Type | Action |
|---|---|
| `PaymentReminderNotification` | Mark read (optimistic) + `router.push("/payments")` + close panel |
| `AnnouncementNotification` | Mark read (optimistic) + expand inline accordion (panel stays open) |

**POS (`StaffNotification`):**

| Type | Action |
|---|---|
| `AnnouncementNotification` | Mark read (optimistic) + `router.push("/announcements/{data.announcement_id}")` + close panel |
| `PreRegistrationNotification` | Mark read (optimistic) + `router.push("/pre-registrations/{data.pre_registration_id}")` + close panel |

### Empty state
When the list is empty: `Bell` illustration + "You're all caught up" + "No new notifications right now."

---

## Architecture

### New files

```
components/ui/popover.tsx    (both apps)   ← shadcn Popover primitive
```

`popover.tsx` is a standard shadcn component built on `@radix-ui/react-popover`, which is already installed as a transitive dependency in both apps. No new packages required.

### Modified files

```
components/notification-bell.tsx    (both apps)
```

The bell component is redesigned from a navigation button into a self-contained `<Popover>` with:
- `<PopoverTrigger asChild>` — wraps the existing bell button
- `<PopoverContent>` — contains the full notification panel

All notification list logic (TanStack Query fetch, mark-read mutation, delete mutation, mark-all-read mutation, type-aware rendering) moves **inside** `notification-bell.tsx`. The component becomes larger but fully self-contained — no props required at call sites.

### Unchanged files

```
app/(portal)/notifications/page.tsx    (portal)   ← stays as "View all" page
app/(kitchen)/notifications/page.tsx   (POS)      ← stays as "View all" page
```

Both pages keep their existing implementation. The only change is that the bell no longer `router.push()`s to them on click.

---

## Component Structure (both apps)

```tsx
// components/notification-bell.tsx

export function NotificationBell() {
  const [open, setOpen] = useState(false);

  // unread count query (unchanged)
  // Echo listeners (unchanged) — also invalidate "notifications" query

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button aria-label="...">
          <Bell /> <Badge count={unreadCount} />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-[380px] p-0 shadow-xl">
        <NotificationPanel onClose={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  );
}

function NotificationPanel({ onClose }) {
  // notifications list query
  // markRead / markAllRead / delete mutations
  // type-aware rendering (same logic as current page)
  // ...
}
```

### Echo listeners addition
When a notification arrives via broadcast, both `["unread-count"]` **and** `["notifications"]` queries are invalidated, so the open panel list refreshes automatically without a manual reload.

Portal bell currently only invalidates `["unread-count"]` — the `["notifications"]` invalidation is added here.
POS bell currently only invalidates `["staff-unread-count"]` — the `["staff-notifications"]` invalidation is added here.

---

## Data Flow

```
User clicks bell
  → Popover opens
  → useQuery(["notifications"]) fetches list (or returns cache)
  → Rows render with type-aware title/preview

User clicks a row (portal — Payment Reminder)
  → optimistic: mark read in cache
  → PATCH /portal/notifications/{id}/read
  → router.push("/payments")
  → panel closes

User clicks a row (portal — Announcement)
  → optimistic: mark read in cache
  → PATCH /portal/notifications/{id}/read
  → accordion expands inline (panel stays open)

New broadcast arrives (panel open or closed)
  → Echo listener fires
  → invalidate ["unread-count"] + ["notifications"]
  → badge updates; list refreshes if panel is open
```

---

## Popover Implementation

Use `@radix-ui/react-popover` via the shadcn `popover.tsx` component.

```tsx
// components/ui/popover.tsx (standard shadcn)
"use client";
import * as PopoverPrimitive from "@radix-ui/react-popover";
import { cn } from "@/lib/utils";

const Popover = PopoverPrimitive.Root;
const PopoverTrigger = PopoverPrimitive.Trigger;

const PopoverContent = React.forwardRef<...>(
  ({ className, align = "center", sideOffset = 4, ...props }, ref) => (
    <PopoverPrimitive.Portal>
      <PopoverPrimitive.Content
        ref={ref}
        align={align}
        sideOffset={sideOffset}
        className={cn(
          "z-50 w-72 rounded-xl border bg-popover text-popover-foreground shadow-xl outline-none",
          "data-[state=open]:animate-in data-[state=closed]:animate-out",
          "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
          "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
          "data-[side=bottom]:slide-in-from-top-2",
          className
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  )
);
```

---

## Security Considerations

- No new API endpoints or auth changes — all existing notification routes are already authenticated
- The dropdown renders in a Radix Portal (`PopoverPrimitive.Portal`), so it sits above all other UI elements without z-index conflicts
- Optimistic mark-as-read is UI-only; `read_at` is persisted by the existing backend endpoint

---

## Testing

**Portal `notification-bell.tsx` tests — update:**
- Click bell → assert dropdown opens (not `router.push` to `/notifications`)
- Assert unread badge count renders correctly
- Assert `markAllRead` clears the badge
- Assert closing panel on outside click

**POS `notification-bell.tsx` tests — update:**
- Same as portal

**Existing `/notifications` page tests — no changes required.**

---

## Files Summary

| File | App | Action |
|---|---|---|
| `components/ui/popover.tsx` | Portal | Create |
| `components/ui/popover.tsx` | POS | Create |
| `components/notification-bell.tsx` | Portal | Rewrite — Popover + inline panel |
| `components/notification-bell.tsx` | POS | Rewrite — Popover + inline panel |
| `app/(portal)/notifications/page.tsx` | Portal | No change |
| `app/(kitchen)/notifications/page.tsx` | POS | No change |
