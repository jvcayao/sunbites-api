# Unified Floating Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static 220px sidebar in `KitchenLayout` with a floating Sheet panel, standardising navigation across all kitchen/admin pages and the POS page.

**Architecture:** Extract nav item arrays to a shared `lib/navigation.ts` module. Create two new Client Components — `AppNavSheet` (the floating Sheet) and `AppHeader` (unified top bar). Refactor `KitchenLayout` to use them (removing the static sidebar), then update the POS page to delegate to the same components. Finish by removing redundant `<h1>` page title headings from kitchen pages.

**Tech Stack:** Next.js 15 App Router, React 19, TypeScript strict, Tailwind v4, shadcn/ui `Sheet`, TanStack Query v5, Zustand, `next/image`, Jest 30 + React Testing Library + MSW 2

## Global Constraints

- Working directory: `~/sunbites-pos`
- All imports use `@/` alias
- No `any` types — use explicit interfaces
- Named exports only — no default exports for components
- `cn()` from `@/lib/utils` for conditional classes
- `useAuthStore` from `@/lib/store/auth` for user and activeBranch
- `icon.png` lives at `public/icon.png` — use Next.js `<Image src="/icon.png" … />`
- Run tests with: `cd ~/sunbites-pos && npx jest --testPathPattern=<file> --no-coverage`
- Full suite: `cd ~/sunbites-pos && npx jest --no-coverage`
- Format: `cd ~/sunbites-pos && npx prettier --write <file>`

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `lib/navigation.ts` | **Create** | Shared nav arrays + `getPageTitle()` |
| `components/navigation/app-nav-sheet.tsx` | **Create** | Floating Sheet panel |
| `components/navigation/app-nav-sheet.test.tsx` | **Create** | Tests for AppNavSheet |
| `components/navigation/app-header.tsx` | **Create** | Unified top bar |
| `components/navigation/app-header.test.tsx` | **Create** | Tests for AppHeader |
| `components/layouts/kitchen-layout.tsx` | **Modify** | Remove sidebar, wire AppHeader + AppNavSheet |
| `app/(pos)/pos/page.tsx` | **Modify** | Remove inline Sheet nav, use AppHeader + AppNavSheet |
| `app/(kitchen)/references/inventory/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/references/branches/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/references/system-settings/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/references/meal-planner/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/references/subscription-config/page.tsx` | **Modify** | Remove title block, keep subtitle |
| `app/(kitchen)/references/users/page.tsx` | **Modify** | Remove title `<div>`, keep action button |
| `app/(kitchen)/references/parents/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/references/feedback/page.tsx` | **Modify** | Remove title block |
| `app/(kitchen)/notifications/page.tsx` | **Modify** | Remove title `<div>`, keep action button |
| `app/(kitchen)/announcements/page.tsx` | **Modify** | Remove title `<div>`, keep action button |
| `app/(kitchen)/reminders/page.tsx` | **Modify** | Remove title `<div>`, keep action button |
| `app/(kitchen)/pre-registrations/page.tsx` | **Modify** | Remove title block |

---

## Task 1: Extract nav definitions to `lib/navigation.ts`

**Files:**
- Create: `lib/navigation.ts`

**Interfaces:**
- Produces:
  - `NavItem` interface: `{ label: string; href: string; icon: React.ElementType; badge?: number }`
  - `mainNav: NavItem[]` — 7 items
  - `reportsNav: NavItem[]` — 9 items
  - `referencesNav: NavItem[]` — 8 items
  - `getPageTitle(pathname: string): string`

- [ ] **Step 1: Create `lib/navigation.ts`**

