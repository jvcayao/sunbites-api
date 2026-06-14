# Notification & Announcements Redesign v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Anti-Drift Protocol
**On every session start or after any context compaction:** Re-read this file top-to-bottom. Check the Progress Tracker below. Find the first unchecked task and continue from there. Also re-read the spec at `docs/superpowers/specs/2026-06-13-notification-redesign-v2.md`.

**After finishing each task:** Edit this file, change `- [ ]` → `- [x]` on that task's Progress Tracker line, and add a one-line note.

---

**Goal:** Replace navigation-based notification bell with an inline Popover dropdown, redesign the notifications full page, and overhaul the announcements page with a card-based layout using a shared `NotificationItem` component.

**Architecture:** `NotificationItem` is a shared component used in both the bell dropdown and the full notifications page. `NotificationBell` wraps it in a Radix Popover. The announcements page gets a card layout with Megaphone icons and stats.

**Spec:** `docs/superpowers/specs/2026-06-13-notification-redesign-v2.md`

**Tech Stack:** Next.js 15 App Router, React 19, TanStack Query v5, `@radix-ui/react-popover` (already installed), Tailwind v4, MSW 2 + RTL for tests.

---

## Progress Tracker

- [x] Task 1: POS — `components/ui/popover.tsx` — used @base-ui/react/popover (not Radix)
- [x] Task 2: POS — `components/notification-item.tsx` + tests — 6 tests passing
- [x] Task 3: POS — `components/notification-bell.tsx` rewrite + tests — 5/5 passing, fixed auth mock & URL pattern
- [x] Task 4: POS — `app/(kitchen)/notifications/page.tsx` rewrite + tests — 9/9 passing
- [x] Task 5: POS — `app/(kitchen)/announcements/page.tsx` rewrite + tests — 7/7 passing
- [x] Task 6: Portal — `components/ui/popover.tsx` — same @base-ui/react/popover pattern
- [x] Task 7: Portal — `components/notification-item.tsx` + tests — 4/4 passing, announcement expands inline, payment reminder navigates to /payments
- [x] Task 8: Portal — `components/notification-bell.tsx` rewrite + tests — 4/4 passing, Popover dropdown with @base-ui/react
- [x] Task 9: Portal — `app/(portal)/notifications/page.tsx` rewrite + tests — 7/7 passing, shared NotificationItem, Unread tab filter
- [x] Task 10: QA — POS: 154/154 passing (16 suites) · Portal: 30/30 passing (7 suites)

---

## Task 1: POS — `components/ui/popover.tsx`

**Files:**
- Create: `~/sunbites-pos/components/ui/popover.tsx`

- [ ] **Step 1: Create the file**

```tsx
"use client";

import * as React from "react";
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

export { Popover, PopoverContent, PopoverTrigger };
```

- [ ] **Step 2: Verify `@radix-ui/react-popover` is installed**

```bash
cd ~/sunbites-pos && grep "@radix-ui/react-popover" package.json
```

Expected: version line present. If missing, run: `npm install @radix-ui/react-popover`

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-pos && git add components/ui/popover.tsx && git commit -m "feat(pos): add shadcn Popover primitive"
```

---

## Task 2: POS — `components/notification-item.tsx` + tests

**Files:**
- Create: `~/sunbites-pos/components/notification-item.tsx`
- Create: `~/sunbites-pos/components/notification-item.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-pos/components/notification-item.test.tsx`:

```tsx
import { render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";

import { NotificationItem } from "./notification-item";

const mockPush = jest.fn();
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

const announcementUnread = {
  id: "1",
  type: "App\\Notifications\\AnnouncementNotification" as const,
  data: {
    announcement_id: 10,
    title: "School Holiday",
    message: "Classes are suspended on June 20.",
    sender_name: "Jhersonn Cayao",
    sent_at: "2026-06-13T10:00:00Z",
  },
  read_at: null,
  created_at: "2026-06-13T10:00:00Z",
};

const preRegRead = {
  id: "2",
  type: "App\\Notifications\\PreRegistrationNotification" as const,
  data: {
    pre_registration_id: 5,
    student_name: "Juan dela Cruz",
    branch_name: "Iloilo Branch",
    enrollment_type: "Subscription",
    submitted_at: "2026-06-13T08:00:00Z",
  },
  read_at: "2026-06-13T09:00:00Z",
  created_at: "2026-06-13T08:00:00Z",
};

const noop = jest.fn();

describe("NotificationItem (POS)", () => {
  beforeEach(() => jest.clearAllMocks());

  it("renders unread announcement with bold title and primary dot", () => {
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    expect(screen.getByText("School Holiday")).toHaveClass("font-semibold");
    expect(screen.getByText(/Classes are suspended/)).toBeInTheDocument();
  });

  it("renders read pre-registration with muted title", () => {
    render(
      <NotificationItem
        notification={preRegRead}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    expect(screen.getByText("New Pre-Registration")).toHaveClass("text-muted-foreground");
  });

  it("clicking unread announcement calls onMarkRead and onNavigate, navigates to announcement", async () => {
    const onMarkRead = jest.fn();
    const onNavigate = jest.fn();
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={onMarkRead}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
        onNavigate={onNavigate}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: /school holiday/i }));
    expect(onMarkRead).toHaveBeenCalledWith("1");
    expect(mockPush).toHaveBeenCalledWith("/announcements/10");
    expect(onNavigate).toHaveBeenCalled();
  });

  it("clicking read pre-registration does not call onMarkRead, navigates to pre-registration", async () => {
    const onMarkRead = jest.fn();
    render(
      <NotificationItem
        notification={preRegRead}
        onMarkRead={onMarkRead}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: /new pre-registration/i }));
    expect(onMarkRead).not.toHaveBeenCalled();
    expect(mockPush).toHaveBeenCalledWith("/pre-registrations/5");
  });

  it("shows Mark as read in menu only when unread", async () => {
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: "Notification actions" }));
    expect(screen.getByText("Mark as read")).toBeInTheDocument();
    expect(screen.getByText("Delete")).toBeInTheDocument();
  });

  it("does not show Mark as read in menu when already read", async () => {
    render(
      <NotificationItem
        notification={preRegRead}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: "Notification actions" }));
    expect(screen.queryByText("Mark as read")).not.toBeInTheDocument();
    expect(screen.getByText("Delete")).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="components/notification-item.test" --no-coverage 2>&1 | tail -20
```

Expected: FAIL — `Cannot find module './notification-item'`

- [ ] **Step 3: Create `components/notification-item.tsx`**

```tsx
"use client";

import { useRouter } from "next/navigation";
import { Megaphone, MoreHorizontal, UserPlus } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { StaffNotification } from "@/types/staff-notification";

interface NotificationItemProps {
  notification: StaffNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
  isMarkingRead: boolean;
  isDeleting: boolean;
  onNavigate?: () => void;
}

