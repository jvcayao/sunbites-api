# Notifications & Announcements Pages Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign three existing pages to use a clean Grouped Inbox layout — date groups (Today / Yesterday / Earlier), thin hover rows, All/Unread tabs with badge count, and timestamp top-right.

**Architecture:** Each page is a single self-contained `"use client"` component. The redesign replaces bordered card rows with `hover:bg-muted/30` rows grouped under date section headers. A `groupByDate` helper (plain JS, no date-fns) is defined inline per file. No new files, no backend changes, no new dependencies.

**Tech Stack:** Next.js 15 App Router, TanStack Query, shadcn/ui (portal), Base UI (POS DropdownMenu), Tailwind v4, Jest + MSW for tests.

---

## Files Changed

| File | App | Change |
|---|---|---|
| `app/(portal)/notifications/page.tsx` | `~/sunbites-portal` | Rewrite |
| `app/(portal)/notifications/notifications.test.tsx` | `~/sunbites-portal` | Update — add tab + group tests |
| `app/(kitchen)/notifications/page.tsx` | `~/sunbites-pos` | Rewrite |
| `app/(kitchen)/notifications/notifications.test.tsx` | `~/sunbites-pos` | Update — add tab + group tests |
| `app/(kitchen)/announcements/page.tsx` | `~/sunbites-pos` | Rewrite |
| `app/(kitchen)/announcements/announcements.test.tsx` | `~/sunbites-pos` | Create — new test file |

---

## Task 1: Portal `/notifications` Page Redesign

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/notifications/page.tsx`
- Modify: `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx`

- [ ] **Step 1.1 — Add failing tests for the new tab and date-group features**

Append these two test cases to the `describe("NotificationsPage")` block in `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx`. The existing 5 tests stay untouched.

```tsx
  it("shows the 'Today' date group header for notifications created today", async () => {
    setupHandlers([paymentReminderFixture]);

    render(<NotificationsPage />);

    expect(await screen.findByText("Today")).toBeInTheDocument();
  });

  it("Unread tab filters to only unread notifications", async () => {
    const readFixture = {
      ...paymentReminderFixture,
      id: "notif-read",
      read_at: new Date().toISOString(),
    };
    setupHandlers([paymentReminderFixture, readFixture], 1);

    render(<NotificationsPage />);

    // Wait for All tab to load both items
    await screen.findByText("Payment Reminder — August 2026");

    // Switch to Unread tab
    const unreadTab = screen.getByRole("tab", { name: /unread/i });
    await userEvent.click(unreadTab);

    // Unread tab should show only the unread notification (1 article)
    const articles = screen.getAllByRole("article");
    expect(articles).toHaveLength(1);
  });
```

- [ ] **Step 1.2 — Run the new tests to confirm they fail**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="notifications/notifications" --no-coverage 2>&1 | tail -30
```

Expected: `● NotificationsPage › shows the 'Today' date group header` FAIL and `● NotificationsPage › Unread tab filters` FAIL. The 5 existing tests should still PASS.

- [ ] **Step 1.3 — Rewrite the portal notifications page**

Replace the full contents of `~/sunbites-portal/app/(portal)/notifications/page.tsx` with:

```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, CheckCheck, Check, Trash2, MoreHorizontal } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
} from "@/components/ui/dropdown-menu";
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
import { notificationApi } from "@/lib/api/notifications";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { ParentNotification } from "@/types/notification";

// ---------------------------------------------------------------------------
// Date grouping
// ---------------------------------------------------------------------------

function groupByDate(
  items: ParentNotification[]
): { label: string; items: ParentNotification[] }[] {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const startOfYesterday = new Date(startOfToday.getTime() - 86_400_000);

  return [
    { label: "Today", items: items.filter((n) => new Date(n.created_at) >= startOfToday) },
    {
      label: "Yesterday",
      items: items.filter(
        (n) =>
          new Date(n.created_at) >= startOfYesterday &&
          new Date(n.created_at) < startOfToday
      ),
    },
    {
      label: "Earlier",
      items: items.filter((n) => new Date(n.created_at) < startOfYesterday),
    },
  ].filter((g) => g.items.length > 0);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatAmount(amount: number): string {
  return `₱${amount.toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function getTitle(notification: ParentNotification): string {
  if (notification.type === "App\\Notifications\\PaymentReminderNotification") {
    const { school_month, school_year } = notification.data;
    const month = school_month.charAt(0).toUpperCase() + school_month.slice(1);
    return `Payment Reminder — ${month} ${school_year}`;
  }
  return notification.data.title ?? "Announcement";
}

function getPreview(notification: ParentNotification): string {
  if (notification.type === "App\\Notifications\\PaymentReminderNotification") {
    const { students, total_amount } = notification.data;
    const count = students.length;
    return `${count} student${count !== 1 ? "s" : ""} — ${formatAmount(total_amount)}`;
  }
  const { message } = notification.data;
  return message.length > 120 ? message.slice(0, 120) + "…" : message;
}

// ---------------------------------------------------------------------------
// NotificationRow
// ---------------------------------------------------------------------------

interface NotificationRowProps {
  notification: ParentNotification;
  isExpanded: boolean;
  onRowClick: (notification: ParentNotification) => void;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
}

function NotificationRow({
  notification,
  isExpanded,
  onRowClick,
  onMarkRead,
  onDelete,
}: NotificationRowProps) {
  const isUnread = notification.read_at === null;
  const isAnnouncement =
    notification.type === "App\\Notifications\\AnnouncementNotification";
  const title = getTitle(notification);
  const preview = getPreview(notification);

  return (
    <div
      role="article"
      aria-label={title}
      className={cn(
        "group relative flex cursor-pointer items-start gap-2 rounded-md px-2 py-2.5 transition-colors hover:bg-muted/30",
        isUnread && "bg-primary/5"
      )}
      onClick={() => onRowClick(notification)}
    >
      {/* Unread dot */}
      <span
        aria-hidden="true"
        className={cn(
          "mt-1.5 h-2 w-2 shrink-0 rounded-full",
          isUnread ? "bg-primary" : "bg-transparent"
        )}
      />

      <div className="min-w-0 flex-1 space-y-0.5">
        <div className="flex items-baseline justify-between gap-2">
          <p
            className={cn(
              "text-sm leading-snug",
              isUnread ? "font-semibold text-foreground" : "text-muted-foreground"
            )}
          >
            {title}
          </p>
          <span className="shrink-0 text-xs text-muted-foreground">
            {relativeTime(notification.created_at)}
          </span>
        </div>
        <p className="line-clamp-2 text-xs text-muted-foreground">{preview}</p>

        {/* Inline accordion for announcements */}
        {isAnnouncement && isExpanded && (
          <div
            className="mt-2 rounded-md border border-border bg-muted/40 p-3 text-sm"
            onClick={(e) => e.stopPropagation()}
          >
            <p className="whitespace-pre-wrap text-foreground">
              {notification.data.message}
            </p>
            <p className="mt-2 text-xs text-muted-foreground">
              From: {notification.data.sender_name}
            </p>
          </div>
        )}
      </div>

      {/* Context menu — visible on hover */}
      <DropdownMenu>
        <DropdownMenuTrigger
          aria-label="Notification options"
          className="shrink-0 rounded-md p-1 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:bg-muted focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          onClick={(e) => e.stopPropagation()}
        >
          <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-44">
          {isUnread && (
            <DropdownMenuItem
              onClick={(e) => {
                e.stopPropagation();
                onMarkRead(notification.id);
              }}
              className="flex items-center gap-2"
            >
              <Check className="h-4 w-4" aria-hidden="true" />
              Mark as read
            </DropdownMenuItem>
          )}
          <DropdownMenuItem
            variant="destructive"
            onClick={(e) => {
              e.stopPropagation();
              onDelete(notification.id);
            }}
            className="flex items-center gap-2"
          >
            <Trash2 className="h-4 w-4" aria-hidden="true" />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function NotificationsPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [clearDialogOpen, setClearDialogOpen] = useState(false);
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());
  const [activeTab, setActiveTab] = useState<"all" | "unread">("all");

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

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
    queryClient.invalidateQueries({ queryKey: ["unread-count"] });
  };

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

  function handleRowClick(notification: ParentNotification) {
    const id = notification.id;
    const isUnread = notification.read_at === null;

    if (notification.type === "App\\Notifications\\PaymentReminderNotification") {
      if (isUnread) markReadMutation.mutate(id);
      router.push("/payments");
      return;
    }

    // Announcement: mark read + toggle accordion
    if (isUnread) markReadMutation.mutate(id);
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  if (isLoading) {
    return (
      <div className="space-y-3" aria-busy="true" aria-label="Loading notifications">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-14 animate-pulse rounded-md bg-muted" />
        ))}
      </div>
    );
  }

  const displayed =
    activeTab === "unread"
      ? notifications.filter((n) => n.read_at === null)
      : notifications;

  const groups = groupByDate(displayed);

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Notifications</h1>
        <div className="flex items-center gap-2">
          {unreadCount > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => markAllReadMutation.mutate()}
              disabled={markAllReadMutation.isPending}
              aria-label="Mark all notifications as read"
            >
              <CheckCheck className="mr-1.5 h-4 w-4" aria-hidden="true" />
              Mark all read
            </Button>
          )}
          <Button
            variant="outline"
            size="sm"
            onClick={() => setClearDialogOpen(true)}
            aria-label="Clear all notifications"
          >
            Clear all
          </Button>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-border" role="tablist">
        <button
          role="tab"
          aria-selected={activeTab === "all"}
          className={cn(
            "px-3 py-2 text-sm transition-colors",
            activeTab === "all"
              ? "-mb-px border-b-2 border-primary font-semibold text-primary"
              : "text-muted-foreground hover:text-foreground"
          )}
          onClick={() => setActiveTab("all")}
        >
          All
        </button>
        <button
          role="tab"
          aria-selected={activeTab === "unread"}
          className={cn(
            "flex items-center gap-1.5 px-3 py-2 text-sm transition-colors",
            activeTab === "unread"
              ? "-mb-px border-b-2 border-primary font-semibold text-primary"
              : "text-muted-foreground hover:text-foreground"
          )}
          onClick={() => setActiveTab("unread")}
        >
          Unread
          {unreadCount > 0 && (
            <span className="rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold leading-none text-destructive-foreground">
              {unreadCount}
            </span>
          )}
        </button>
      </div>

      {/* Empty state */}
      {displayed.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <Bell className="mb-3 h-8 w-8 text-muted-foreground" aria-hidden="true" />
          <p className="font-medium">You&apos;re all caught up</p>
          <p className="mt-1 text-sm text-muted-foreground">No new notifications right now.</p>
        </div>
      )}

      {/* Grouped list */}
      {groups.map((group) => (
        <div key={group.label}>
          <p className="mb-1 px-2 text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
            {group.label}
          </p>
          <div>
            {group.items.map((n) => (
              <NotificationRow
                key={n.id}
                notification={n}
                isExpanded={expandedIds.has(n.id)}
                onRowClick={handleRowClick}
                onMarkRead={(id) => markReadMutation.mutate(id)}
                onDelete={(id) => deleteMutation.mutate(id)}
              />
            ))}
          </div>
        </div>
      ))}

      {/* Clear all confirmation dialog */}
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
              variant="destructive"
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