```typescript
import {
  LayoutDashboard,
  ShoppingCart,
  BarChart2,
  Users,
  Wallet,
  Package,
  FileText,
  Archive,
  CalendarDays,
  UserCog,
  GitBranch,
  ClipboardList,
  ClipboardCheck,
  Settings,
  UserRound,
  MessageSquare,
  Activity,
  CreditCard,
  Bell,
  Megaphone,
} from "lucide-react";

export interface NavItem {
  label: string;
  href: string;
  icon: React.ElementType;
  badge?: number;
}

export const mainNav: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: LayoutDashboard },
  { label: "POS", href: "/pos", icon: ShoppingCart },
  { label: "Enrollment", href: "/enrollment", icon: ClipboardList },
  { label: "Pre-Registrations", href: "/pre-registrations", icon: ClipboardCheck },
  { label: "Students", href: "/students", icon: Users },
  { label: "Reminders", href: "/reminders", icon: Bell },
  { label: "Announcements", href: "/announcements", icon: Megaphone },
];

export const reportsNav: NavItem[] = [
  { label: "Sales", href: "/reports/sales", icon: BarChart2 },
  { label: "Students", href: "/reports/students", icon: Users },
  { label: "Wallet", href: "/reports/wallet", icon: Wallet },
  { label: "Inventory", href: "/reports/inventory", icon: Package },
  { label: "Daily Summary", href: "/reports/daily-summary", icon: FileText },
  { label: "Billing", href: "/reports/billing", icon: ClipboardList },
  { label: "Credits", href: "/reports/credits", icon: CreditCard },
  { label: "Subscription", href: "/reports/subscription", icon: CalendarDays },
  { label: "Activity Log", href: "/reports/activity", icon: Activity },
];

export const referencesNav: NavItem[] = [
  { label: "Inventory", href: "/references/inventory", icon: Archive },
  { label: "Meal Planner", href: "/references/meal-planner", icon: CalendarDays },
  { label: "Subscription Config", href: "/references/subscription-config", icon: CalendarDays },
  { label: "Users", href: "/references/users", icon: UserCog },
  { label: "Branches", href: "/references/branches", icon: GitBranch },
  { label: "Parents", href: "/references/parents", icon: UserRound },
  { label: "Feedback", href: "/references/feedback", icon: MessageSquare },
  { label: "System Settings", href: "/references/system-settings", icon: Settings },
];

export function getPageTitle(pathname: string): string {
  const all = [...mainNav, ...reportsNav, ...referencesNav];
  return (
    all.find(
      (item) =>
        pathname === item.href || pathname.startsWith(`${item.href}/`),
    )?.label ?? "Dashboard"
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add lib/navigation.ts
git commit -m "feat: extract shared nav definitions to lib/navigation.ts"
```

---

## Task 2: Create `AppNavSheet`

**Files:**
- Create: `components/navigation/app-nav-sheet.tsx`
- Create: `components/navigation/app-nav-sheet.test.tsx`

**Interfaces:**
- Consumes: `NavItem`, `mainNav`, `reportsNav`, `referencesNav` from `@/lib/navigation`
- Consumes: `useAuthStore` from `@/lib/store/auth`
- Consumes: `preRegistrationApi.list` from `@/lib/api/pre-registrations`
- Produces: `AppNavSheet` — props `{ open: boolean; onOpenChange: (open: boolean) => void }`

- [ ] **Step 1: Write the failing test**

Create `components/navigation/app-nav-sheet.test.tsx`:

```typescript
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { AppNavSheet } from "./app-nav-sheet";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

jest.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: jest.fn() }),
}));
jest.mock("next/image", () => ({
  __esModule: true,
  default: (props: React.ImgHTMLAttributes<HTMLImageElement>) => (
    // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
    <img {...props} />
  ),
}));

const mockAdmin = {
  id: 1,
  first_name: "Jhersonn",
  last_name: "Cayao",
  roles: ["admin"],
  branches: [{ id: 1, name: "Antipolo Branch" }],
};
const mockBranch = { id: 1, name: "Antipolo Branch" };

function mockAuthStore(overrides: Partial<typeof mockAdmin> = {}) {
  const user = { ...mockAdmin, ...overrides };
  jest.mock("@/lib/store/auth", () => ({
    useAuthStore: (sel: (s: { user: typeof mockAdmin; activeBranch: typeof mockBranch; logout: () => void }) => unknown) =>
      sel({ user, activeBranch: mockBranch, logout: jest.fn() }),
  }));
}

beforeEach(() => {
  server.use(
    http.get(`${API}/pre-registrations`, () =>
      HttpResponse.json({ data: [], meta: { total: 0 } }),
    ),
    http.post(`${API}/auth/logout`, () => HttpResponse.json({})),
  );
});

jest.mock("@/lib/store/auth", () => ({
  useAuthStore: Object.assign(
    (sel: (s: { user: typeof mockAdmin; activeBranch: typeof mockBranch; logout: () => void }) => unknown) =>
      sel({ user: mockAdmin, activeBranch: mockBranch, logout: jest.fn() }),
    { getState: () => ({ user: mockAdmin, activeBranch: mockBranch, logout: jest.fn() }) },
  ),
}));

describe("AppNavSheet", () => {
  it("renders nav sheet with brand header when open", () => {
    render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
    expect(screen.getByText("Sunbites")).toBeInTheDocument();
    expect(screen.getByText("Your healthy kitchen")).toBeInTheDocument();
    expect(screen.getByText("Antipolo Branch")).toBeInTheDocument();
  });

  it("renders all main nav items", () => {
    render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
    expect(screen.getByRole("link", { name: /dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /enrollment/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /students/i })).toBeInTheDocument();
  });

  it("renders all reports nav items for admin", () => {
    render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
    expect(screen.getByRole("link", { name: /sales/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /wallet/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /activity log/i })).toBeInTheDocument();
  });

  it("calls onOpenChange(false) when a nav link is clicked", async () => {
    const onOpenChange = jest.fn();
    render(<AppNavSheet open={true} onOpenChange={onOpenChange} />);
    await userEvent.click(screen.getByRole("link", { name: /dashboard/i }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it("renders logout button", () => {
    render(<AppNavSheet open={true} onOpenChange={jest.fn()} />);
    expect(screen.getByRole("button", { name: /log out/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern=app-nav-sheet --no-coverage
```

Expected: FAIL — "Cannot find module './app-nav-sheet'"

- [ ] **Step 3: Create `components/navigation/app-nav-sheet.tsx`**