function TypeIcon({ notification }: { notification: StaffNotification }) {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return (
      <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-amber-100">
        <Megaphone className="h-4 w-4 text-amber-600" aria-hidden="true" />
      </span>
    );
  }
  return (
    <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-100">
      <UserPlus className="h-4 w-4 text-blue-600" aria-hidden="true" />
    </span>
  );
}

function getTitle(notification: StaffNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return notification.data.title ?? "Announcement";
  }
  return "New Pre-Registration";
}

function getPreview(notification: StaffNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return notification.data.message;
  }
  const { student_name, enrollment_type, branch_name } = notification.data;
  return `${student_name} — ${enrollment_type} · ${branch_name}`;
}

export function NotificationItem({
  notification,
  onMarkRead,
  onDelete,
  isMarkingRead,
  isDeleting,
  onNavigate,
}: NotificationItemProps) {
  const router = useRouter();
  const isUnread = notification.read_at === null;

  function handleClick() {
    if (isUnread) {
      onMarkRead(notification.id);
    }
    if (notification.type === "App\\Notifications\\AnnouncementNotification") {
      router.push(`/announcements/${notification.data.announcement_id}`);
    } else {
      router.push(`/pre-registrations/${notification.data.pre_registration_id}`);
    }
    onNavigate?.();
  }

  return (
    <div
      role="button"
      aria-label={getTitle(notification)}
      tabIndex={0}
      onKeyDown={(e) => e.key === "Enter" && handleClick()}
      onClick={handleClick}
      className={cn(
        "group relative flex cursor-pointer items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/50",
        isUnread && "bg-primary/5"
      )}
    >
      {/* Unread dot */}
      <span
        aria-hidden="true"
        className={cn(
          "mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full",
          isUnread ? "bg-primary" : "bg-transparent"
        )}
      />

      {/* Type icon */}
      <TypeIcon notification={notification} />

      {/* Content */}
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <p
            className={cn(
              "text-sm",
              isUnread ? "font-semibold" : "font-medium text-muted-foreground"
            )}
          >
            {getTitle(notification)}
          </p>
          <span className="flex-shrink-0 text-xs text-muted-foreground">
            {relativeTime(notification.created_at)}
          </span>
        </div>
        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
          {getPreview(notification)}
        </p>
      </div>

      {/* ··· hover menu */}
      <div
        className="absolute right-3 top-3 opacity-0 transition-opacity group-hover:opacity-100"
        onClick={(e) => e.stopPropagation()}
      >
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              aria-label="Notification actions"
              className="flex h-6 w-6 items-center justify-center rounded hover:bg-muted"
            >
              <MoreHorizontal className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {isUnread && (
              <DropdownMenuItem
                disabled={isMarkingRead}
                onSelect={() => onMarkRead(notification.id)}
              >
                Mark as read
              </DropdownMenuItem>
            )}
            <DropdownMenuItem
              disabled={isDeleting}
              onSelect={() => onDelete(notification.id)}
              className="text-destructive focus:text-destructive"
            >
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to confirm it passes**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="components/notification-item.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 5 tests passing

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-pos && git add components/notification-item.tsx components/notification-item.test.tsx && git commit -m "feat(pos): add shared NotificationItem component"
```

---

## Task 3: POS — `components/notification-bell.tsx` rewrite + tests

**Files:**
- Modify: `~/sunbites-pos/components/notification-bell.tsx` (full rewrite)
- Create: `~/sunbites-pos/components/notification-bell.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-pos/components/notification-bell.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import { NotificationBell } from "./notification-bell";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

jest.mock("@/components/providers/echo-provider", () => ({
  useEcho: () => null,
}));
jest.mock("@/lib/store/auth", () => ({
  useAuthStore: (sel: (s: any) => any) => sel({ user: { id: 1, name: "Admin" } }),
}));
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: jest.fn() }),
}));

const unreadCountHandler = (count: number) =>
  http.get(`${API}/api/v1/staff/notifications/unread-count`, () =>
    HttpResponse.json({ count })
  );

const notificationsHandler = (items: any[] = []) =>
  http.get(`${API}/api/v1/staff/notifications`, () =>
    HttpResponse.json({ data: items, meta: { current_page: 1, last_page: 1, per_page: 20, total: items.length } })
  );