- [ ] **Step 1.4 — Run all portal notification tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="notifications/notifications" --no-coverage 2>&1 | tail -30
```

Expected: `7 passed` (5 original + 2 new). Fix any failures before proceeding.

- [ ] **Step 1.5 — Run portal lint**

```bash
cd ~/sunbites-portal && npx next lint 2>&1 | tail -20
```

Expected: `✔ No ESLint warnings or errors`. Fix any lint errors before proceeding.

- [ ] **Step 1.6 — Commit**

```bash
cd ~/sunbites-portal && git add app/\(portal\)/notifications/page.tsx app/\(portal\)/notifications/notifications.test.tsx && git commit -m "$(cat <<'EOF'
feat(portal): redesign /notifications page — grouped inbox with tabs

Date groups (Today/Yesterday/Earlier), All/Unread tabs with badge count,
timestamp top-right, hover rows, accordion for announcements unchanged.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: POS `/notifications` Page Redesign

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/notifications/page.tsx`
- Modify: `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx`

- [ ] **Step 2.1 — Add failing tests for the new tab and date-group features**

Append these two test cases to the `describe("StaffNotificationsPage")` block in `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx`. The existing 5 tests stay untouched.

```tsx
  it("shows the 'Today' date group header for today's notifications", async () => {
    render(<StaffNotificationsPage />);

    expect(await screen.findByText("Today")).toBeInTheDocument();
  });

  it("Unread tab filters to only unread notifications", async () => {
    server.use(
      http.get(`${API}/staff/notifications`, () =>
        HttpResponse.json({
          data: [
            announcementFixture,
            { ...preRegFixture, id: "notif-read", read_at: new Date().toISOString() },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
        })
      ),
      http.get(`${API}/staff/notifications/unread-count`, () =>
        HttpResponse.json({ count: 1 })
      )
    );

    render(<StaffNotificationsPage />);

    await screen.findByText("Canteen closure Friday");

    const unreadTab = screen.getByRole("tab", { name: /unread/i });
    unreadTab.click();

    const articles = screen.getAllByRole("article");
    expect(articles).toHaveLength(1);
    expect(screen.getByRole("article")).toHaveAccessibleName("Canteen closure Friday");
  });
```

- [ ] **Step 2.2 — Run the new tests to confirm they fail**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="notifications/notifications" --no-coverage 2>&1 | tail -30
```

Expected: the 2 new tests FAIL. The 5 existing tests should still PASS.

- [ ] **Step 2.3 — Rewrite the POS notifications page**

Replace the full contents of `~/sunbites-pos/app/(kitchen)/notifications/page.tsx` with:

```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, CheckCheck, MoreHorizontal, Trash2 } from "lucide-react";
import { toast } from "sonner";

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
import { Button } from "@/components/ui/button";
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
// Date grouping
// ---------------------------------------------------------------------------

function groupByDate(
  items: StaffNotification[]
): { label: string; items: StaffNotification[] }[] {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const startOfYesterday = new Date(startOfToday.getTime() - 86_400_000);

  return [
    { label: "Today", items: items.filter((n) => new Date(n.created_at) >= startOfToday) },
    {
      label: "Yesterday",
      items: items.filter(
        (n) =>
          new Date(n.created_at) >= startOfYesterday &&
          new Date(n.created_at) < startOfToday
      ),
    },
    {
      label: "Earlier",
      items: items.filter((n) => new Date(n.created_at) < startOfYesterday),
    },
  ].filter((g) => g.items.length > 0);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getNotificationTitle(notification: StaffNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return notification.data.title ?? "Announcement";
  }
  return "New Pre-Registration";
}

function getNotificationPreview(notification: StaffNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    const { message } = notification.data;
    return message.length > 120 ? message.slice(0, 120) + "…" : message;
  }
  const { student_name, enrollment_type, branch_name } = notification.data;
  return `${student_name} — ${enrollment_type} at ${branch_name}`;
}

function getNotificationHref(notification: StaffNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return `/announcements/${notification.data.announcement_id}`;
  }
  return `/pre-registrations/${notification.data.pre_registration_id}`;
}

// ---------------------------------------------------------------------------
// NotificationRow
// ---------------------------------------------------------------------------

interface NotificationRowProps {
  notification: StaffNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
  isMarkingRead: boolean;
  isDeleting: boolean;
}

function NotificationRow({
  notification,
  onMarkRead,
  onDelete,
  isMarkingRead,
  isDeleting,
}: NotificationRowProps) {
  const router = useRouter();
  const isUnread = notification.read_at === null;
  const title = getNotificationTitle(notification);
  const preview = getNotificationPreview(notification);
  const href = getNotificationHref(notification);

  function handleRowClick() {
    if (isUnread) onMarkRead(notification.id);
    router.push(href);
  }

  return (
    <div
      role="article"
      aria-label={title}
      className={cn(
        "group relative flex cursor-pointer items-start gap-2 rounded-md px-2 py-2.5 transition-colors hover:bg-muted/30",
        isUnread && "bg-primary/5",
        (isMarkingRead || isDeleting) && "pointer-events-none opacity-60"
      )}
      onClick={handleRowClick}
    >
      <span
        aria-hidden="true"
        className={cn(
          "mt-1.5 h-2 w-2 shrink-0 rounded-full",
          isUnread ? "bg-primary" : "bg-transparent"
        )}
      />

      <div className="min-w-0 flex-1 space-y-0.5">
        <div className="flex items-baseline justify-between gap-2">
          <p
            className={cn(
              "text-sm leading-snug",
              isUnread ? "font-semibold text-foreground" : "text-muted-foreground"
            )}
          >
            {title}
          </p>
          <span className="shrink-0 text-xs text-muted-foreground">
            {relativeTime(notification.created_at)}
          </span>
        </div>
        <p className="line-clamp-2 text-xs text-muted-foreground">{preview}</p>
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger
          render={
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
              aria-label={`More options for: ${title}`}
            />
          }
          onClick={(e: React.MouseEvent) => e.stopPropagation()}
        >
          <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" side="bottom">
          {isUnread && (
            <DropdownMenuItem
              onClick={(e: React.MouseEvent) => {
                e.stopPropagation();
                onMarkRead(notification.id);
              }}
            >
              Mark as read
            </DropdownMenuItem>
          )}
          <DropdownMenuItem
            variant="destructive"
            onClick={(e: React.MouseEvent) => {
              e.stopPropagation();
              onDelete(notification.id);
            }}
          >
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function NotificationSkeleton() {
  return (
    <div className="space-y-3" aria-busy="true" aria-label="Loading notifications">
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="h-14 animate-pulse rounded-md bg-muted" />
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function StaffNotificationsPage() {
  const queryClient = useQueryClient();
  const [clearDialogOpen, setClearDialogOpen] = useState(false);
  const [activeTab, setActiveTab] = useState<"all" | "unread">("all");

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

  function invalidateAll() {
    queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
    queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.markRead(id),
    onSuccess: invalidateAll,
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => staffNotificationApi.markAllRead(),
    onSuccess: () => {
      invalidateAll();
      toast.success("All notifications marked as read.");
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.destroy(id),
    onSuccess: invalidateAll,
  });

  const clearAllMutation = useMutation({
    mutationFn: async () => {
      await staffNotificationApi.markAllRead();
      await Promise.all(notifications.map((n) => staffNotificationApi.destroy(n.id)));
    },
    onSuccess: () => {
      invalidateAll();
      setClearDialogOpen(false);
      toast.success("All notifications cleared.");
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-bold">Notifications</h1>
        </div>
        <NotificationSkeleton />
      </div>
    );
  }

  const displayed =
    activeTab === "unread"
      ? notifications.filter((n) => n.read_at === null)
      : notifications;

  const groups = groupByDate(displayed);

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Notifications</h1>
        <div className="flex items-center gap-2">
          {unreadCount > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => markAllReadMutation.mutate()}
              disabled={markAllReadMutation.isPending}
              aria-label="Mark all notifications as read"
            >
              <CheckCheck className="h-4 w-4" aria-hidden="true" />
              <span>Mark all read</span>
            </Button>
          )}

          {notifications.length > 0 && (
            <AlertDialog open={clearDialogOpen} onOpenChange={setClearDialogOpen}>
              <AlertDialogTrigger
                render={
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label="Clear all notifications"
                  />
                }
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
                <span>Clear all</span>
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
                    variant="destructive"
                    onClick={() => clearAllMutation.mutate()}
                    disabled={clearAllMutation.isPending}
                  >
                    {clearAllMutation.isPending ? "Clearing…" : "Clear all"}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-border" role="tablist">
        <button
          role="tab"
          aria-selected={activeTab === "all"}
          className={cn(
            "px-3 py-2 text-sm transition-colors",
            activeTab === "all"
              ? "-mb-px border-b-2 border-primary font-semibold text-primary"
              : "text-muted-foreground hover:text-foreground"
          )}
          onClick={() => setActiveTab("all")}
        >
          All
        </button>
        <button
          role="tab"
          aria-selected={activeTab === "unread"}
          className={cn(
            "flex items-center gap-1.5 px-3 py-2 text-sm transition-colors",
            activeTab === "unread"
              ? "-mb-px border-b-2 border-primary font-semibold text-primary"
              : "text-muted-foreground hover:text-foreground"
          )}
          onClick={() => setActiveTab("unread")}
        >
          Unread
          {unreadCount > 0 && (
            <span className="rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold leading-none text-destructive-foreground">
              {unreadCount}
            </span>
          )}
        </button>
      </div>

      {/* Empty state */}
      {displayed.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <Bell className="mb-3 h-10 w-10 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium text-muted-foreground">You&apos;re all caught up</p>
        </div>
      )}

      {/* Grouped list */}
      {groups.map((group) => (
        <div key={group.label}>
          <p className="mb-1 px-2 text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
            {group.label}
          </p>
          <div>
            {group.items.map((n) => (
              <NotificationRow
                key={n.id}
                notification={n}
                onMarkRead={(id) => markReadMutation.mutate(id)}
                onDelete={(id) => deleteMutation.mutate(id)}
                isMarkingRead={
                  markReadMutation.isPending && markReadMutation.variables === n.id
                }
                isDeleting={deleteMutation.isPending && deleteMutation.variables === n.id}
              />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 2.4 — Run all POS notification tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="notifications/notifications" --no-coverage 2>&1 | tail -30
```

Expected: `7 passed`. Fix any failures before proceeding.

- [ ] **Step 2.5 — Run POS lint**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -20
```

Expected: `✔ No ESLint warnings or errors`. Fix any lint errors before proceeding.

- [ ] **Step 2.6 — Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/notifications/page.tsx app/\(kitchen\)/notifications/notifications.test.tsx && git commit -m "$(cat <<'EOF'
feat(pos): redesign /notifications page — grouped inbox with tabs

Date groups (Today/Yesterday/Earlier), All/Unread tabs with badge count,
timestamp top-right, hover rows. Base UI DropdownMenuTrigger pattern preserved.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: POS `/announcements` Page Redesign

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/page.tsx`
- Create: `~/sunbites-pos/app/(kitchen)/announcements/announcements.test.tsx`

- [ ] **Step 3.1 — Create the test file (tests will fail against the old implementation)**

Create `~/sunbites-pos/app/(kitchen)/announcements/announcements.test.tsx` with:

```tsx
import { http, HttpResponse } from "msw";

import { render, screen } from "@/__tests__/test-utils";
import { server } from "@/__tests__/mocks/server";

import AnnouncementsPage from "./page";

const API = "http://localhost:8000";

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const parentsFixture = {
  id: 1,
  title: "Canteen Closed Friday",
  message_preview: "The canteen will be closed on Friday for maintenance.",
  sender_name: "Admin User",
  recipient_type: "parents" as const,
  recipient_count: 48,
  read_count: 31,
  created_at: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
};

const staffFixture = {
  id: 2,
  title: "New Menu Items",
  message_preview: "Three new items added to the weekly lunch menu.",
  sender_name: "Manager",
  recipient_type: "staff" as const,
  recipient_count: 12,
  read_count: 10,
  created_at: new Date(Date.now() - 6 * 60 * 60 * 1000).toISOString(),
};

function setupHandlers(announcements: unknown[]) {
  server.use(
    http.get(`${API}/announcements`, () =>
      HttpResponse.json({
        data: announcements,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 20,
          total: announcements.length,
        },
      })
    )
  );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe("AnnouncementsPage", () => {
  it("renders announcement title and message preview", async () => {
    setupHandlers([parentsFixture]);

    render(<AnnouncementsPage />);

    expect(await screen.findByText("Canteen Closed Friday")).toBeInTheDocument();
    expect(
      screen.getByText("The canteen will be closed on Friday for maintenance.")
    ).toBeInTheDocument();
  });

  it("shows 'Parents' badge for parents recipient type", async () => {
    setupHandlers([parentsFixture]);

    render(<AnnouncementsPage />);

    expect(await screen.findByText("Parents")).toBeInTheDocument();
  });

  it("shows 'Staff' badge for staff recipient type", async () => {
    setupHandlers([staffFixture]);

    render(<AnnouncementsPage />);

    expect(await screen.findByText("Staff")).toBeInTheDocument();
  });

  it("shows sent and read counts in the stats line", async () => {
    setupHandlers([parentsFixture]);

    render(<AnnouncementsPage />);

    expect(
      await screen.findByText(/by Admin User · 48 sent · 31 read/)
    ).toBeInTheDocument();
  });

  it("shows 'Today' date group header for today's announcements", async () => {
    setupHandlers([parentsFixture]);

    render(<AnnouncementsPage />);

    expect(await screen.findByText("Today")).toBeInTheDocument();
  });

  it("shows empty state when there are no announcements", async () => {
    setupHandlers([]);

    render(<AnnouncementsPage />);

    expect(await screen.findByText("No announcements yet")).toBeInTheDocument();
    expect(
      screen.getByText("Create your first announcement to notify parents or staff.")
    ).toBeInTheDocument();
  });
});
```

- [ ] **Step 3.2 — Run the tests to confirm they fail**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="announcements/announcements" --no-coverage 2>&1 | tail -30
```

Expected: multiple tests FAIL (no `Today` header, no `No announcements yet` text in the current implementation). Fix nothing yet.

- [ ] **Step 3.3 — Rewrite the POS announcements page**

Replace the full contents of `~/sunbites-pos/app/(kitchen)/announcements/page.tsx` with:

```tsx
"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Megaphone, Plus } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { announcementApi } from "@/lib/api/announcements";
import { cn } from "@/lib/utils";
import { relativeTime } from "@/lib/relative-time";

import type { Announcement } from "@/types/announcement";

// ---------------------------------------------------------------------------
// Date grouping
// ---------------------------------------------------------------------------

function groupByDate(
  items: Announcement[]
): { label: string; items: Announcement[] }[] {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const startOfYesterday = new Date(startOfToday.getTime() - 86_400_000);

  return [
    { label: "Today", items: items.filter((a) => new Date(a.created_at) >= startOfToday) },
    {
      label: "Yesterday",
      items: items.filter(
        (a) =>
          new Date(a.created_at) >= startOfYesterday &&
          new Date(a.created_at) < startOfToday
      ),
    },
    {
      label: "Earlier",
      items: items.filter((a) => new Date(a.created_at) < startOfYesterday),
    },
  ].filter((g) => g.items.length > 0);
}

// ---------------------------------------------------------------------------
// AnnouncementRow
// ---------------------------------------------------------------------------

function AnnouncementRow({ item }: { item: Announcement }) {
  return (
    <Link
      href={`/announcements/${item.id}`}
      className="flex items-start gap-2 rounded-md px-2 py-2.5 transition-colors hover:bg-muted/30"
      aria-label={item.title ?? "Announcement"}
    >
      <div className="min-w-0 flex-1 space-y-0.5">
        <div className="flex items-baseline justify-between gap-2">
          <p className="truncate text-sm font-semibold text-foreground">
            {item.title}
          </p>
          <span className="shrink-0 text-xs text-muted-foreground">
            {relativeTime(item.created_at)}
          </span>
        </div>
        <p className="line-clamp-2 text-xs text-muted-foreground">
          {item.message_preview}
        </p>
        <div className="flex items-center gap-2 pt-0.5">
          <span
            className={cn(
              "rounded px-1.5 py-0.5 text-[10px] font-medium",
              item.recipient_type === "parents"
                ? "bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                : "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
            )}
          >
            {item.recipient_type === "parents" ? "Parents" : "Staff"}
          </span>
          <span className="text-xs text-muted-foreground">
            by {item.sender_name} · {item.recipient_count} sent · {item.read_count} read
          </span>
        </div>
      </div>
    </Link>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function AnnouncementsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ["announcements"],
    queryFn: () => announcementApi.list(),
  });

  const announcements = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Announcements</h1>
        <Link href="/announcements/create" className={buttonVariants({ size: "sm" })}>
          <Plus className="mr-1.5 h-4 w-4" aria-hidden="true" />
          New Announcement
        </Link>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="space-y-2 rounded-md px-2 py-2.5">
              <Skeleton className="h-4 w-48" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-3 w-64" />
            </div>
          ))}
        </div>
      ) : announcements.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <Megaphone className="mb-3 h-8 w-8 text-muted-foreground" aria-hidden="true" />
          <p className="font-medium">No announcements yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Create your first announcement to notify parents or staff.
          </p>
          <Link
            href="/announcements/create"
            className={cn(buttonVariants({ variant: "outline", size: "sm" }), "mt-4")}
          >
            Send your first announcement
          </Link>
        </div>
      ) : (
        groupByDate(announcements).map((group) => (
          <div key={group.label}>
            <p className="mb-1 px-2 text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
              {group.label}
            </p>
            <div>
              {group.items.map((item) => (
                <AnnouncementRow key={item.id} item={item} />
              ))}
            </div>
          </div>
        ))
      )}
    </div>
  );
}
```

- [ ] **Step 3.4 — Run the announcements tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="announcements/announcements" --no-coverage 2>&1 | tail -30
```