```typescript
"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";

import { LogOut } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { cn } from "@/lib/utils";
import { authApi } from "@/lib/api/auth";
import { useAuthStore } from "@/lib/store/auth";
import { preRegistrationApi } from "@/lib/api/pre-registrations";
import { mainNav, reportsNav, referencesNav } from "@/lib/navigation";
import type { NavItem } from "@/lib/navigation";
import { Badge } from "@/components/ui/badge";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";

interface AppNavSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

interface NavGroupProps {
  label: string;
  items: NavItem[];
  pathname: string;
  onClose: () => void;
}

function NavGroup({ label, items, pathname, onClose }: NavGroupProps) {
  return (
    <div className="space-y-1">
      <p className="mb-1 px-3 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
        {label}
      </p>
      {items.map((item) => {
        const isActive =
          pathname === item.href || pathname.startsWith(`${item.href}/`);
        const Icon = item.icon;

        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={isActive ? "page" : undefined}
            onClick={onClose}
            className={cn(
              "flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors",
              isActive
                ? "border-l-[3px] border-primary bg-primary/10 font-bold text-primary"
                : "text-foreground hover:bg-muted hover:text-foreground",
            )}
          >
            <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{item.label}</span>
            {item.badge !== undefined && item.badge > 0 && (
              <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground">
                {item.badge > 99 ? "99+" : item.badge}
              </span>
            )}
          </Link>
        );
      })}
    </div>
  );
}

export function AppNavSheet({ open, onOpenChange }: AppNavSheetProps) {
  const pathname = usePathname();
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const activeBranch = useAuthStore((s) => s.activeBranch);

  const isAdmin = user?.roles.includes("admin") ?? false;
  const isManager = user?.roles.includes("manager") ?? false;
  const isSupervisor = user?.roles.includes("supervisor") ?? false;
  const canSeeReminders = isAdmin || isManager || isSupervisor;
  const canSeeAnnouncements = isAdmin || isManager || isSupervisor;
  const canSeePreRegistrations = isAdmin || isManager || isSupervisor;

  const { data: pendingCountData } = useQuery({
    queryKey: ["pre-registrations-pending-count"],
    queryFn: () => preRegistrationApi.list({ status: "pending", page: 1 }),
    enabled: canSeePreRegistrations,
    staleTime: 60_000,
  });
  const pendingPreRegCount = pendingCountData?.meta?.total ?? 0;

  const mainNavFiltered = mainNav
    .map((item) =>
      item.href === "/pre-registrations"
        ? { ...item, badge: pendingPreRegCount > 0 ? pendingPreRegCount : undefined }
        : item,
    )
    .filter((item) => {
      if (item.href === "/reminders" && !canSeeReminders) return false;
      if (item.href === "/announcements" && !canSeeAnnouncements) return false;
      if (item.href === "/pre-registrations" && !canSeePreRegistrations) return false;
      return true;
    });

  const supervisorAllowedReports = [
    "/reports/sales",
    "/reports/students",
    "/reports/inventory",
    "/reports/billing",
    "/reports/subscription",
  ];
  const reportsNavFiltered =
    isAdmin || isManager
      ? reportsNav
      : reportsNav.filter((item) =>
          supervisorAllowedReports.includes(item.href),
        );

  const referencesNavFiltered = isAdmin
    ? referencesNav
    : referencesNav.filter(
        (item) => item.href !== "/references/system-settings",
      );

  function handleClose() {
    onOpenChange(false);
  }

  async function handleLogout() {
    try {
      await authApi.logout();
    } catch {
      // proceed with local logout even if the API call fails
    }
    useAuthStore.getState().logout();
    router.push("/login");
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="left" className="flex flex-col p-0 sm:max-w-xs">
        <SheetHeader className="border-b border-border px-4 py-4">
          <SheetTitle className="sr-only">Navigation</SheetTitle>
          <div className="flex items-center gap-3">
            <Image
              src="/icon.png"
              alt="Sunbites logo"
              width={36}
              height={36}
              className="shrink-0"
            />
            <div>
              <p className="font-bold leading-tight text-foreground">Sunbites</p>
              <p className="text-xs leading-tight text-muted-foreground">
                Your healthy kitchen
              </p>
            </div>
          </div>
          {activeBranch && (
            <Badge variant="outline" className="mt-2 w-fit">
              {activeBranch.name}
            </Badge>
          )}
        </SheetHeader>

        <nav className="flex-1 space-y-4 overflow-y-auto px-2 py-4">
          <NavGroup
            label="Main"
            items={mainNavFiltered}
            pathname={pathname}
            onClose={handleClose}
          />
          <NavGroup
            label="Reports"
            items={reportsNavFiltered}
            pathname={pathname}
            onClose={handleClose}
          />
          <NavGroup
            label="References"
            items={referencesNavFiltered}
            pathname={pathname}
            onClose={handleClose}
          />
        </nav>

        <div className="border-t border-border px-2 py-2">
          <button
            type="button"
            onClick={handleLogout}
            className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"
          >
            <LogOut className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>Log out</span>
          </button>
        </div>
      </SheetContent>
    </Sheet>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern=app-nav-sheet --no-coverage
```

Expected: PASS — 5 tests pass

- [ ] **Step 5: Commit**

```bash
git add components/navigation/app-nav-sheet.tsx components/navigation/app-nav-sheet.test.tsx
git commit -m "feat: add AppNavSheet floating navigation sheet component"
```

---

## Task 3: Create `AppHeader`

**Files:**
- Create: `components/navigation/app-header.tsx`
- Create: `components/navigation/app-header.test.tsx`

**Interfaces:**
- Consumes: `getPageTitle` from `@/lib/navigation`
- Consumes: `useAuthStore` from `@/lib/store/auth`
- Consumes: `NotificationBell` from `@/components/notification-bell`
- Produces: `AppHeader` — props `{ onMenuOpen: () => void }`

