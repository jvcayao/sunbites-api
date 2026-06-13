# Unified Notification Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the "announcement shows as Payment Reminder" bug, remove the duplicate bell from the POS header, and redesign both notification list pages + POS announcements pages to match the approved MagicBell reference design.

**Architecture:** All changes are frontend-only — the Laravel backend and all API endpoints are complete. Two Next.js apps are modified in sequence: `~/sunbites-portal` first (highest user-visible impact), then `~/sunbites-pos`. A shared `relativeTime()` utility is added to each app. TypeScript discriminated unions on the Laravel FQCN `type` field replace the current single-type definitions.

**Tech Stack:** Next.js 15 App Router, React 19, TanStack Query, TypeScript, Tailwind v4, shadcn/ui (base-ui DropdownMenu), Jest + MSW 2 + React Testing Library

---

## File Map

| File | App | Action |
|---|---|---|
| `types/notification.ts` | Portal | Modify — add `AnnouncementData`, replace `ParentNotification` with discriminated union |
| `components/notification-bell.tsx` | Portal | Modify — add `AnnouncementNotification` listener |
| `lib/relative-time.ts` | Portal | Create — `relativeTime(iso: string): string` |
| `app/(portal)/notifications/page.tsx` | Portal | Modify — MagicBell redesign, type-aware cards, click routing, `...` menu |
| `app/(portal)/notifications/notifications.test.tsx` | Portal | Create — 6 tests |
| `components/layouts/kitchen-layout.tsx` | POS | Modify — remove `<ReminderBell />` from header |
| `types/staff-notification.ts` | POS | Modify — add `PreRegistrationData`, replace `StaffNotification` with discriminated union |
| `components/notification-bell.tsx` | POS | Modify — add `PreRegistrationNotification` listener |
| `lib/relative-time.ts` | POS | Create — same `relativeTime()` utility |
| `app/(kitchen)/notifications/page.tsx` | POS | Modify — MagicBell redesign, type-aware cards, click routing |
| `app/(kitchen)/notifications/notifications.test.tsx` | POS | Create — 5 tests |
| `app/(kitchen)/announcements/page.tsx` | POS | Modify — fix badge colors, add relative timestamps |
| `app/(kitchen)/announcements/create/page.tsx` | POS | Modify — pill toggle for Send to, character count on textarea |
| `app/(kitchen)/announcements/[id]/page.tsx` | POS | Modify — add read summary, relative time in Read At column |

---

## Task 1: Portal — Discriminated union notification types

**Spec:** Spec 10 Task 8.1

**Files:**
- Modify: `~/sunbites-portal/types/notification.ts`

- [ ] **Step 1.1: Replace the flat `ParentNotification` type with a discriminated union**

Open `~/sunbites-portal/types/notification.ts` and replace the entire file content with:

```typescript
export interface PaymentReminderData {
  school_month: string;
  school_year: number;
  due_date: string;
  students: Array<{ name: string; amount: number }>;
  total_amount: number;
}

export interface AnnouncementData {
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

export interface NotificationListResponse {
  data: ParentNotification[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface PaymentHistoryEntry {
  id: number;
  school_month: string;
  year: number;
  amount: number;
  status: "paid" | "unpaid";
  paid_at: string | null;
}
```

- [ ] **Step 1.2: Verify TypeScript compiles without errors**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors. If errors appear they will be in `notifications/page.tsx` (because it still accesses `data.school_month` without type narrowing) — that's expected and will be fixed in Task 4.

- [ ] **Step 1.3: Commit**

```bash
cd ~/sunbites-portal
git add types/notification.ts
git commit -m "feat(portal): discriminated union for ParentNotification type"
```

---

## Task 2: Portal — Bell subscribes to AnnouncementNotification

**Spec:** Spec 10 Task 8.2

**Files:**
- Modify: `~/sunbites-portal/components/notification-bell.tsx`

- [ ] **Step 2.1: Add AnnouncementNotification listener**

In `~/sunbites-portal/components/notification-bell.tsx`, replace the `useEffect` block (lines 26–38):

```typescript
  useEffect(() => {
    if (!echo || !parent) return;

    const channel = echo
      .private(`parents.${parent.id}`)
      .listen("PaymentReminderNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["unread-count"] });
      })
      .listen("AnnouncementNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["unread-count"] });
      });

    return () => {
      channel.stopListening("PaymentReminderNotification");
      channel.stopListening("AnnouncementNotification");
    };
  }, [echo, parent, queryClient]);
```

