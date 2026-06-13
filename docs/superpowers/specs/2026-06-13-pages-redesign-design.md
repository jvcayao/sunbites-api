# Design: Notifications & Announcements Pages Redesign

**Date:** 2026-06-13
**Branch:** `feat/parents-portal-update`
**Apps affected:**
- `~/sunbites-portal` — `/notifications` page
- `~/sunbites-pos` — `/notifications` page
- `~/sunbites-pos` — `/announcements` page

---

## Overview

Redesign three existing pages to use a clean **Grouped Inbox** layout. The current designs use flat border cards with inconsistent spacing and no date grouping. The redesign unifies all three pages under the same visual pattern: date-grouped rows, a left unread dot, relative timestamps top-right, and subtle hover states.

No backend changes are required. All data shapes, API endpoints, and routing logic remain identical to the current implementation.

---

## Shared Design Pattern — Grouped Inbox Row

Every item across all three pages uses this row structure:

```
[dot]  [title bold/normal]                    [timestamp]
       [2-line preview, muted]
```

| Element | Unread | Read |
|---|---|---|
| Left dot | `bg-primary` 7×7px circle | transparent (invisible) |
| Row background | `bg-primary/5` | default (no tint) |
| Title | `font-semibold text-foreground` | `text-muted-foreground` |
| Preview | `text-muted-foreground` 2-line clamp | `text-muted-foreground/70` 2-line clamp |
| Timestamp | `text-muted-foreground` top-right | same |

**Date group headers** — `text-xs font-bold uppercase tracking-wider text-muted-foreground/60` — appear above each group:
- Today
- Yesterday
- Earlier (for anything older than 2 days)

**Hover state** — `hover:bg-muted/30` on every row.

**Context menu (`⋯`)** — visible on row hover only (opacity-0 → opacity-100). Positioned top-right inside the row. Contains: Mark as read / Delete.

---

## Portal `/notifications` Page

**File:** `~/sunbites-portal/app/(portal)/notifications/page.tsx`

### Header

```
Notifications                     [Mark all read]  [Clear all]
```

- "Clear all" triggers an AlertDialog confirmation before `clearAll` mutation fires.
- "Mark all read" calls `markAllRead` mutation immediately (no confirmation).

### Tabs

```
[All]  [Unread ●2]
```

- Two tabs: **All** and **Unread**.
- Unread tab shows a red count badge (`bg-destructive text-destructive-foreground`) when count > 0.
- Tab state is local `useState` — no URL param, no persistence.
- "All" tab shows every notification grouped by date.
- "Unread" tab shows only `read_at === null` items, same grouped layout.

### Notification Rows

Notification type is determined by the discriminated union on the `type` FQCN field (existing `types/notification.ts`).

| Type | Title | Preview |
|---|---|---|
| `PaymentReminderNotification` | `"Payment Due — {month}"` | `"Your child's payment for {month} is due in {N} days."` |
| `AnnouncementNotification` | `data.title` | `data.message` truncated |

**Click behaviour — unchanged from current:**
- `PaymentReminderNotification` → optimistic mark read + `router.push("/payments")` 
- `AnnouncementNotification` → optimistic mark read + toggle inline accordion below the row

**Inline accordion** (Announcement only): slides open below the row, showing the full `data.message`. A second click collapses it. Uses CSS `max-height` transition (no Radix required).

### Empty State

When the filtered list is empty:

```
🔔
You're all caught up
No new notifications right now.
```

Bell icon (`lucide-react Bell`), `text-muted-foreground` text, centered vertically in the remaining space.

---

## POS `/notifications` Page

**File:** `~/sunbites-pos/app/(kitchen)/notifications/page.tsx`

Identical layout to the portal notifications page with two differences:

1. **Notification types** — discriminated union on `types/staff-notification.ts`:

| Type | Title | Preview |
|---|---|---|
| `AnnouncementNotification` | `data.title` | `data.message` truncated |
| `PreRegistrationNotification` | `"New Pre-Registration"` | `"Submitted for {data.student_name} ({data.grade_level})."` |

2. **Click behaviour:**

| Type | Action |
|---|---|
| `AnnouncementNotification` | optimistic mark read + `router.push(`/announcements/${data.announcement_id}`)` |
| `PreRegistrationNotification` | optimistic mark read + `router.push(`/pre-registrations/${data.pre_registration_id}`)` |

3. **Context menu (`⋯`)** — uses the existing Base UI `DropdownMenuTrigger` with `render` prop pattern already established in this app:

```tsx
<DropdownMenuTrigger
  render={<Button variant="ghost" size="icon" className="h-7 w-7 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100" />}
  onClick={(e: React.MouseEvent) => e.stopPropagation()}
>
```