- [ ] **Step 1: Write the failing test**

Create `components/navigation/app-header.test.tsx`:

```typescript
import { render, screen } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { AppHeader } from "./app-header";

jest.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: jest.fn() }),
}));
jest.mock("next/image", () => ({
  __esModule: true,
  default: (props: React.ImgHTMLAttributes<HTMLImageElement>) => (
    // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
    <img {...props} />
  ),
}));
jest.mock("@/components/notification-bell", () => ({
  NotificationBell: () => <button>Notifications</button>,
}));

const mockUser = {
  id: 1,
  first_name: "Jhersonn",
  last_name: "Cayao",
  roles: ["admin"],
  branches: [{ id: 1, name: "Antipolo Branch" }],
};
const mockBranch = { id: 1, name: "Antipolo Branch" };

jest.mock("@/lib/store/auth", () => ({
  useAuthStore: Object.assign(
    (sel: (s: { user: typeof mockUser; activeBranch: typeof mockBranch }) => unknown) =>
      sel({ user: mockUser, activeBranch: mockBranch }),
    { getState: () => ({ user: mockUser, activeBranch: mockBranch, logout: jest.fn() }) },
  ),
}));

describe("AppHeader", () => {
  it("renders brand text", () => {
    render(<AppHeader onMenuOpen={jest.fn()} />);
    expect(screen.getByText("Sunbites")).toBeInTheDocument();
    expect(screen.getByText("Your healthy kitchen")).toBeInTheDocument();
  });

  it("renders current page name derived from pathname", () => {
    render(<AppHeader onMenuOpen={jest.fn()} />);
    expect(screen.getByText("Dashboard")).toBeInTheDocument();
  });

  it("renders branch name", () => {
    render(<AppHeader onMenuOpen={jest.fn()} />);
    expect(screen.getByText("Antipolo Branch")).toBeInTheDocument();
  });

  it("renders user name and role", () => {
    render(<AppHeader onMenuOpen={jest.fn()} />);
    expect(screen.getByText("Jhersonn Cayao")).toBeInTheDocument();
    expect(screen.getByText("admin")).toBeInTheDocument();
  });

  it("calls onMenuOpen when hamburger button is clicked", async () => {
    const onMenuOpen = jest.fn();
    render(<AppHeader onMenuOpen={onMenuOpen} />);
    await userEvent.click(screen.getByRole("button", { name: /open menu/i }));
    expect(onMenuOpen).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern=app-header --no-coverage
```

Expected: FAIL — "Cannot find module './app-header'"

- [ ] **Step 3: Create `components/navigation/app-header.tsx`**