- [ ] **Step 2.2: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -20
```

Expected: same errors as before (only from `notifications/page.tsx`) — no new errors.

- [ ] **Step 2.3: Commit**

```bash
git add components/notification-bell.tsx
git commit -m "feat(portal): bell subscribes to AnnouncementNotification events"
```

---

## Task 3: Portal — Relative time utility

**Spec:** Spec 10 Task 8.3

**Files:**
- Create: `~/sunbites-portal/lib/relative-time.ts`

- [ ] **Step 3.1: Create the utility**

Create `~/sunbites-portal/lib/relative-time.ts`:

```typescript
export function relativeTime(isoString: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin}m`;
  if (diffHr < 24) return `${diffHr}h`;
  if (diffDay < 7) return `${diffDay}d`;

  return date.toLocaleDateString("en-PH", { month: "short", day: "numeric" });
}
```

- [ ] **Step 3.2: Write unit tests for the utility**

Create `~/sunbites-portal/lib/relative-time.test.ts`:

```typescript
import { relativeTime } from "./relative-time";

function isoSecondsAgo(seconds: number): string {
  return new Date(Date.now() - seconds * 1000).toISOString();
}

describe("relativeTime", () => {
  it('returns "just now" for timestamps under 60 seconds ago', () => {
    expect(relativeTime(isoSecondsAgo(30))).toBe("just now");
    expect(relativeTime(isoSecondsAgo(59))).toBe("just now");
  });

  it('returns "{N}m" for timestamps 1–59 minutes ago', () => {
    expect(relativeTime(isoSecondsAgo(60))).toBe("1m");
    expect(relativeTime(isoSecondsAgo(5 * 60))).toBe("5m");
    expect(relativeTime(isoSecondsAgo(59 * 60))).toBe("59m");
  });

  it('returns "{N}h" for timestamps 1–23 hours ago', () => {
    expect(relativeTime(isoSecondsAgo(3600))).toBe("1h");
    expect(relativeTime(isoSecondsAgo(23 * 3600))).toBe("23h");
  });

  it('returns "{N}d" for timestamps 1–6 days ago', () => {
    expect(relativeTime(isoSecondsAgo(86400))).toBe("1d");
    expect(relativeTime(isoSecondsAgo(6 * 86400))).toBe("6d");
  });

  it("returns a formatted date for timestamps 7+ days ago", () => {
    const sevenDaysAgo = new Date(Date.now() - 7 * 86400 * 1000);
    const result = relativeTime(sevenDaysAgo.toISOString());
    expect(result).toMatch(/^[A-Z][a-z]+ \d+$/);
  });
});
```

- [ ] **Step 3.3: Run the tests**

```bash
cd ~/sunbites-portal && npx jest lib/relative-time.test.ts --no-coverage
```

Expected: 5 tests pass.

- [ ] **Step 3.4: Commit**

```bash
git add lib/relative-time.ts lib/relative-time.test.ts
git commit -m "feat(portal): add relativeTime utility"
```

---

## Task 4: Portal — MagicBell notifications page redesign

**Spec:** Spec 10 Task 8.4

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/notifications/page.tsx`

- [ ] **Step 4.1: Replace the page with the MagicBell design**

Replace `~/sunbites-portal/app/(portal)/notifications/page.tsx` with:

```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, CheckCheck, MoreHorizontal } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { notificationApi } from "@/lib/api/notifications";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { ParentNotification } from "@/types/notification";

// ---------------------------------------------------------------------------
// Type-aware helpers
// ---------------------------------------------------------------------------

function getTitle(n: ParentNotification): string {
  if (n.type === "App\\Notifications\\PaymentReminderNotification") {
    const month = n.data.school_month
      ? n.data.school_month.charAt(0).toUpperCase() + n.data.school_month.slice(1)
      : "";
    return `Payment Reminder — ${month} ${n.data.school_year ?? ""}`.trim();
  }
  return n.data.title ?? "Announcement";
}

function getPreview(n: ParentNotification): string {
  if (n.type === "App\\Notifications\\PaymentReminderNotification") {
    const count = n.data.students?.length ?? 0;
    const total = n.data.total_amount
      ? `₱${Number(n.data.total_amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`
      : "";
    return `${count} student${count !== 1 ? "s" : ""}${total ? ` — ${total}` : ""}`;
  }
  const msg = n.data.message ?? "";
  return msg.length > 120 ? msg.slice(0, 120) + "…" : msg;
}

// ---------------------------------------------------------------------------
// NotificationCard
// ---------------------------------------------------------------------------

interface NotificationCardProps {
  notification: ParentNotification;
  isExpanded: boolean;
  onToggleExpand: () => void;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
}

