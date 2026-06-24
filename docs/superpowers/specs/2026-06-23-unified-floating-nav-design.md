# Unified Floating Navigation — Design Spec

**Date:** 2026-06-23
**Project:** sunbites-pos (Next.js POS & admin app)
**Status:** Approved

---

## Overview

Replace the static 220px left sidebar on all kitchen/admin pages with the same floating Sheet panel already used on the POS page. Every authenticated page gets a unified top header bar and a consistent floating navigation experience. This frees up horizontal space so page content has more breathing room.

---

## Problem

Navigation is inconsistent across the app:
- Kitchen/admin pages use a fixed 220px sidebar (collapsible to 60px icon-only mode)
- The POS page uses a floating Sheet panel triggered by a hamburger button
- The static sidebar consumes permanent horizontal space regardless of whether the user needs navigation

---

## Approach

Lift the POS floating Sheet nav into a shared `AppNavSheet` component. `KitchenLayout` adopts it — removing the static sidebar and adding the new unified header. The POS page delegates to the same shared components instead of its inline implementation.

---

## Components

### New: `components/navigation/app-nav-sheet.tsx`

The floating Sheet panel. Props: `open: boolean`, `onOpenChange: (open: boolean) => void`.

**Panel header (inside the sheet):**
```
[ icon.png ]  Sunbites
              Your healthy kitchen
Antipolo Branch
─────────────────────────────────────
```

- Uses `public/icon.png` for the logo (not the red circle "S")
- Branch name from the active branch context
- Horizontal rule separates header from nav items

**Nav groups (all items from the current kitchen sidebar):**

| Group | Items |
|---|---|
| Main | Dashboard, POS, Enrollment, Pre-Registrations, Students, Reminders, Announcements |
| Reports | Sales, Students, Wallet, Inventory, Daily Summary, Billing, Credits, Subscription, Activity Log |
| References | Inventory, Meal Planner, Subscription Config, Users, Branches, Parents, Feedback, System Settings |

- Role-based filtering carried over exactly from `KitchenLayout` (supervisor restrictions, admin-only items)
- Every nav link calls `onOpenChange(false)` on click to close the sheet
- Logout button at the bottom

### New: `components/navigation/app-header.tsx`

The unified top bar. Client Component (needs click handler and `usePathname`).

**Layout:**
```
[ ☰ ]  [ icon.png ]  Sunbites          (Page Name)     ──────  Antipolo Branch  🔔  [J] Name / Role
                     Your healthy kitchen
```

- Left: hamburger `☰` button → calls `onMenuOpen`. `icon.png` logo. Stacked brand text ("Sunbites" bold, "Your healthy kitchen" muted below).
- Center: current page name derived from `usePathname()` — maps route to display name (e.g. `/dashboard` → `"Dashboard"`, `/enrollment` → `"Enrollment"`, `/references/inventory` → `"Inventory"`)
- Right: branch badge, notification bell icon, user avatar + name + role — retained exactly as the current kitchen header

**Props:** `onMenuOpen: () => void`

### Modified: `components/layouts/kitchen-layout.tsx`

- Remove the static sidebar (220px/60px, `collapsed` state, toggle button)
- Remove the existing header area
- Add `AppHeader` and `AppNavSheet` at the top level
- Hold `menuOpen: boolean` state — pass `onMenuOpen` to `AppHeader`, pass `open` and `onOpenChange` to `AppNavSheet`
- Page content takes full width below the header

### Modified: `app/(pos)/pos/page.tsx`

- Remove the inline `Sheet` component and hamburger button from the POS page
- Remove local `menuOpen` state for the sheet
- Add `AppHeader` and `AppNavSheet` with a new local `menuOpen` state
- The rest of the POS page layout (search bar, menu grid, order summary panel) is unchanged

---

## Page Title Handling

Pages that currently have a standalone `<h1>` title repeating the page name (e.g. `<h1>Dashboard</h1>`) have that heading removed — the top bar's center page name handles wayfinding.

Pages with a heading that includes action buttons or filters (e.g. Students page with "New Student" button) keep the actions row but drop the title text from it.

**Rule:**
- Title-only heading → remove
- Title + actions/filters → keep actions, remove title text

---

## State & Data Flow

- `menuOpen` boolean lives at the layout level (`KitchenLayout` or POS page) — no Zustand store needed
- Active page name derived inside `AppHeader` via `usePathname()` — no prop drilling
- Sheet closes on every nav link click via `onOpenChange(false)`
- Branch and user info read from existing auth/branch context (same as current implementation)

---

## Files Changed

| File | Change |
|---|---|
| `components/navigation/app-nav-sheet.tsx` | **New** — floating Sheet panel with all nav groups |
| `components/navigation/app-header.tsx` | **New** — unified top bar |
| `components/layouts/kitchen-layout.tsx` | Remove static sidebar and old header; add `AppHeader` + `AppNavSheet` |
| `app/(pos)/pos/page.tsx` | Remove inline Sheet + hamburger; use `AppHeader` + `AppNavSheet` |
| Individual kitchen page files | Remove standalone `<h1>` title-only headings; keep action rows |

---

## What Does Not Change

- Route structure — no URL changes
- Role-based filtering logic — carried over exactly
- Right-side header content (branch badge, notifications, user info)
- POS page layout (search, menu grid, order summary)
- Auth pages (`AuthLayout`) — no navigation, unaffected
- All other page content and functionality