```typescript
"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";

import { Menu } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuthStore } from "@/lib/store/auth";
import { getPageTitle } from "@/lib/navigation";
import { NotificationBell } from "@/components/notification-bell";

interface AppHeaderProps {
  onMenuOpen: () => void;
}

export function AppHeader({ onMenuOpen }: AppHeaderProps) {
  const pathname = usePathname();
  const user = useAuthStore((s) => s.user);
  const activeBranch = useAuthStore((s) => s.activeBranch);

  const isAdmin = user?.roles.includes("admin") ?? false;
  const canSwitchBranch = isAdmin || (user?.branches?.length ?? 0) > 1;
  const pageTitle = getPageTitle(pathname);

  return (
    <header className="no-print flex h-16 shrink-0 items-center border-b border-border bg-card px-4">
      {/* Left: hamburger + logo + brand */}
      <div className="flex items-center gap-3">
        <button
          type="button"
          aria-label="Open menu"
          onClick={onMenuOpen}
          className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          <Menu className="h-5 w-5" aria-hidden="true" />
        </button>

        <Image
          src="/icon.png"
          alt="Sunbites logo"
          width={32}
          height={32}
          className="shrink-0"
        />

        <div>
          <p className="text-sm font-bold leading-tight text-foreground">
            Sunbites
          </p>
          <p className="text-[10px] leading-tight text-muted-foreground">
            Your healthy kitchen
          </p>
        </div>
      </div>

      {/* Center: page title */}
      <div className="flex flex-1 items-center justify-center px-4">
        <h1 className="text-base font-semibold text-foreground">{pageTitle}</h1>
      </div>

      {/* Right: branch badge + notifications + user */}
      <div className="flex items-center gap-3">
        {activeBranch &&
          (canSwitchBranch ? (
            <Link
              href="/branch"
              className="rounded-full border border-border bg-muted px-3 py-1 text-xs font-medium text-foreground transition-colors hover:bg-primary/10 hover:text-primary"
            >
              {activeBranch.name}
            </Link>
          ) : (
            <span className="cursor-default rounded-full border border-border bg-muted px-3 py-1 text-xs font-medium text-foreground">
              {activeBranch.name}
            </span>
          ))}

        <NotificationBell />

        {user && (
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
              {user.first_name.charAt(0).toUpperCase()}
            </div>
            <div className="hidden flex-col md:flex">
              <span className="text-sm font-medium leading-tight">
                {user.first_name} {user.last_name}
              </span>
              {user.roles[0] && (
                <span className={cn("text-xs capitalize leading-tight text-muted-foreground")}>
                  {user.roles[0]}
                </span>
              )}
            </div>
          </div>
        )}
      </div>
    </header>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd ~/sunbites-pos && npx jest --testPathPattern=app-header --no-coverage
```

Expected: PASS — 5 tests pass

- [ ] **Step 5: Commit**

```bash
git add components/navigation/app-header.tsx components/navigation/app-header.test.tsx
git commit -m "feat: add AppHeader unified top bar component"
```

---

## Task 4: Refactor `KitchenLayout`

**Files:**
- Modify: `components/layouts/kitchen-layout.tsx`

**Interfaces:**
- Consumes: `AppHeader` from `@/components/navigation/app-header`
- Consumes: `AppNavSheet` from `@/components/navigation/app-nav-sheet`

Replace the entire `kitchen-layout.tsx` content:

- [ ] **Step 1: Replace `components/layouts/kitchen-layout.tsx`**

```typescript
"use client";

import { useState } from "react";

import { AppHeader } from "@/components/navigation/app-header";
import { AppNavSheet } from "@/components/navigation/app-nav-sheet";

interface KitchenLayoutProps {
  children: React.ReactNode;
}

export function KitchenLayout({ children }: KitchenLayoutProps) {
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <div className="flex h-screen flex-col overflow-hidden bg-background">
      <AppHeader onMenuOpen={() => setMenuOpen(true)} />
      <AppNavSheet open={menuOpen} onOpenChange={setMenuOpen} />
      <main className="flex-1 overflow-y-auto">{children}</main>
    </div>
  );
}
```

- [ ] **Step 2: Run the full test suite to check for regressions**

```bash
cd ~/sunbites-pos && npx jest --no-coverage
```

Expected: All tests that were passing before still pass. Fix any failures before proceeding.

- [ ] **Step 3: Commit**

```bash
git add components/layouts/kitchen-layout.tsx
git commit -m "refactor: replace KitchenLayout static sidebar with AppHeader + AppNavSheet"
```

---

## Task 5: Refactor POS page

**Files:**
- Modify: `app/(pos)/pos/page.tsx`

The POS page currently has its own inline Sheet nav and header bar. Replace them with `AppHeader` + `AppNavSheet`. The rest of the page (tab bar, menu grid, cart panel, modals) is unchanged.

- [ ] **Step 1: Update imports in `app/(pos)/pos/page.tsx`**

Remove these imports (no longer needed after the refactor):
```typescript
// Remove these:
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Menu,
  LayoutDashboard,
  ClipboardList,
  Users,
  Archive,
  CalendarDays,
  GitBranch,
  UserCog,
  LogOut,
} from "lucide-react";
import { AppLogo } from "@/components/app-logo";
import { Badge } from "@/components/ui/badge";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetFooter,
} from "@/components/ui/sheet";
```