function NotificationCard({
  notification,
  isExpanded,
  onToggleExpand,
  onMarkRead,
  onDelete,
}: NotificationCardProps) {
  const router = useRouter();
  const isUnread = notification.read_at === null;
  const title = getTitle(notification);
  const preview = getPreview(notification);

  function handleCardClick() {
    if (isUnread) {
      onMarkRead(notification.id);
    }
    if (notification.type === "App\\Notifications\\PaymentReminderNotification") {
      router.push("/payments");
    } else {
      onToggleExpand();
    }
  }

  return (
    <div
      className={cn(
        "rounded-lg border border-border bg-card transition-colors",
        isUnread && "border-primary/30 bg-primary/5"
      )}
    >
      <div
        role="article"
        aria-label={title}
        className="flex cursor-pointer items-start gap-3 p-4 hover:bg-muted/30 rounded-lg"
        onClick={handleCardClick}
      >
        <span
          aria-hidden="true"
          className={cn(
            "mt-1.5 h-2 w-2 shrink-0 rounded-full transition-colors",
            isUnread ? "bg-primary" : "bg-transparent"
          )}
        />
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className={cn("text-sm leading-snug", isUnread ? "font-semibold" : "font-medium")}>
            {title}
          </p>
          <p className="line-clamp-2 text-sm text-muted-foreground">{preview}</p>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          <span className="text-xs text-muted-foreground whitespace-nowrap">
            {relativeTime(notification.created_at)}
          </span>
          <DropdownMenu>
            <DropdownMenuTrigger
              render={
                <button
                  type="button"
                  aria-label="Notification options"
                  onClick={(e) => e.stopPropagation()}
                  className="flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                />
              }
            >
              <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {isUnread && (
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    onMarkRead(notification.id);
                  }}
                >
                  Mark as read
                </DropdownMenuItem>
              )}
              <DropdownMenuItem
                variant="destructive"
                onClick={(e) => {
                  e.stopPropagation();
                  onDelete(notification.id);
                }}
              >
                Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Accordion expansion — announcement only */}
      {notification.type === "App\\Notifications\\AnnouncementNotification" && isExpanded && (
        <div className="border-t border-border px-4 pb-4 pt-3 space-y-2">
          <p className="whitespace-pre-wrap text-sm">{notification.data.message}</p>
          <p className="text-xs text-muted-foreground">From: {notification.data.sender_name}</p>
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function NotificationsPage() {
  const queryClient = useQueryClient();
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [clearDialogOpen, setClearDialogOpen] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ["notifications"],
    queryFn: () => notificationApi.list(),
  });

  const { data: unreadData } = useQuery({
    queryKey: ["unread-count"],
    queryFn: () => notificationApi.unreadCount(),
  });

  const notifications = data?.data ?? [];
  const unreadCount = unreadData?.count ?? 0;

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
    queryClient.invalidateQueries({ queryKey: ["unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => notificationApi.markRead(id),
    onSuccess: invalidate,
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onSuccess: invalidate,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => notificationApi.destroy(id),
    onSuccess: invalidate,
  });

  const clearAllMutation = useMutation({
    mutationFn: () => notificationApi.clearAll(),
    onSuccess: () => {
      setClearDialogOpen(false);
      invalidate();
      toast.success("All notifications cleared.");
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-3 animate-pulse">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-20 rounded-lg bg-muted" />
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Notifications</h1>
        <div className="flex items-center gap-2">
          {unreadCount > 0 && (
            <button
              type="button"
              aria-label="Mark all as read"
              onClick={() => markAllReadMutation.mutate()}
              disabled={markAllReadMutation.isPending}
              className="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted disabled:opacity-50"
            >
              <CheckCheck className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
          {notifications.length > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setClearDialogOpen(true)}
            >
              Clear all
            </Button>
          )}
        </div>
      </div>

      {/* List */}
      {notifications.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border border-border py-16 text-center">
          <Bell className="mb-3 h-10 w-10 text-muted-foreground/40" aria-hidden="true" />
          <p className="font-medium text-sm">You&apos;re all caught up</p>
          <p className="mt-1 text-xs text-muted-foreground">No notifications right now.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {notifications.map((n) => (
            <NotificationCard
              key={n.id}
              notification={n}
              isExpanded={expandedId === n.id}
              onToggleExpand={() => setExpandedId(expandedId === n.id ? null : n.id)}
              onMarkRead={(id) => markReadMutation.mutate(id)}
              onDelete={(id) => deleteMutation.mutate(id)}
            />
          ))}
        </div>
      )}

      {/* Clear all dialog */}
      <AlertDialog open={clearDialogOpen} onOpenChange={setClearDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Clear all notifications?</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete all your notifications. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => clearAllMutation.mutate()}
              disabled={clearAllMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Clear all
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
```

- [ ] **Step 4.2: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -30
```

Expected: 0 errors.

- [ ] **Step 4.3: Run linter**

```bash
cd ~/sunbites-portal && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **Step 4.4: Commit**

```bash
git add app/\(portal\)/notifications/page.tsx
git commit -m "feat(portal): MagicBell notifications page redesign with type-aware cards"
```

---

## Task 5: Portal — Notification page tests

**Spec:** Spec 10 Task 8.5 + 8.6

**Files:**
- Create: `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx`

- [ ] **Step 5.1: Write the tests**

Create `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx`:

```tsx
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";

import NotificationsPage from "./page";

const mockPush = jest.fn();

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const paymentReminderFixture = {
  id: "notif-1",
  type: "App\\Notifications\\PaymentReminderNotification",
  data: {
    school_month: "august",
    school_year: 2026,
    due_date: "2026-08-01",
    students: [{ name: "Juan Santos", amount: 2430 }],
    total_amount: 2430,
  },
  read_at: null,
  created_at: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
};

const announcementFixture = {
  id: "notif-2",
  type: "App\\Notifications\\AnnouncementNotification",
  data: {
    announcement_id: 7,
    title: "Canteen closure notice",
    message: "The canteen will be closed on Friday due to maintenance.",
    sender_name: "Maria Santos",
    sent_at: new Date(Date.now() - 10 * 60 * 1000).toISOString(),
  },
  read_at: null,
  created_at: new Date(Date.now() - 10 * 60 * 1000).toISOString(),
};

beforeEach(() => {
  mockPush.mockClear();
  server.use(
    http.get(`${API}/portal/notifications/unread-count`, () =>
      HttpResponse.json({ count: 2 })
    ),
    http.get(`${API}/portal/notifications`, () =>
      HttpResponse.json({
        data: [paymentReminderFixture, announcementFixture],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
      })
    )
  );
});

describe("NotificationsPage", () => {
  it("renders a payment reminder with correct title and preview", async () => {
    render(<NotificationsPage />);

    expect(await screen.findByText("Payment Reminder — August 2026")).toBeInTheDocument();
    expect(screen.getByText(/1 student — ₱2,430/)).toBeInTheDocument();
  });

  it("renders an announcement with its title and message preview — not 'Payment reminder'", async () => {
    render(<NotificationsPage />);

    expect(await screen.findByText("Canteen closure notice")).toBeInTheDocument();
    expect(
      screen.getByText("The canteen will be closed on Friday due to maintenance.")
    ).toBeInTheDocument();
    expect(screen.queryByText(/Payment reminder —/)).not.toBeInTheDocument();
  });

  it("shows the empty state when there are no notifications", async () => {
    server.use(
      http.get(`${API}/portal/notifications`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } })
      )
    );

    render(<NotificationsPage />);

    expect(await screen.findByText("You're all caught up")).toBeInTheDocument();
  });

  it("clicking a payment reminder card navigates to /payments", async () => {
    server.use(
      http.patch(`${API}/portal/notifications/notif-1/read`, () =>
        HttpResponse.json({ message: "ok" })
      )
    );

    const user = userEvent.setup();
    render(<NotificationsPage />);

    const card = await screen.findByRole("article", { name: "Payment Reminder — August 2026" });
    await user.click(card);

    await waitFor(() => expect(mockPush).toHaveBeenCalledWith("/payments"));
  });

  it("clicking an announcement card expands its full message inline", async () => {
    server.use(
      http.patch(`${API}/portal/notifications/notif-2/read`, () =>
        HttpResponse.json({ message: "ok" })
      )
    );

    const user = userEvent.setup();
    render(<NotificationsPage />);

    const card = await screen.findByRole("article", { name: "Canteen closure notice" });
    await user.click(card);

    expect(
      await screen.findByText("The canteen will be closed on Friday due to maintenance.")
    ).toBeInTheDocument();
    expect(screen.getByText("From: Maria Santos")).toBeInTheDocument();
    expect(mockPush).not.toHaveBeenCalled();
  });

  it("shows relative timestamps on notification cards", async () => {
    render(<NotificationsPage />);

    // Both notifications are < 60 min old — should show Nm
    const timestamps = await screen.findAllByText(/^\d+m$/);
    expect(timestamps.length).toBeGreaterThanOrEqual(2);
  });
});
```

- [ ] **Step 5.2: Run the tests**

```bash
cd ~/sunbites-portal && npx jest app/\(portal\)/notifications/notifications.test.tsx --no-coverage
```

Expected: 6 tests pass (the expand test finds the full message because it's in the accordion).

- [ ] **Step 5.3: Run lint**

```bash
cd ~/sunbites-portal && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **Step 5.4: Commit**

```bash
git add app/\(portal\)/notifications/notifications.test.tsx
git commit -m "test(portal): notification page tests — type-aware rendering + click routing"
```

---

## Task 6: POS — Remove duplicate ReminderBell from header

**Spec:** Spec 11 Task 9.1

**Files:**
- Modify: `~/sunbites-pos/components/layouts/kitchen-layout.tsx`

- [ ] **Step 6.1: Remove `<ReminderBell />` from the header**

In `~/sunbites-pos/components/layouts/kitchen-layout.tsx`, find the header section (around line 270):

```tsx
<NotificationBell />
{canSeeReminders && <ReminderBell />}
```

Replace it with just:

```tsx
<NotificationBell />
```

Also check whether `ReminderBell` is imported at the top of the file. If it is, **remove only the import** if `ReminderBell` is no longer used anywhere else in the file:

```typescript
// Remove this line if present:
import { ReminderBell } from "@/components/reminder-bell";
```

- [ ] **Step 6.2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: 0 errors (or same pre-existing errors as before — no new ones).

- [ ] **Step 6.3: Commit**

```bash
cd ~/sunbites-pos
git add components/layouts/kitchen-layout.tsx
git commit -m "fix(pos): remove duplicate ReminderBell from header — single bell only"
```

---

## Task 7: POS — Discriminated union staff notification types

**Spec:** Spec 11 Task 9.3

**Files:**
- Modify: `~/sunbites-pos/types/staff-notification.ts`

- [ ] **Step 7.1: Replace flat type with discriminated union**

Replace `~/sunbites-pos/types/staff-notification.ts` with:

```typescript
export interface AnnouncementData {
  announcement_id: number;
  title: string | null;
  message: string;
  sender_name: string;
  sent_at: string;
}

export interface PreRegistrationData {
  pre_registration_id: number;
  student_name: string;
  branch_name: string;
  enrollment_type: string;
  submitted_at: string;
}

export type StaffNotification =
  | {
      id: string;
      type: "App\\Notifications\\AnnouncementNotification";
      data: AnnouncementData;
      read_at: string | null;
      created_at: string;
    }
  | {
      id: string;
      type: "App\\Notifications\\PreRegistrationNotification";
      data: PreRegistrationData;
      read_at: string | null;
      created_at: string;
    };

export interface StaffNotificationListResponse {
  data: StaffNotification[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface StaffUnreadCountResponse {
  count: number;
}
```

- [ ] **Step 7.2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -30
```

Expected: errors will appear in `app/(kitchen)/notifications/page.tsx` because it still accesses `data.title` without narrowing — that's expected and will be fixed in Task 9. No new errors should appear in any other file.

- [ ] **Step 7.3: Commit**

```bash
git add types/staff-notification.ts
git commit -m "feat(pos): discriminated union for StaffNotification type"
```

---

## Task 8: POS — Bell subscribes to PreRegistrationNotification + relative-time utility

**Spec:** Spec 11 Task 9.2 + 9.4

**Files:**
- Modify: `~/sunbites-pos/components/notification-bell.tsx`
- Create: `~/sunbites-pos/lib/relative-time.ts`

- [ ] **Step 8.1: Add PreRegistrationNotification listener to POS bell**

In `~/sunbites-pos/components/notification-bell.tsx`, replace the `useEffect` block (lines 31–43):

```typescript
  useEffect(() => {
    if (!echo || !user) return;

    const channel = echo
      .private(`staff.${user.id}`)
      .listen("AnnouncementNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
      })
      .listen("PreRegistrationNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
      });

    return () => {
      channel.stopListening("AnnouncementNotification");
      channel.stopListening("PreRegistrationNotification");
    };
  }, [echo, user, queryClient]);