The row wrapper gets `className="group relative flex ..."` to enable `group-hover:opacity-100`.

---

## POS `/announcements` Page

**File:** `~/sunbites-pos/app/(kitchen)/announcements/page.tsx`

### Header

```
Announcements                                  [+ New Announcement]
```

### No Tabs

The announcements page is a **management view** (staff see what was sent, not a personal inbox). No All/Unread tabs. No unread dot. Simple date-grouped list.

### Announcement Rows

```
[title bold]                                   [relative timestamp]
[2-line message preview, muted]
[badge]  by {sender_name} · {sent_count} sent · {read_count} read
```

| Element | Value |
|---|---|
| Badge — Parents | `bg-purple-950/60 text-purple-300 border border-purple-800/40` |
| Badge — Staff | `bg-blue-950/60 text-blue-300 border border-blue-800/40` |
| Stats line | `text-xs text-muted-foreground` |
| Timestamp | `text-xs text-muted-foreground` top-right, relative format |

**Click behaviour — unchanged:** `router.push(`/announcements/${id}`)`.

### Empty State

```
📣
No announcements yet
Create your first announcement to notify parents or staff.
```

Megaphone icon (`lucide-react Megaphone`), centered.

---

## Shared Components (no new files)

Both pages already import from existing shared components. No new shared components are introduced. All changes are isolated to the three page files.

---

## Tab State

The All/Unread tab is `useState<"all" | "unread">` local to each page. The existing `useQuery` results are filtered client-side:

```ts
const displayed = activeTab === "unread"
  ? notifications.filter(n => n.read_at === null)
  : notifications;
```

Date grouping runs on `displayed` after the filter.

---

## Date Grouping Logic

Both apps have no date library. Use plain `Date` arithmetic:

```ts
function groupByDate<T extends { created_at: string }>(
  items: T[]
): { label: string; items: T[] }[] {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const startOfYesterday = new Date(startOfToday.getTime() - 86_400_000);

  return [
    { label: "Today",     items: items.filter(n => new Date(n.created_at) >= startOfToday) },
    { label: "Yesterday", items: items.filter(n => new Date(n.created_at) >= startOfYesterday && new Date(n.created_at) < startOfToday) },
    { label: "Earlier",   items: items.filter(n => new Date(n.created_at) < startOfYesterday) },
  ].filter(g => g.items.length > 0);
}
```

No new dependencies required. This helper is defined once per file (not extracted to a shared utility) to keep each page self-contained.

---

## Mutations (unchanged from current)

All mutations already exist in each app's API service layer. The redesign only changes how rows are rendered — no new endpoints, no new mutations, no new query keys.

| Mutation | Endpoint | Trigger |
|---|---|---|
| Mark read | `PATCH /notifications/{id}/read` | row click (portal) or ⋯ menu |
| Mark all read | `POST /notifications/read-all` | header button |
| Delete | `DELETE /notifications/{id}` | ⋯ menu |
| Clear all | `DELETE /notifications` | header button (after AlertDialog confirm) |

POS equivalents use `/staff-notifications/` prefix — already wired.

---

## Error & Loading States

- **Loading:** existing `loading.tsx` skeleton files are unchanged — they already show skeleton rows.
- **Error:** existing `error.tsx` boundary files handle fetch failures — unchanged.
- **Empty:** new inline empty state per page (described above), shown when the filtered list has zero items.

---

## Files Changed

| File | App | Change |
|---|---|---|
| `app/(portal)/notifications/page.tsx` | Portal | Rewrite — Grouped Inbox + tabs + accordion |
| `app/(kitchen)/notifications/page.tsx` | POS | Rewrite — Grouped Inbox + tabs + Base UI ⋯ menu |
| `app/(kitchen)/announcements/page.tsx` | POS | Rewrite — Grouped Inbox rows (no tabs, management view) |

No other files change. `loading.tsx`, `error.tsx`, API service files, type files, and layout files are all untouched.

---

## Testing

**Portal notifications page:**
- Renders date groups (Today, Yesterday, Earlier) correctly
- Unread tab filters to only unread items; badge count matches
- Payment row click navigates to `/payments`
- Announcement row click expands accordion; second click collapses
- Mark all read clears unread dot and tint from all rows
- Empty state renders when no notifications

**POS notifications page:**
- Pre-registration row click navigates to `/pre-registrations/{id}`
- Announcement row click navigates to `/announcements/{id}`
- Unread tab filter works
- ⋯ menu renders on hover; Mark as read and Delete fire correct mutations

**POS announcements page:**
- Rows render with correct badge color per recipient type
- Stats line shows correct sent/read counts
- Date groups render correctly
- Empty state renders when no announcements
- Click navigates to `/announcements/{id}`