Add these imports:
```typescript
import { AppHeader } from "@/components/navigation/app-header";
import { AppNavSheet } from "@/components/navigation/app-nav-sheet";
```

- [ ] **Step 2: Remove inline nav state and data structures**

Remove from `PosPage`:
- The `interface NavItem` definition (lines 47–52)
- The `MAIN_NAV` array (lines 54–66)
- The `REFERENCES_NAV` array (lines 68–90)
- `const [menuOpen, setMenuOpen] = useState(false);` (line 118)
- `const pathname = usePathname();` (line 119)
- The `handleLogout` function (lines 124–132) — logout is now in `AppNavSheet`

- [ ] **Step 3: Replace the top header bar and Sheet with `AppHeader` + `AppNavSheet`**

The current JSX starts with:
```tsx
return (
  <div className="flex h-screen flex-col overflow-hidden bg-background">
    {/* Top header bar */}
    <div className="flex h-12 shrink-0 items-center border-b border-border bg-card px-3">
      ...hamburger button...
      ...branch name...
      ...user info...
    </div>

    {/* Hamburger Sheet */}
    <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
      ...
    </Sheet>
    ...
```

Replace the `{/* Top header bar */}` div and `{/* Hamburger Sheet */}` Sheet block with:

```tsx
return (
  <div className="flex h-screen flex-col overflow-hidden bg-background">
    <AppHeader onMenuOpen={() => setMenuOpen(true)} />
    <AppNavSheet open={menuOpen} onOpenChange={setMenuOpen} />
    ...rest of page unchanged (tab bar, content)...
```

Add `const [menuOpen, setMenuOpen] = useState(false);` back (the POS page owns this state since it has its own layout wrapper, not KitchenLayout).

- [ ] **Step 4: Run the full test suite**

```bash
cd ~/sunbites-pos && npx jest --no-coverage
```

Expected: All tests pass. Fix any failures before proceeding.

- [ ] **Step 5: Commit**

```bash
git add app/\(pos\)/pos/page.tsx
git commit -m "refactor: replace POS page inline nav with AppHeader + AppNavSheet"
```

---

## Task 6: Remove redundant page title headings

Each kitchen page has a heading block that duplicates the page name now shown in `AppHeader`. Remove them following the spec rule:

- **Title-only heading (the div containing `<p>` breadcrumb + `<h1>` with no sibling action button)** → remove the entire `<div>` block
- **Title + actions (title div is flex sibling to an action button)** → remove only the title `<div>`, keep the action button

**Files and exact changes:**

### `references/inventory/page.tsx` — title-only

Remove lines:
```tsx
<div>
  <p className="text-xs text-muted-foreground">References</p>
  <h1 className="text-2xl font-bold text-foreground">Inventory</h1>
</div>
```

### `references/branches/page.tsx` — title-only

Remove lines:
```tsx
<div>
  <p className="text-xs text-muted-foreground">References</p>
  <h1 className="text-2xl font-bold text-foreground">
    Branch Management
  </h1>
</div>
```

### `references/system-settings/page.tsx` — title-only

Remove lines:
```tsx
<p className="text-xs text-muted-foreground">References</p>
<h1 className="text-2xl font-bold text-foreground">System Settings</h1>
```

### `references/meal-planner/page.tsx` — title-only

Remove lines:
```tsx
<p className="text-xs text-muted-foreground">References</p>
<h1 className="text-2xl font-bold text-foreground">Meal Planner</h1>
```

### `references/subscription-config/page.tsx` — title-only, keep subtitle

Remove:
```tsx
<p className="text-xs text-muted-foreground">References</p>
<h1 className="text-2xl font-bold text-foreground">
  Subscription Config
</h1>
```
Keep the subtitle `<p className="mt-1 text-sm text-muted-foreground">Daily meal rate…</p>` — it's meaningful content.

### `references/parents/page.tsx` — title-only

Remove the title `<div>` (the one containing the breadcrumb `<p>` + `<h1>`). The filters below are a separate `<div>` — leave them.