```

- [ ] **Step 8.2: Create the relative-time utility**

Create `~/sunbites-pos/lib/relative-time.ts` (identical to the portal version):

```typescript
export function relativeTime(isoString: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin}m`;
  if (diffHr < 24) return `${diffHr}h`;
  if (diffDay < 7) return `${diffDay}d`;

  return date.toLocaleDateString("en-PH", { month: "short", day: "numeric" });
}
```

- [ ] **Step 8.3: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: only pre-existing errors from `notifications/page.tsx` (no new ones).

- [ ] **Step 8.4: Commit**

```bash
git add components/notification-bell.tsx lib/relative-time.ts
git commit -m "feat(pos): bell subscribes to PreRegistrationNotification; add relativeTime utility"
```

---

## Task 9: POS — MagicBell staff notifications page redesign

**Spec:** Spec 11 Task 9.5

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/notifications/page.tsx`

- [ ] **Step 9.1: Replace the page with the MagicBell design**

Replace `~/sunbites-pos/app/(kitchen)/notifications/page.tsx` with:

```tsx
"use client";

import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, CheckCheck, MoreHorizontal } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { staffNotificationApi } from "@/lib/api/staff-notifications";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { StaffNotification } from "@/types/staff-notification";

// ---------------------------------------------------------------------------
// Type-aware helpers
// ---------------------------------------------------------------------------