Expected: `6 passed`. Fix any failures before proceeding.

- [ ] **Step 3.5 — Run POS lint**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -20
```

Expected: `✔ No ESLint warnings or errors`. Fix any lint errors before proceeding.

- [ ] **Step 3.6 — Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/announcements/page.tsx app/\(kitchen\)/announcements/announcements.test.tsx && git commit -m "$(cat <<'EOF'
feat(pos): redesign /announcements page — grouped inbox layout

Date groups (Today/Yesterday/Earlier), timestamp top-right, badge and stats
inline below preview. Removes per-row card border in favour of hover rows.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Final QA

- [ ] **Step 4.1 — Run all portal tests**

```bash
cd ~/sunbites-portal && npx jest --no-coverage 2>&1 | tail -20
```

Expected: all tests pass. Fix any regressions.

- [ ] **Step 4.2 — Run all POS tests**

```bash
cd ~/sunbites-pos && npx jest --no-coverage 2>&1 | tail -20
```

Expected: all tests pass. Fix any regressions.

- [ ] **Step 4.3 — Run portal lint (final)**

```bash
cd ~/sunbites-portal && npx next lint 2>&1 | tail -10
```

Expected: `✔ No ESLint warnings or errors`.

- [ ] **Step 4.4 — Run POS lint (final)**

```bash
cd ~/sunbites-pos && npx next lint 2>&1 | tail -10
```

Expected: `✔ No ESLint warnings or errors`.