```tsx
{/* Remove this block: */}
<div>
  <p className="text-xs text-muted-foreground">References</p>
  <h1 className="text-xl font-bold text-foreground">Parent Management</h1>
</div>
```

### `references/feedback/page.tsx` — title-only

```tsx
{/* Remove this block: */}
<div>
  <p className="text-xs text-muted-foreground">References</p>
  <h1 className="text-xl font-bold text-foreground">Feedback</h1>
</div>
```

### `pre-registrations/page.tsx` — title-only

```tsx
{/* Remove this block: */}
<div>
  <p className="text-xs text-muted-foreground">References</p>
  <h1 className="text-xl font-bold text-foreground">Pre-Registrations</h1>
</div>
```

### `references/users/page.tsx` — title + action button

The structure is a flex row:
```tsx
<div className="flex items-center justify-between">
  <div>
    <p className="text-xs text-muted-foreground">References</p>
    <h1 className="text-xl font-bold text-foreground">User Management</h1>
  </div>
  <Link href="/references/users/create" className={buttonVariants()}>
    <Plus className="mr-1.5 h-4 w-4" />
    Add New User
  </Link>
</div>
```

Remove only the inner `<div>` containing the breadcrumb + h1. The result:
```tsx
<div className="flex items-center justify-between">
  <Link href="/references/users/create" className={buttonVariants()}>
    <Plus className="mr-1.5 h-4 w-4" />
    Add New User
  </Link>
</div>
```

### `notifications/page.tsx` — title + action button (two instances: loading state + loaded state)

Loading state (around line 353):
```tsx
<div>
  <p className="text-xs text-muted-foreground">Activity</p>
  <h1 className="text-xl font-bold text-foreground">Notifications</h1>
</div>
```
Remove this block entirely (the loading state has no action button).

Loaded state (around line 373): The structure is a flex row — remove the inner `<div>` with breadcrumb + h1, keep the action buttons `<div>` on the right.

### `announcements/page.tsx` — title + action button

Remove the inner `<div>` with breadcrumb + h1; keep the `<Link>` "New Announcement" button.

### `reminders/page.tsx` — title + action button

Remove the inner `<div>` with `<h2>Payment Reminders</h2>` + subtitle `<p>`; keep the conditional "Send Reminders" `<Button>`.

- [ ] **Step 1: Apply all title removals above to each file**

Apply each change in sequence. Verify each file still renders valid JSX (no orphaned closing tags).

- [ ] **Step 2: Run the full test suite**

```bash
cd ~/sunbites-pos && npx jest --no-coverage
```

Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/\(kitchen\)/
git commit -m "refactor: remove redundant page title headings now shown in AppHeader"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| New `AppNavSheet` with icon.png + brand + branch header | Task 2 |
| All nav groups (Main, Reports, References) in sheet | Task 2 |
| Role-based filtering in sheet | Task 2 |
| Sheet closes on nav link click | Task 2 |
| Logout in sheet | Task 2 |
| New `AppHeader` with hamburger + icon.png + brand text | Task 3 |
| Page name in center of header from `usePathname()` | Task 3 |
| Right side: branch badge + bell + user info | Task 3 |
| `KitchenLayout` removes static sidebar | Task 4 |
| `KitchenLayout` wires AppHeader + AppNavSheet | Task 4 |
| POS page uses AppHeader + AppNavSheet | Task 5 |
| Title-only `<h1>` headings removed | Task 6 |
| Title + actions: remove title, keep actions | Task 6 |

All requirements covered. ✓

**Type consistency check:**

- `NavItem` defined in Task 1 (`lib/navigation.ts`), consumed in Task 2 (`AppNavSheet`)
- `getPageTitle(pathname: string): string` defined in Task 1, consumed in Task 3 (`AppHeader`)
- `AppNavSheet` props `{ open: boolean; onOpenChange: (open: boolean) => void }` used in Tasks 4 and 5
- `AppHeader` props `{ onMenuOpen: () => void }` used in Tasks 4 and 5

All consistent. ✓