function getTitle(n: StaffNotification): string {
  if (n.type === "App\\Notifications\\AnnouncementNotification") {
    return n.data.title ?? "Announcement";
  }
  return "New Pre-Registration";
}

function getPreview(n: StaffNotification): string {
  if (n.type === "App\\Notifications\\AnnouncementNotification") {
    const msg = n.data.message ?? "";
    return msg.length > 120 ? msg.slice(0, 120) + "…" : msg;
  }
  return `${n.data.student_name} — ${n.data.enrollment_type} at ${n.data.branch_name}`;
}

function getDestination(n: StaffNotification): string {
  if (n.type === "App\\Notifications\\AnnouncementNotification") {
    return `/announcements/${n.data.announcement_id}`;
  }
  return `/pre-registrations/${n.data.pre_registration_id}`;
}

// ---------------------------------------------------------------------------
// NotificationCard
// ---------------------------------------------------------------------------

interface NotificationCardProps {
  notification: StaffNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
}

function NotificationCard({ notification, onMarkRead, onDelete }: NotificationCardProps) {
  const router = useRouter();
  const isUnread = notification.read_at === null;
  const title = getTitle(notification);
  const preview = getPreview(notification);

  function handleCardClick() {
    if (isUnread) {
      onMarkRead(notification.id);
    }
    router.push(getDestination(notification));
  }

  return (
    <div
      className={cn(
        "rounded-lg border border-border bg-card transition-colors",
        isUnread && "border-primary/30 bg-primary/5"
      )}
    >
      <div
        role="article"
        aria-label={title}
        className="flex cursor-pointer items-start gap-3 p-4 hover:bg-muted/30 rounded-lg"
        onClick={handleCardClick}
      >
        <span
          aria-hidden="true"
          className={cn(
            "mt-1.5 h-2 w-2 shrink-0 rounded-full transition-colors",
            isUnread ? "bg-primary" : "bg-transparent"
          )}
        />
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className={cn("text-sm leading-snug", isUnread ? "font-semibold" : "font-medium")}>
            {title}
          </p>
          <p className="line-clamp-2 text-sm text-muted-foreground">{preview}</p>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          <span className="text-xs text-muted-foreground whitespace-nowrap">
            {relativeTime(notification.created_at)}
          </span>
          <DropdownMenu>
            <DropdownMenuTrigger
              render={
                <button
                  type="button"
                  aria-label="Notification options"
                  onClick={(e) => e.stopPropagation()}
                  className="flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                />
              }
            >
              <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {isUnread && (
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    onMarkRead(notification.id);
                  }}
                >
                  Mark as read
                </DropdownMenuItem>
              )}
              <DropdownMenuItem
                variant="destructive"
                onClick={(e) => {
                  e.stopPropagation();
                  onDelete(notification.id);
                }}
              >
                Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function StaffNotificationsPage() {
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ["staff-notifications"],
    queryFn: () => staffNotificationApi.list(),
  });

  const { data: unreadData } = useQuery({
    queryKey: ["staff-unread-count"],
    queryFn: () => staffNotificationApi.unreadCount(),
  });

  const notifications = data?.data ?? [];
  const unreadCount = unreadData?.count ?? 0;

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
    queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.markRead(id),
    onSuccess: invalidate,
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => staffNotificationApi.markAllRead(),
    onSuccess: invalidate,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.destroy(id),
    onSuccess: invalidate,
  });

  const clearAllMutation = useMutation({
    mutationFn: async () => {
      await staffNotificationApi.markAllRead();
      await Promise.all(notifications.map((n) => staffNotificationApi.destroy(n.id)));
    },
    onSuccess: () => {
      invalidate();
      toast.success("All notifications cleared.");
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-3 animate-pulse">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-20 rounded-lg bg-muted" />
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Notifications</h1>
        <div className="flex items-center gap-2">
          {unreadCount > 0 && (
            <button
              type="button"
              aria-label="Mark all as read"
              onClick={() => markAllReadMutation.mutate()}
              disabled={markAllReadMutation.isPending}
              className="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted disabled:opacity-50"
            >
              <CheckCheck className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
          {notifications.length > 0 && (
            <AlertDialog>
              <AlertDialogTrigger render={<Button variant="outline" size="sm" />}>
                Clear all
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Clear all notifications?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This will permanently delete all your notifications. This action cannot be undone.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => clearAllMutation.mutate()}
                    disabled={clearAllMutation.isPending}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  >
                    Clear all
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </div>

      {/* List */}
      {notifications.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border border-border py-16 text-center">
          <Bell className="mb-3 h-10 w-10 text-muted-foreground/40" aria-hidden="true" />
          <p className="font-medium text-sm">You&apos;re all caught up</p>
          <p className="mt-1 text-xs text-muted-foreground">No notifications right now.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {notifications.map((n) => (
            <NotificationCard
              key={n.id}
              notification={n}
              onMarkRead={(id) => markReadMutation.mutate(id)}
              onDelete={(id) => deleteMutation.mutate(id)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 9.2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: 0 errors.

- [ ] **Step 9.3: Run linter**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **Step 9.4: Commit**

```bash
git add app/\(kitchen\)/notifications/page.tsx
git commit -m "feat(pos): MagicBell notifications page redesign with type-aware cards and click routing"
```

---

## Task 10: POS — Staff notification page tests

**Spec:** Spec 11 Task 9.6

**Files:**
- Create: `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx`

- [ ] **Step 10.1: Write the tests**

Create `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx`:

```tsx
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";

import StaffNotificationsPage from "./page";

const mockPush = jest.fn();

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const announcementFixture = {
  id: "notif-a1",
  type: "App\\Notifications\\AnnouncementNotification",
  data: {
    announcement_id: 3,
    title: "Canteen closure Friday",
    message: "Canteen will be closed on Friday for maintenance.",
    sender_name: "Admin User",
    sent_at: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
  },
  read_at: null,
  created_at: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
};

const preRegFixture = {
  id: "notif-p1",
  type: "App\\Notifications\\PreRegistrationNotification",
  data: {
    pre_registration_id: 12,
    student_name: "Jose Reyes",
    branch_name: "Iloilo Branch",
    enrollment_type: "subscription",
    submitted_at: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
  },
  read_at: null,
  created_at: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
};

beforeEach(() => {
  mockPush.mockClear();
  server.use(
    http.get(`${API}/notifications/unread-count`, () =>
      HttpResponse.json({ count: 2 })
    ),
    http.get(`${API}/notifications`, () =>
      HttpResponse.json({
        data: [announcementFixture, preRegFixture],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
      })
    )
  );
});

describe("StaffNotificationsPage", () => {
  it("renders an announcement notification correctly", async () => {
    render(<StaffNotificationsPage />);

    expect(await screen.findByText("Canteen closure Friday")).toBeInTheDocument();
    expect(
      screen.getByText("Canteen will be closed on Friday for maintenance.")
    ).toBeInTheDocument();
  });

  it("renders a pre-registration notification correctly", async () => {
    render(<StaffNotificationsPage />);

    expect(await screen.findByText("New Pre-Registration")).toBeInTheDocument();
    expect(
      screen.getByText("Jose Reyes — subscription at Iloilo Branch")
    ).toBeInTheDocument();
  });

  it("clicking an announcement card navigates to /announcements/{id}", async () => {
    server.use(
      http.patch(`${API}/notifications/notif-a1/read`, () =>
        HttpResponse.json({ message: "ok" })
      )
    );

    const user = userEvent.setup();
    render(<StaffNotificationsPage />);

    const card = await screen.findByRole("article", { name: "Canteen closure Friday" });
    await user.click(card);

    await waitFor(() => expect(mockPush).toHaveBeenCalledWith("/announcements/3"));
  });

  it("clicking a pre-registration card navigates to /pre-registrations/{id}", async () => {
    server.use(
      http.patch(`${API}/notifications/notif-p1/read`, () =>
        HttpResponse.json({ message: "ok" })
      )
    );

    const user = userEvent.setup();
    render(<StaffNotificationsPage />);

    const card = await screen.findByRole("article", { name: "New Pre-Registration" });
    await user.click(card);

    await waitFor(() => expect(mockPush).toHaveBeenCalledWith("/pre-registrations/12"));
  });

  it("shows the empty state when there are no notifications", async () => {
    server.use(
      http.get(`${API}/notifications`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } })
      )
    );

    render(<StaffNotificationsPage />);

    expect(await screen.findByText("You're all caught up")).toBeInTheDocument();
  });
});
```

- [ ] **Step 10.2: Run the tests**

```bash
cd ~/sunbites-pos && npx jest app/\(kitchen\)/notifications/notifications.test.tsx --no-coverage
```

Expected: 5 tests pass.

- [ ] **Step 10.3: Run lint**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **Step 10.4: Commit**

```bash
git add app/\(kitchen\)/notifications/notifications.test.tsx
git commit -m "test(pos): staff notifications page tests — type-aware rendering + click routing"
```

---

## Task 11: POS — Fix announcement list badge colors + relative timestamps

**Spec:** Spec 11 Task 10.1

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/page.tsx`

- [ ] **Step 11.1: Fix badge colors and add relative timestamp**

In `~/sunbites-pos/app/(kitchen)/announcements/page.tsx`, make two changes inside `AnnouncementRow`:

**A) Fix badge colors** — `parents` should be purple, `staff` should be blue. Replace the existing badge `className` expression:

```tsx
// Replace this:
item.recipient_type === "parents"
  ? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
  : "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"

// With this:
item.recipient_type === "parents"
  ? "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
  : "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
```

**B) Add relative timestamp** — add the import at the top and replace the raw `toLocaleDateString` call:

Add import:
```typescript
import { relativeTime } from "@/lib/relative-time";
```

Replace the timestamp span (inside the `<div className="flex shrink-0 flex-col items-end gap-1">` block):
```tsx
// Replace:
<span className="text-xs text-muted-foreground">
  {new Date(item.created_at).toLocaleDateString("en-PH", {
    month: "short",
    day: "numeric",
  })}
</span>

// With:
<span className="text-xs text-muted-foreground">
  {relativeTime(item.created_at)}
</span>
```

- [ ] **Step 11.2: Verify TypeScript compiles and lint passes**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -10 && npx next lint 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 11.3: Commit**

```bash
git add app/\(kitchen\)/announcements/page.tsx
git commit -m "fix(pos): fix announcement badge colors; add relative timestamps to announcement list"
```

---

## Task 12: POS — Create announcement: pill toggle + character count

**Spec:** Spec 11 Task 10.2

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/create/page.tsx`

- [ ] **Step 12.1: Replace the radio inputs with pill toggle buttons**

In `~/sunbites-pos/app/(kitchen)/announcements/create/page.tsx`, find the "Recipient type toggle" section (the `<div className="space-y-1.5">` wrapping the radio inputs) and replace it with:

```tsx
      {/* Recipient type toggle — pill buttons */}
      <div className="space-y-1.5">
        <p className="text-sm font-medium">Send to</p>
        <div className="inline-flex rounded-lg border border-border p-1 gap-1">
          {(["parents", "staff"] as const).map((type) => (
            <button
              key={type}
              type="button"
              onClick={() => switchType(type)}
              className={cn(
                "rounded-md px-4 py-1.5 text-sm font-medium capitalize transition-colors",
                recipientType === type
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "text-muted-foreground hover:bg-muted"
              )}
              aria-pressed={recipientType === type}
            >
              {type}
            </button>
          ))}
        </div>
      </div>
```

- [ ] **Step 12.2: Add character count to the Message textarea**

Replace the "Message" section:

```tsx
      {/* Message */}
      <div className="space-y-1.5">
        <label htmlFor="message" className="text-sm font-medium">
          Message <span className="text-destructive">*</span>
        </label>
        <div className="relative">
          <textarea
            id="message"
            rows={4}
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-none pb-6"
            placeholder="Write your announcement..."
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            maxLength={1000}
          />
          <span className="pointer-events-none absolute bottom-2 right-3 text-[11px] text-muted-foreground">
            {message.length}/1000
          </span>
        </div>
      </div>
```

- [ ] **Step 12.3: Verify TypeScript compiles and lint passes**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -10 && npx next lint 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 12.4: Commit**

```bash
git add app/\(kitchen\)/announcements/create/page.tsx
git commit -m "feat(pos): pill toggle for recipient type + character count on create announcement"
```

---

## Task 13: POS — Announcement detail: read summary + relative timestamps

**Spec:** Spec 11 Task 10.3

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/[id]/page.tsx`

- [ ] **Step 13.1: Add relativeTime import**

Add this import to the existing imports in `~/sunbites-pos/app/(kitchen)/announcements/[id]/page.tsx`:

```typescript
import { relativeTime } from "@/lib/relative-time";
```

- [ ] **Step 13.2: Add read summary above the recipients table**

Find the `<div className="space-y-3">` section for "Recipients" and add the summary line between the heading and the table:

```tsx
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold">Recipients</h2>
          <span className="text-xs text-muted-foreground">
            {announcement.recipients.filter((r) => r.read_at).length} read /{" "}
            {announcement.recipients.length} total
          </span>
        </div>
        {/* ... existing table ... */}
      </div>
```

- [ ] **Step 13.3: Use relative timestamps in the Read At column**

Replace the `<td>` for the `Read At` column:

```tsx
// Replace:
<td className="px-4 py-3 text-muted-foreground">
  {r.read_at
    ? new Date(r.read_at).toLocaleDateString("en-PH", {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "—"}
</td>

// With:
<td className="px-4 py-3 text-muted-foreground">
  {r.read_at ? relativeTime(r.read_at) : "—"}
</td>
```

- [ ] **Step 13.4: Verify TypeScript compiles and lint passes**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -10 && npx next lint 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 13.5: Commit**

```bash
git add app/\(kitchen\)/announcements/\[id\]/page.tsx
git commit -m "feat(pos): add read summary and relative timestamps to announcement detail page"
```

---

## Final QA

- [ ] **FQ.1: Run all portal tests**

```bash
cd ~/sunbites-portal && npx jest --no-coverage 2>&1 | tail -20
```

Expected: all tests pass.

- [ ] **FQ.2: Run all POS tests**

```bash
cd ~/sunbites-pos && npx jest --no-coverage 2>&1 | tail -20
```

Expected: all tests pass.

- [ ] **FQ.3: Portal lint**

```bash
cd ~/sunbites-portal && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **FQ.4: POS lint**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -5
```

Expected: no errors.

- [ ] **FQ.5: Verify kiro spec task boxes are updated**

Update the following in the kiro specs:
- `.kiro/specs/10-notifications-and-reminders/tasks.md`: check Tasks 8.1–8.6 as done
- `.kiro/specs/11-announcements/tasks.md`: check Tasks 9.1–9.7 and 10.1–10.4 as done

- [ ] **FQ.6: Final commit**

```bash
cd ~/sunbites-api
git add .kiro/specs/10-notifications-and-reminders/tasks.md .kiro/specs/11-announcements/tasks.md
git commit -m "docs: mark notification redesign tasks complete in kiro specs"
```