describe("NotificationBell (POS)", () => {
  it("renders bell button without badge when unread count is 0", async () => {
    server.use(unreadCountHandler(0), notificationsHandler());
    render(<NotificationBell />);
    await waitFor(() => {
      expect(screen.queryByText(/^\d+$/)).not.toBeInTheDocument();
    });
    expect(screen.getByRole("button", { name: "Notifications" })).toBeInTheDocument();
  });

  it("renders badge with unread count when count > 0", async () => {
    server.use(unreadCountHandler(3), notificationsHandler());
    render(<NotificationBell />);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: "3 unread notifications" })).toBeInTheDocument();
    });
  });

  it("clicking bell opens the notification panel (not navigating)", async () => {
    server.use(unreadCountHandler(0), notificationsHandler());
    render(<NotificationBell />);
    await userEvent.click(screen.getByRole("button", { name: "Notifications" }));
    await waitFor(() => {
      expect(screen.getByText("Notifications")).toBeInTheDocument();
      expect(screen.getByText("View all notifications →")).toBeInTheDocument();
    });
  });

  it("shows empty state when notification list is empty", async () => {
    server.use(unreadCountHandler(0), notificationsHandler([]));
    render(<NotificationBell />);
    await userEvent.click(screen.getByRole("button", { name: "Notifications" }));
    await waitFor(() => {
      expect(screen.getByText("You're all caught up")).toBeInTheDocument();
    });
  });

  it("shows mark-all-read button only when unread count > 0", async () => {
    server.use(unreadCountHandler(2), notificationsHandler());
    render(<NotificationBell />);
    await userEvent.click(screen.getByRole("button", { name: "2 unread notifications" }));
    await waitFor(() => {
      expect(screen.getByRole("button", { name: "Mark all as read" })).toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="components/notification-bell.test" --no-coverage 2>&1 | tail -20
```

Expected: FAIL — popover doesn't open, or `useQuery` not working yet

- [ ] **Step 3: Rewrite `components/notification-bell.tsx`**

```tsx
"use client";

import { useEffect, useState } from "react";
import { Bell, CheckCheck } from "lucide-react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { NotificationItem } from "@/components/notification-item";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useEcho } from "@/components/providers/echo-provider";
import { staffNotificationApi } from "@/lib/api/staff-notifications";
import { useAuthStore } from "@/lib/store/auth";
import { cn } from "@/lib/utils";

import type { StaffNotification } from "@/types/staff-notification";

interface Props {
  className?: string;
}

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
    { label: "Earlier", items: items.filter((n) => new Date(n.created_at) < startOfYesterday) },
  ].filter((g) => g.items.length > 0);
}

function NotificationPanel({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();

  const { data: listData, isLoading } = useQuery({
    queryKey: ["staff-notifications"],
    queryFn: () => staffNotificationApi.list({ per_page: 20 }),
  });

  const { data: countData } = useQuery({
    queryKey: ["staff-unread-count"],
    queryFn: () => staffNotificationApi.unreadCount(),
  });

  const unreadCount = countData?.count ?? 0;
  const notifications = listData?.data ?? [];

  function invalidateAll() {
    queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
    queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.markRead(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["staff-notifications"] });
      const prev = queryClient.getQueryData(["staff-notifications"]);
      queryClient.setQueryData(["staff-notifications"], (old: any) => ({
        ...old,
        data: old?.data?.map((n: any) =>
          n.id === id ? { ...n, read_at: new Date().toISOString() } : n
        ),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["staff-notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.destroy(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["staff-notifications"] });
      const prev = queryClient.getQueryData(["staff-notifications"]);
      queryClient.setQueryData(["staff-notifications"], (old: any) => ({
        ...old,
        data: old?.data?.filter((n: any) => n.id !== id),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["staff-notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => staffNotificationApi.markAllRead(),
    onSuccess: () => invalidateAll(),
  });

  const groups = groupByDate(notifications);

  return (
    <div className="flex flex-col">
      {/* Header */}
      <div className="flex items-center justify-between border-b px-4 py-3">
        <span className="text-sm font-semibold">Notifications</span>
        {unreadCount > 0 && (
          <button
            type="button"
            aria-label="Mark all as read"
            onClick={() => markAllReadMutation.mutate()}
            disabled={markAllReadMutation.isPending}
            className="flex h-7 w-7 items-center justify-center rounded transition-colors hover:bg-muted"
          >
            <CheckCheck className="h-4 w-4" aria-hidden="true" />
          </button>
        )}
      </div>

      {/* List */}
      <div className="max-h-[420px] overflow-y-auto">
        {isLoading && (
          <div className="space-y-px">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="flex items-start gap-3 px-4 py-3">
                <div className="mt-2 h-1.5 w-1.5 rounded-full bg-muted" />
                <div className="h-8 w-8 flex-shrink-0 rounded-full bg-muted" />
                <div className="flex-1 space-y-1.5 pt-0.5">
                  <div className="h-3 w-3/4 rounded bg-muted" />
                  <div className="h-3 w-full rounded bg-muted" />
                </div>
              </div>
            ))}
          </div>
        )}

        {!isLoading && notifications.length === 0 && (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <Bell className="mb-2 h-8 w-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm font-medium">You&apos;re all caught up</p>
            <p className="text-xs text-muted-foreground">No new notifications</p>
          </div>
        )}

        {!isLoading &&
          groups.map((group) => (
            <div key={group.label}>
              <p className="px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/50">
                {group.label}
              </p>
              {group.items.map((n) => (
                <NotificationItem
                  key={n.id}
                  notification={n}
                  onMarkRead={(id) => markReadMutation.mutate(id)}
                  onDelete={(id) => deleteMutation.mutate(id)}
                  isMarkingRead={
                    markReadMutation.isPending && markReadMutation.variables === n.id
                  }
                  isDeleting={
                    deleteMutation.isPending && deleteMutation.variables === n.id
                  }
                  onNavigate={onClose}
                />
              ))}
            </div>
          ))}
      </div>

      {/* Footer */}
      <div className="border-t px-4 py-2.5 text-center">
        <Link
          href="/notifications"
          onClick={onClose}
          className="text-xs text-primary hover:underline"
        >
          View all notifications →
        </Link>
      </div>
    </div>
  );
}

export function NotificationBell({ className }: Props) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();
  const echo = useEcho();
  const user = useAuthStore((s) => s.user);

  const { data } = useQuery({
    queryKey: ["staff-unread-count"],
    queryFn: () => staffNotificationApi.unreadCount(),
    enabled: !!user,
  });

  const unreadCount = data?.count ?? 0;

  useEffect(() => {
    if (!echo || !user) return;
    const channel = echo
      .private(`staff.${user.id}`)
      .listen("AnnouncementNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
        queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
      })
      .listen("PreRegistrationNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
        queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
      });
    return () => {
      channel.stopListening("AnnouncementNotification");
      channel.stopListening("PreRegistrationNotification");
    };
  }, [echo, user, queryClient]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={
            unreadCount > 0 ? `${unreadCount} unread notifications` : "Notifications"
          }
          className={cn(
            "relative flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-muted",
            className
          )}
        >
          <Bell className="h-4 w-4" aria-hidden="true" />
          {unreadCount > 0 && (
            <span
              aria-hidden="true"
              className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-0.5 text-[10px] font-bold text-destructive-foreground"
            >
              {unreadCount > 99 ? "99+" : unreadCount}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-[380px] p-0 shadow-xl">
        <NotificationPanel onClose={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  );
}
```

- [ ] **Step 4: Run tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="components/notification-bell.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 5 tests passing

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-pos && git add components/notification-bell.tsx components/notification-bell.test.tsx && git commit -m "feat(pos): rewrite NotificationBell as Popover dropdown"
```

---

## Task 4: POS — `app/(kitchen)/notifications/page.tsx` rewrite + tests

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/notifications/page.tsx` (full rewrite)
- Modify: `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx` (update)

- [ ] **Step 1: Rewrite `app/(kitchen)/notifications/page.tsx`**

```tsx
"use client";

import { useState } from "react";
import { Bell, CheckCheck, Trash2 } from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import { NotificationItem } from "@/components/notification-item";
import { staffNotificationApi } from "@/lib/api/staff-notifications";
import { cn } from "@/lib/utils";

import type { StaffNotification } from "@/types/staff-notification";

type Tab = "all" | "unread";

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
    { label: "Earlier", items: items.filter((n) => new Date(n.created_at) < startOfYesterday) },
  ].filter((g) => g.items.length > 0);
}

function NotificationSkeleton() {
  return (
    <div className="space-y-2">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="flex items-start gap-3 px-2 py-3">
          <Skeleton className="mt-2 h-1.5 w-1.5 rounded-full" />
          <Skeleton className="h-8 w-8 flex-shrink-0 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3 w-3/4" />
            <Skeleton className="h-3 w-full" />
          </div>
        </div>
      ))}
    </div>
  );
}

export default function NotificationsPage() {
  const [activeTab, setActiveTab] = useState<Tab>("all");
  const [clearDialogOpen, setClearDialogOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ["staff-notifications"],
    queryFn: () => staffNotificationApi.list({ per_page: 50 }),
  });

  const { data: countData } = useQuery({
    queryKey: ["staff-unread-count"],
    queryFn: () => staffNotificationApi.unreadCount(),
  });

  const unreadCount = countData?.count ?? 0;
  const notifications = data?.data ?? [];

  function invalidateAll() {
    queryClient.invalidateQueries({ queryKey: ["staff-notifications"] });
    queryClient.invalidateQueries({ queryKey: ["staff-unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.markRead(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["staff-notifications"] });
      const prev = queryClient.getQueryData(["staff-notifications"]);
      queryClient.setQueryData(["staff-notifications"], (old: any) => ({
        ...old,
        data: old?.data?.map((n: any) =>
          n.id === id ? { ...n, read_at: new Date().toISOString() } : n
        ),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["staff-notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => staffNotificationApi.destroy(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["staff-notifications"] });
      const prev = queryClient.getQueryData(["staff-notifications"]);
      queryClient.setQueryData(["staff-notifications"], (old: any) => ({
        ...old,
        data: old?.data?.filter((n: any) => n.id !== id),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["staff-notifications"], ctx.prev);
      toast.error("Failed to delete notification.");
    },
    onSettled: () => invalidateAll(),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => staffNotificationApi.markAllRead(),
    onSuccess: () => {
      invalidateAll();
      toast.success("All notifications marked as read.");
    },
  });

  const clearAllMutation = useMutation({
    mutationFn: () =>
      Promise.all(notifications.map((n) => staffNotificationApi.destroy(n.id))),
    onSuccess: () => {
      invalidateAll();
      setClearDialogOpen(false);
      toast.success("All notifications cleared.");
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <h1 className="text-xl font-bold">Notifications</h1>
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
              <AlertDialogTrigger asChild>
                <Button variant="outline" size="sm" aria-label="Clear all notifications">
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                  <span>Clear all</span>
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Clear all notifications?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This will permanently delete all your notifications. This action cannot be
                    undone.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => clearAllMutation.mutate()}
                    disabled={clearAllMutation.isPending}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
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
          <div className="mb-2 flex items-center gap-2">
            <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
              {group.label}
            </p>
            <Separator className="flex-1" />
          </div>
          <div>
            {group.items.map((n) => (
              <NotificationItem
                key={n.id}
                notification={n}
                onMarkRead={(id) => markReadMutation.mutate(id)}
                onDelete={(id) => deleteMutation.mutate(id)}
                isMarkingRead={
                  markReadMutation.isPending && markReadMutation.variables === n.id
                }
                isDeleting={
                  deleteMutation.isPending && deleteMutation.variables === n.id
                }
              />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Update `notifications.test.tsx`**

Replace the contents of `~/sunbites-pos/app/(kitchen)/notifications/notifications.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import NotificationsPage from "./page";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: jest.fn() }),
}));

const announcementNotification = {
  id: "1",
  type: "App\\Notifications\\AnnouncementNotification",
  data: {
    announcement_id: 10,
    title: "School Holiday",
    message: "Classes are suspended on June 20.",
    sender_name: "Jhersonn Cayao",
    sent_at: "2026-06-13T10:00:00Z",
  },
  read_at: null,
  created_at: new Date().toISOString(),
};

const preRegNotification = {
  id: "2",
  type: "App\\Notifications\\PreRegistrationNotification",
  data: {
    pre_registration_id: 5,
    student_name: "Juan dela Cruz",
    branch_name: "Iloilo Branch",
    enrollment_type: "Subscription",
    submitted_at: new Date().toISOString(),
  },
  read_at: "2026-06-13T09:00:00Z",
  created_at: new Date().toISOString(),
};

function setupHandlers(items = [announcementNotification, preRegNotification], unread = 1) {
  server.use(
    http.get(`${API}/api/v1/staff/notifications`, () =>
      HttpResponse.json({
        data: items,
        meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length },
      })
    ),
    http.get(`${API}/api/v1/staff/notifications/unread-count`, () =>
      HttpResponse.json({ count: unread })
    )
  );
}

describe("NotificationsPage (POS)", () => {
  it("renders notification items for both types", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(screen.getByText("School Holiday")).toBeInTheDocument();
      expect(screen.getByText("New Pre-Registration")).toBeInTheDocument();
    });
  });

  it("shows empty state when no notifications", async () => {
    setupHandlers([], 0);
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(screen.getByText("You're all caught up")).toBeInTheDocument();
    });
  });

  it("Unread tab shows only unread notifications", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => screen.getByText("School Holiday"));
    await userEvent.click(screen.getByRole("tab", { name: /unread/i }));
    expect(screen.getByText("School Holiday")).toBeInTheDocument();
    expect(screen.queryByText("New Pre-Registration")).not.toBeInTheDocument();
  });

  it("shows mark-all-read button when unread count > 0", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(
        screen.getByRole("button", { name: "Mark all notifications as read" })
      ).toBeInTheDocument();
    });
  });

  it("shows clear-all button when notifications exist", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(
        screen.getByRole("button", { name: "Clear all notifications" })
      ).toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 3: Run tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="notifications/notifications.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 5 tests passing

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/notifications/page.tsx app/\(kitchen\)/notifications/notifications.test.tsx && git commit -m "feat(pos): rewrite notifications page with NotificationItem"
```

---

## Task 5: POS — `app/(kitchen)/announcements/page.tsx` rewrite + tests

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/page.tsx` (full rewrite)
- Modify: `~/sunbites-pos/app/(kitchen)/announcements/announcements.test.tsx` (update)

- [ ] **Step 1: Rewrite `app/(kitchen)/announcements/page.tsx`**

```tsx
"use client";

import Link from "next/link";
import { Megaphone } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import { announcementApi } from "@/lib/api/announcements";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { Announcement } from "@/types/announcement";

function groupByDate(
  items: Announcement[]
): { label: string; items: Announcement[] }[] {
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  return [
    { label: "Today", items: items.filter((a) => new Date(a.created_at) >= startOfToday) },
    { label: "Earlier", items: items.filter((a) => new Date(a.created_at) < startOfToday) },
  ].filter((g) => g.items.length > 0);
}

function audienceLabel(recipientType: Announcement["recipient_type"]): string {
  return recipientType === "parents" ? "Parents" : "Staff";
}

function AnnouncementSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 3 }).map((_, i) => (
        <Card key={i} className="p-4">
          <div className="flex items-start gap-3">
            <Skeleton className="h-8 w-8 flex-shrink-0 rounded-full" />
            <div className="flex-1 space-y-2">
              <div className="flex justify-between">
                <Skeleton className="h-3.5 w-1/2" />
                <Skeleton className="h-3 w-10" />
              </div>
              <Skeleton className="h-3 w-full" />
              <Skeleton className="h-3 w-3/4" />
              <Skeleton className="h-3 w-1/3" />
            </div>
          </div>
        </Card>
      ))}
    </div>
  );
}

function AnnouncementCard({ item }: { item: Announcement }) {
  return (
    <Link href={`/announcements/${item.id}`} aria-label={item.title ?? "Announcement"}>
      <Card className="cursor-pointer p-4 transition-colors hover:bg-muted/50">
        <div className="flex items-start gap-3">
          {/* Icon */}
          <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-amber-100">
            <Megaphone className="h-4 w-4 text-amber-600" aria-hidden="true" />
          </span>

          {/* Content */}
          <div className="min-w-0 flex-1">
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm font-semibold">{item.title}</p>
              <span className="flex-shrink-0 text-xs text-muted-foreground">
                {relativeTime(item.created_at)}
              </span>
            </div>
            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
              {item.message_preview}
            </p>
            {/* Meta row */}
            <div className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
              <span className="rounded-full border px-2 py-0.5 text-[10px] font-medium">
                {audienceLabel(item.recipient_type)}
              </span>
              <span>{item.sender_name}</span>
              <span>·</span>
              <span>{item.recipient_count} sent</span>
              <span>·</span>
              <span className={cn(item.read_count > 0 ? "text-foreground" : "")}>
                {item.read_count} read
              </span>
            </div>
          </div>
        </div>
      </Card>
    </Link>
  );
}

export default function AnnouncementsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ["announcements"],
    queryFn: () => announcementApi.list({ per_page: 50 }),
  });

  const announcements = data?.data ?? [];
  const groups = groupByDate(announcements);

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Announcements</h1>
        <Link
          href="/announcements/create"
          className={cn(buttonVariants({ size: "sm" }))}
        >
          + New Announcement
        </Link>
      </div>

      {/* Loading */}
      {isLoading && <AnnouncementSkeleton />}

      {/* Empty state */}
      {!isLoading && announcements.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <Megaphone className="mb-3 h-10 w-10 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium text-muted-foreground">No announcements yet</p>
        </div>
      )}

      {/* Grouped cards */}
      {!isLoading &&
        groups.map((group) => (
          <div key={group.label} className="space-y-3">
            <div className="flex items-center gap-2">
              <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
                {group.label}
              </p>
              <Separator className="flex-1" />
            </div>
            {group.items.map((item) => (
              <AnnouncementCard key={item.id} item={item} />
            ))}
          </div>
        ))}
    </div>
  );
}
```

- [ ] **Step 2: Update `announcements.test.tsx`**

Replace the contents of `~/sunbites-pos/app/(kitchen)/announcements/announcements.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import AnnouncementsPage from "./page";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const announcementFixture = {
  id: 1,
  title: "School Holiday Notice",
  message_preview: "Classes are suspended on June 20.",
  sender_name: "Jhersonn Cayao",
  recipient_type: "parents" as const,
  recipient_count: 5,
  read_count: 3,
  created_at: new Date().toISOString(),
};

function setupHandlers(items = [announcementFixture]) {
  server.use(
    http.get(`${API}/api/v1/announcements`, () =>
      HttpResponse.json({
        data: items,
        meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length },
      })
    )
  );
}

describe("AnnouncementsPage (POS)", () => {
  it("renders announcement card with title, preview, and meta", async () => {
    setupHandlers();
    render(<AnnouncementsPage />);
    await waitFor(() => {
      expect(screen.getByText("School Holiday Notice")).toBeInTheDocument();
      expect(screen.getByText(/Classes are suspended/)).toBeInTheDocument();
      expect(screen.getByText("Jhersonn Cayao")).toBeInTheDocument();
      expect(screen.getByText("5 sent")).toBeInTheDocument();
      expect(screen.getByText("3 read")).toBeInTheDocument();
      expect(screen.getByText("Parents")).toBeInTheDocument();
    });
  });

  it("announcement card links to detail page", async () => {
    setupHandlers();
    render(<AnnouncementsPage />);
    await waitFor(() => screen.getByText("School Holiday Notice"));
    expect(screen.getByRole("link", { name: "School Holiday Notice" })).toHaveAttribute(
      "href",
      "/announcements/1"
    );
  });

  it("shows empty state when no announcements", async () => {
    setupHandlers([]);
    render(<AnnouncementsPage />);
    await waitFor(() => {
      expect(screen.getByText("No announcements yet")).toBeInTheDocument();
    });
  });

  it("shows Today group label for today's announcements", async () => {
    setupHandlers();
    render(<AnnouncementsPage />);
    await waitFor(() => {
      expect(screen.getByText("Today")).toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 3: Run tests**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern="announcements/announcements.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 4 tests passing

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos && git add app/\(kitchen\)/announcements/page.tsx app/\(kitchen\)/announcements/announcements.test.tsx && git commit -m "feat(pos): redesign announcements page with card layout"
```

---

## Task 6: Portal — `components/ui/popover.tsx`

**Files:**
- Create: `~/sunbites-portal/components/ui/popover.tsx`

- [ ] **Step 1: Create the file** (identical to POS version)

```tsx
"use client";

import * as React from "react";
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

export { Popover, PopoverContent, PopoverTrigger };
```

- [ ] **Step 2: Verify `@radix-ui/react-popover` is installed**

```bash
cd ~/sunbites-portal && grep "@radix-ui/react-popover" package.json
```

Expected: version line present. If missing: `npm install @radix-ui/react-popover`

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal && git add components/ui/popover.tsx && git commit -m "feat(portal): add shadcn Popover primitive"
```

---

## Task 7: Portal — `components/notification-item.tsx` + tests

**Files:**
- Create: `~/sunbites-portal/components/notification-item.tsx`
- Create: `~/sunbites-portal/components/notification-item.test.tsx`

Note: Portal `ParentNotification` type is at `~/sunbites-portal/types/notification.ts`:
- `PaymentReminderNotification` — `data: { school_month, school_year, due_date, students[], total_amount }`
- `AnnouncementNotification` — `data: { announcement_id, title, message, sender_name, sent_at }`

Portal announcements expand inline (accordion) instead of navigating.

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/components/notification-item.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";

import { NotificationItem } from "./notification-item";

const mockPush = jest.fn();
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

const announcementUnread = {
  id: "1",
  type: "App\\Notifications\\AnnouncementNotification" as const,
  data: {
    announcement_id: 10,
    title: "Holiday Notice",
    message: "School is closed on June 20.",
    sender_name: "Admin",
    sent_at: "2026-06-13T10:00:00Z",
  },
  read_at: null,
  created_at: "2026-06-13T10:00:00Z",
};

const paymentReminderUnread = {
  id: "2",
  type: "App\\Notifications\\PaymentReminderNotification" as const,
  data: {
    school_month: "June",
    school_year: 2026,
    due_date: "2026-06-30",
    students: [{ name: "Juan dela Cruz", amount: 1500 }],
    total_amount: 1500,
  },
  read_at: null,
  created_at: "2026-06-13T08:00:00Z",
};

const noop = jest.fn();

describe("NotificationItem (Portal)", () => {
  beforeEach(() => jest.clearAllMocks());

  it("renders unread announcement with bold title", () => {
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    expect(screen.getByText("Holiday Notice")).toHaveClass("font-semibold");
  });

  it("clicking announcement expands inline body instead of navigating", async () => {
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: /holiday notice/i }));
    await waitFor(() => {
      expect(screen.getByText("School is closed on June 20.")).toBeVisible();
    });
    expect(mockPush).not.toHaveBeenCalled();
  });

  it("clicking announcement again collapses the body", async () => {
    render(
      <NotificationItem
        notification={announcementUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: /holiday notice/i }));
    await waitFor(() => screen.getByText("School is closed on June 20."));
    await userEvent.click(screen.getByRole("button", { name: /holiday notice/i }));
    await waitFor(() => {
      expect(screen.queryByText("School is closed on June 20.")).not.toBeInTheDocument();
    });
  });

  it("renders payment reminder and navigates to /payments on click", async () => {
    const onNavigate = jest.fn();
    render(
      <NotificationItem
        notification={paymentReminderUnread}
        onMarkRead={noop}
        onDelete={noop}
        isMarkingRead={false}
        isDeleting={false}
        onNavigate={onNavigate}
      />
    );
    await userEvent.click(screen.getByRole("button", { name: /payment reminder/i }));
    expect(mockPush).toHaveBeenCalledWith("/payments");
    expect(onNavigate).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="components/notification-item.test" --no-coverage 2>&1 | tail -20
```

Expected: FAIL — `Cannot find module './notification-item'`

- [ ] **Step 3: Create `components/notification-item.tsx`**

```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { CreditCard, Megaphone, MoreHorizontal } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { relativeTime } from "@/lib/relative-time";
import { cn } from "@/lib/utils";

import type { ParentNotification } from "@/types/notification";

interface NotificationItemProps {
  notification: ParentNotification;
  onMarkRead: (id: string) => void;
  onDelete: (id: string) => void;
  isMarkingRead: boolean;
  isDeleting: boolean;
  onNavigate?: () => void;
}

function TypeIcon({ notification }: { notification: ParentNotification }) {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return (
      <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-amber-100">
        <Megaphone className="h-4 w-4 text-amber-600" aria-hidden="true" />
      </span>
    );
  }
  return (
    <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-red-100">
      <CreditCard className="h-4 w-4 text-red-600" aria-hidden="true" />
    </span>
  );
}

function getTitle(notification: ParentNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return notification.data.title ?? "Announcement";
  }
  return "Payment Reminder";
}

function getPreview(notification: ParentNotification): string {
  if (notification.type === "App\\Notifications\\AnnouncementNotification") {
    return notification.data.message;
  }
  const { school_month, school_year, total_amount } = notification.data;
  return `${school_month} ${school_year} — ₱${total_amount.toLocaleString()} due`;
}

export function NotificationItem({
  notification,
  onMarkRead,
  onDelete,
  isMarkingRead,
  isDeleting,
  onNavigate,
}: NotificationItemProps) {
  const router = useRouter();
  const isUnread = notification.read_at === null;
  const isAnnouncement =
    notification.type === "App\\Notifications\\AnnouncementNotification";
  const [expanded, setExpanded] = useState(false);

  function handleClick() {
    if (isUnread) {
      onMarkRead(notification.id);
    }
    if (isAnnouncement) {
      setExpanded((prev) => !prev);
    } else {
      router.push("/payments");
      onNavigate?.();
    }
  }

  return (
    <div
      className={cn(
        "group relative flex cursor-pointer flex-col px-4 py-3 transition-colors hover:bg-muted/50",
        isUnread && "bg-primary/5"
      )}
    >
      {/* Main row */}
      <div
        role="button"
        aria-label={getTitle(notification)}
        tabIndex={0}
        onKeyDown={(e) => e.key === "Enter" && handleClick()}
        onClick={handleClick}
        className="flex items-start gap-3"
      >
        {/* Unread dot */}
        <span
          aria-hidden="true"
          className={cn(
            "mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full",
            isUnread ? "bg-primary" : "bg-transparent"
          )}
        />

        {/* Type icon */}
        <TypeIcon notification={notification} />

        {/* Content */}
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <p
              className={cn(
                "text-sm",
                isUnread ? "font-semibold" : "font-medium text-muted-foreground"
              )}
            >
              {getTitle(notification)}
            </p>
            <span className="flex-shrink-0 text-xs text-muted-foreground">
              {relativeTime(notification.created_at)}
            </span>
          </div>
          {!expanded && (
            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
              {getPreview(notification)}
            </p>
          )}
        </div>
      </div>

      {/* Inline accordion body (announcements only) */}
      {isAnnouncement && expanded && (
        <div className="ml-[44px] mt-2 border-t pt-2">
          <p className="text-sm text-foreground">
            {notification.data.message}
          </p>
        </div>
      )}

      {/* ··· hover menu */}
      <div
        className="absolute right-3 top-3 opacity-0 transition-opacity group-hover:opacity-100"
        onClick={(e) => e.stopPropagation()}
      >
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              aria-label="Notification actions"
              className="flex h-6 w-6 items-center justify-center rounded hover:bg-muted"
            >
              <MoreHorizontal className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {isUnread && (
              <DropdownMenuItem
                disabled={isMarkingRead}
                onSelect={() => onMarkRead(notification.id)}
              >
                Mark as read
              </DropdownMenuItem>
            )}
            <DropdownMenuItem
              disabled={isDeleting}
              onSelect={() => onDelete(notification.id)}
              className="text-destructive focus:text-destructive"
            >
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="components/notification-item.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 4 tests passing

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal && git add components/notification-item.tsx components/notification-item.test.tsx && git commit -m "feat(portal): add shared NotificationItem component"
```

---

## Task 8: Portal — `components/notification-bell.tsx` rewrite + tests

**Files:**
- Modify: `~/sunbites-portal/components/notification-bell.tsx` (full rewrite)
- Create: `~/sunbites-portal/components/notification-bell.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `~/sunbites-portal/components/notification-bell.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import { NotificationBell } from "./notification-bell";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

jest.mock("@/components/providers/echo-provider", () => ({
  useEcho: () => null,
}));
jest.mock("@/lib/store/auth", () => ({
  useAuthStore: (sel: (s: any) => any) => sel({ parent: { id: 1, name: "Parent" } }),
}));
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: jest.fn() }),
}));

function setupHandlers(count = 0, items: any[] = []) {
  server.use(
    http.get(`${API}/api/v1/portal/notifications/unread-count`, () =>
      HttpResponse.json({ count })
    ),
    http.get(`${API}/api/v1/portal/notifications`, () =>
      HttpResponse.json({
        data: items,
        meta: { current_page: 1, last_page: 1, per_page: 20, total: items.length },
      })
    )
  );
}

describe("NotificationBell (Portal)", () => {
  it("renders bell without badge when count is 0", async () => {
    setupHandlers(0);
    render(<NotificationBell />);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: "Notifications" })).toBeInTheDocument();
    });
  });

  it("clicking bell opens the panel", async () => {
    setupHandlers(0);
    render(<NotificationBell />);
    await userEvent.click(screen.getByRole("button", { name: "Notifications" }));
    await waitFor(() => {
      expect(screen.getByText("View all notifications →")).toBeInTheDocument();
    });
  });

  it("shows empty state when no notifications", async () => {
    setupHandlers(0, []);
    render(<NotificationBell />);
    await userEvent.click(screen.getByRole("button", { name: "Notifications" }));
    await waitFor(() => {
      expect(screen.getByText("You're all caught up")).toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="components/notification-bell.test" --no-coverage 2>&1 | tail -20
```

- [ ] **Step 3: Rewrite `components/notification-bell.tsx`**

```tsx
"use client";

import { useEffect, useState } from "react";
import { Bell, CheckCheck } from "lucide-react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { NotificationItem } from "@/components/notification-item";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useEcho } from "@/components/providers/echo-provider";
import { notificationApi } from "@/lib/api/notifications";
import { useAuthStore } from "@/lib/store/auth";
import { cn } from "@/lib/utils";

import type { ParentNotification } from "@/types/notification";

interface Props {
  className?: string;
}

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
    { label: "Earlier", items: items.filter((n) => new Date(n.created_at) < startOfYesterday) },
  ].filter((g) => g.items.length > 0);
}

function NotificationPanel({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();

  const { data: listData, isLoading } = useQuery({
    queryKey: ["notifications"],
    queryFn: () => notificationApi.list(),
  });

  const { data: countData } = useQuery({
    queryKey: ["unread-count"],
    queryFn: () => notificationApi.unreadCount(),
  });

  const unreadCount = countData?.count ?? 0;
  const notifications = listData?.data ?? [];

  function invalidateAll() {
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
    queryClient.invalidateQueries({ queryKey: ["unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => notificationApi.markRead(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["notifications"] });
      const prev = queryClient.getQueryData(["notifications"]);
      queryClient.setQueryData(["notifications"], (old: any) => ({
        ...old,
        data: old?.data?.map((n: any) =>
          n.id === id ? { ...n, read_at: new Date().toISOString() } : n
        ),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => notificationApi.destroy(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["notifications"] });
      const prev = queryClient.getQueryData(["notifications"]);
      queryClient.setQueryData(["notifications"], (old: any) => ({
        ...old,
        data: old?.data?.filter((n: any) => n.id !== id),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onSuccess: () => invalidateAll(),
  });

  const groups = groupByDate(notifications);

  return (
    <div className="flex flex-col">
      <div className="flex items-center justify-between border-b px-4 py-3">
        <span className="text-sm font-semibold">Notifications</span>
        {unreadCount > 0 && (
          <button
            type="button"
            aria-label="Mark all as read"
            onClick={() => markAllReadMutation.mutate()}
            disabled={markAllReadMutation.isPending}
            className="flex h-7 w-7 items-center justify-center rounded transition-colors hover:bg-muted"
          >
            <CheckCheck className="h-4 w-4" aria-hidden="true" />
          </button>
        )}
      </div>

      <div className="max-h-[420px] overflow-y-auto">
        {isLoading && (
          <div className="space-y-px">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="flex items-start gap-3 px-4 py-3">
                <div className="mt-2 h-1.5 w-1.5 rounded-full bg-muted" />
                <div className="h-8 w-8 flex-shrink-0 rounded-full bg-muted" />
                <div className="flex-1 space-y-1.5 pt-0.5">
                  <div className="h-3 w-3/4 rounded bg-muted" />
                  <div className="h-3 w-full rounded bg-muted" />
                </div>
              </div>
            ))}
          </div>
        )}

        {!isLoading && notifications.length === 0 && (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <Bell className="mb-2 h-8 w-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm font-medium">You&apos;re all caught up</p>
            <p className="text-xs text-muted-foreground">No new notifications</p>
          </div>
        )}

        {!isLoading &&
          groups.map((group) => (
            <div key={group.label}>
              <p className="px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/50">
                {group.label}
              </p>
              {group.items.map((n) => (
                <NotificationItem
                  key={n.id}
                  notification={n}
                  onMarkRead={(id) => markReadMutation.mutate(id)}
                  onDelete={(id) => deleteMutation.mutate(id)}
                  isMarkingRead={
                    markReadMutation.isPending && markReadMutation.variables === n.id
                  }
                  isDeleting={
                    deleteMutation.isPending && deleteMutation.variables === n.id
                  }
                  onNavigate={onClose}
                />
              ))}
            </div>
          ))}
      </div>

      <div className="border-t px-4 py-2.5 text-center">
        <Link
          href="/notifications"
          onClick={onClose}
          className="text-xs text-primary hover:underline"
        >
          View all notifications →
        </Link>
      </div>
    </div>
  );
}

export function NotificationBell({ className }: Props) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();
  const echo = useEcho();
  const parent = useAuthStore((s) => s.parent);

  const { data } = useQuery({
    queryKey: ["unread-count"],
    queryFn: () => notificationApi.unreadCount(),
  });

  const unreadCount = data?.count ?? 0;

  useEffect(() => {
    if (!echo || !parent) return;
    const channel = echo
      .private(`parents.${parent.id}`)
      .listen("PaymentReminderNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["unread-count"] });
        queryClient.invalidateQueries({ queryKey: ["notifications"] });
      })
      .listen("AnnouncementNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["unread-count"] });
        queryClient.invalidateQueries({ queryKey: ["notifications"] });
      });
    return () => {
      channel.stopListening("PaymentReminderNotification");
      channel.stopListening("AnnouncementNotification");
    };
  }, [echo, parent, queryClient]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={
            unreadCount > 0 ? `${unreadCount} unread notifications` : "Notifications"
          }
          className={cn(
            "relative flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-muted",
            className
          )}
        >
          <Bell className="h-4 w-4" aria-hidden="true" />
          {unreadCount > 0 && (
            <span
              aria-hidden="true"
              className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-0.5 text-[10px] font-bold text-destructive-foreground"
            >
              {unreadCount > 99 ? "99+" : unreadCount}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-[380px] p-0 shadow-xl">
        <NotificationPanel onClose={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  );
}
```

- [ ] **Step 4: Run tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="components/notification-bell.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 3 tests passing

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-portal && git add components/notification-bell.tsx components/notification-bell.test.tsx && git commit -m "feat(portal): rewrite NotificationBell as Popover dropdown"
```

---

## Task 9: Portal — `app/(portal)/notifications/page.tsx` rewrite + tests

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/notifications/page.tsx` (full rewrite)
- Modify: `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx` (update)

- [ ] **Step 1: Rewrite `app/(portal)/notifications/page.tsx`**

```tsx
"use client";

import { useState } from "react";
import { Bell, CheckCheck, Trash2 } from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import { NotificationItem } from "@/components/notification-item";
import { notificationApi } from "@/lib/api/notifications";
import { cn } from "@/lib/utils";

import type { ParentNotification } from "@/types/notification";

type Tab = "all" | "unread";

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
    { label: "Earlier", items: items.filter((n) => new Date(n.created_at) < startOfYesterday) },
  ].filter((g) => g.items.length > 0);
}

function NotificationSkeleton() {
  return (
    <div className="space-y-2">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="flex items-start gap-3 px-2 py-3">
          <Skeleton className="mt-2 h-1.5 w-1.5 rounded-full" />
          <Skeleton className="h-8 w-8 flex-shrink-0 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3 w-3/4" />
            <Skeleton className="h-3 w-full" />
          </div>
        </div>
      ))}
    </div>
  );
}

export default function NotificationsPage() {
  const [activeTab, setActiveTab] = useState<Tab>("all");
  const [clearDialogOpen, setClearDialogOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ["notifications"],
    queryFn: () => notificationApi.list(),
  });

  const { data: countData } = useQuery({
    queryKey: ["unread-count"],
    queryFn: () => notificationApi.unreadCount(),
  });

  const unreadCount = countData?.count ?? 0;
  const notifications = data?.data ?? [];

  function invalidateAll() {
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
    queryClient.invalidateQueries({ queryKey: ["unread-count"] });
  }

  const markReadMutation = useMutation({
    mutationFn: (id: string) => notificationApi.markRead(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["notifications"] });
      const prev = queryClient.getQueryData(["notifications"]);
      queryClient.setQueryData(["notifications"], (old: any) => ({
        ...old,
        data: old?.data?.map((n: any) =>
          n.id === id ? { ...n, read_at: new Date().toISOString() } : n
        ),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["notifications"], ctx.prev);
    },
    onSettled: () => invalidateAll(),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => notificationApi.destroy(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: ["notifications"] });
      const prev = queryClient.getQueryData(["notifications"]);
      queryClient.setQueryData(["notifications"], (old: any) => ({
        ...old,
        data: old?.data?.filter((n: any) => n.id !== id),
      }));
      return { prev };
    },
    onError: (_err: unknown, _id: string, ctx: any) => {
      if (ctx?.prev) queryClient.setQueryData(["notifications"], ctx.prev);
      toast.error("Failed to delete notification.");
    },
    onSettled: () => invalidateAll(),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onSuccess: () => {
      invalidateAll();
      toast.success("All notifications marked as read.");
    },
  });

  const clearAllMutation = useMutation({
    mutationFn: () => notificationApi.clearAll(),
    onSuccess: () => {
      invalidateAll();
      setClearDialogOpen(false);
      toast.success("All notifications cleared.");
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <h1 className="text-xl font-bold">Notifications</h1>
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
              <AlertDialogTrigger asChild>
                <Button variant="outline" size="sm" aria-label="Clear all notifications">
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                  <span>Clear all</span>
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Clear all notifications?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This will permanently delete all your notifications. This action cannot be
                    undone.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => clearAllMutation.mutate()}
                    disabled={clearAllMutation.isPending}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  >
                    {clearAllMutation.isPending ? "Clearing…" : "Clear all"}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </div>

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

      {displayed.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <Bell className="mb-3 h-10 w-10 text-muted-foreground" aria-hidden="true" />
          <p className="text-sm font-medium text-muted-foreground">You&apos;re all caught up</p>
        </div>
      )}

      {groups.map((group) => (
        <div key={group.label}>
          <div className="mb-2 flex items-center gap-2">
            <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground/60">
              {group.label}
            </p>
            <Separator className="flex-1" />
          </div>
          <div>
            {group.items.map((n) => (
              <NotificationItem
                key={n.id}
                notification={n}
                onMarkRead={(id) => markReadMutation.mutate(id)}
                onDelete={(id) => deleteMutation.mutate(id)}
                isMarkingRead={
                  markReadMutation.isPending && markReadMutation.variables === n.id
                }
                isDeleting={
                  deleteMutation.isPending && deleteMutation.variables === n.id
                }
              />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Update `notifications.test.tsx`**

Replace the contents of `~/sunbites-portal/app/(portal)/notifications/notifications.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";

import { server } from "@/__tests__/mocks/server";
import NotificationsPage from "./page";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: jest.fn() }),
}));

const announcementNotification = {
  id: "1",
  type: "App\\Notifications\\AnnouncementNotification",
  data: {
    announcement_id: 10,
    title: "Holiday Notice",
    message: "School is closed.",
    sender_name: "Admin",
    sent_at: "2026-06-13T10:00:00Z",
  },
  read_at: null,
  created_at: new Date().toISOString(),
};

const paymentNotification = {
  id: "2",
  type: "App\\Notifications\\PaymentReminderNotification",
  data: {
    school_month: "June",
    school_year: 2026,
    due_date: "2026-06-30",
    students: [{ name: "Juan dela Cruz", amount: 1500 }],
    total_amount: 1500,
  },
  read_at: "2026-06-13T09:00:00Z",
  created_at: new Date().toISOString(),
};

function setupHandlers(items = [announcementNotification, paymentNotification], unread = 1) {
  server.use(
    http.get(`${API}/api/v1/portal/notifications`, () =>
      HttpResponse.json({
        data: items,
        meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length },
      })
    ),
    http.get(`${API}/api/v1/portal/notifications/unread-count`, () =>
      HttpResponse.json({ count: unread })
    )
  );
}

describe("NotificationsPage (Portal)", () => {
  it("renders both notification types", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(screen.getByText("Holiday Notice")).toBeInTheDocument();
      expect(screen.getByText("Payment Reminder")).toBeInTheDocument();
    });
  });

  it("shows empty state when no notifications", async () => {
    setupHandlers([], 0);
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(screen.getByText("You're all caught up")).toBeInTheDocument();
    });
  });

  it("Unread tab shows only unread notifications", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => screen.getByText("Holiday Notice"));
    await userEvent.click(screen.getByRole("tab", { name: /unread/i }));
    expect(screen.getByText("Holiday Notice")).toBeInTheDocument();
    expect(screen.queryByText("Payment Reminder")).not.toBeInTheDocument();
  });

  it("shows mark-all-read button when unread > 0", async () => {
    setupHandlers();
    render(<NotificationsPage />);
    await waitFor(() => {
      expect(
        screen.getByRole("button", { name: "Mark all notifications as read" })
      ).toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 3: Run tests**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="notifications/notifications.test" --no-coverage 2>&1 | tail -20
```

Expected: PASS — 4 tests passing

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-portal && git add app/\(portal\)/notifications/page.tsx app/\(portal\)/notifications/notifications.test.tsx && git commit -m "feat(portal): rewrite notifications page with NotificationItem"
```

---

## Task 10: QA — Run all tests in both apps

- [ ] **Step 1: Run full POS test suite**

```bash
cd ~/sunbites-pos && npx jest --no-coverage 2>&1 | tail -30
```

Expected: All tests pass. Zero failures.

- [ ] **Step 2: Run full Portal test suite**

```bash
cd ~/sunbites-portal && npx jest --no-coverage 2>&1 | tail -30
```

Expected: All tests pass. Zero failures.

- [ ] **Step 3: Verify TypeScript compiles in both apps**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -30
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -30
```

Expected: No errors.

- [ ] **Step 4: Commit QA sign-off if any fixes were needed**

```bash
# Only if you made fixes during QA:
git add -A && git commit -m "fix: QA fixes from notification redesign"
```

- [ ] **Step 5: Report "Ready to commit and push"**

When all tests pass and TypeScript compiles clean in both apps, report back with:
> "Ready to commit and push — all tests pass, TypeScript clean in both apps."
