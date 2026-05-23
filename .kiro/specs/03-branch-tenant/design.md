# Design 03 — Branch & Tenant

## Screen: Branch Selector Page

**Route:** `pos.sunbites.com.ph/branch`
**Layout:** `AuthLayout` (no sidebar, full viewport, centered)

```
┌─────────────────────── viewport ─────────────────────────┐
│                                                           │
│                  ╭─────────────╮                         │
│                  │      S      │                         │
│                  ╰─────────────╯                         │
│                Sunbites Kitchen                           │
│            Welcome back, Admin! 👋                        │
│          Please select your branch                        │
│                                                           │
│    ┌───────────────────┐   ┌───────────────────┐         │
│    │                   │   │                   │         │
│    │        🏫         │   │        🏫         │         │
│    │                   │   │                   │         │
│    │    Antipolo       │   │     Iloilo        │         │
│    │     Branch        │   │     Branch        │         │
│    │                   │   │                   │         │
│    └───────────────────┘   └───────────────────┘         │
│                                                           │
└───────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Page background: `--background` (white), no sidebar
- Logo: `<AppLogo />` centered
- Welcome text: `text-2xl font-extrabold text-primary`
- Subtitle: `text-sm text-muted-foreground`
- Branch cards: white background, `border-2 border-primary`, `rounded-2xl`, `p-8`, 220px wide
- Icon: emoji 🏫, `text-5xl`, centered
- Branch name: `text-xl font-extrabold text-primary`
- "Branch" label: `text-sm font-semibold text-muted-foreground`
- Hover: background transitions to `bg-primary/5`
- Active (pressed): `scale-95` transition

**After selection:** Active branch stored in Zustand store → redirect to `/dashboard`

---

## Component: Branch Switcher (Topbar)

Shown in the topbar for Admin users and multi-branch users:

```
  [🏫 Antipolo Branch  ⇄ Switch]
```

- Pill shape: `bg-muted rounded-full px-3 py-1.5 text-sm font-bold`
- Branch name: `text-primary-800`
- "⇄ Switch" label: `text-primary text-[10px]`
- On click: navigates to `/branch` (branch selector page) without requiring re-login
- Cashiers with single branch: plain non-clickable pill (no switch)

```
  [🏫 Antipolo Branch]            ← read-only for Cashier
  [🏫 Antipolo Branch  ⇄ Switch]  ← clickable for Admin/Manager/Supervisor
```

---

## Screen: Branch Management

**Route:** `pos.sunbites.com.ph/references/branches`
**Role:** Admin only
**Layout:** `KitchenLayout`

```
┌──────────────────────────────────────────────────────────┐
│ References > Branches                                    │
├──────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────┐    │
│  │  🏫 Antipolo Branch                             │    │
│  │  Slug: antipolo                                  │    │
│  │  GCash: 09074984172                             │    │
│  │  Students: 45   Staff: 8   Orders Today: 3     │    │
│  │                                           [Edit] │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  ┌─────────────────────────────────────────────────┐    │
│  │  🏫 Iloilo Branch                               │    │
│  │  Slug: iloilo                                    │    │
│  │  GCash: 09922761801                             │    │
│  │  Students: 38   Staff: 6   Orders Today: 2     │    │
│  │                                           [Edit] │    │
│  └─────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Branch cards: `Card` component, white, `border-border`, 16px radius
- Branch name: `text-lg font-bold`
- Meta info (slug, GCash): `text-sm text-muted-foreground`
- Summary stats: `text-sm font-semibold`
- `[Edit]` button: ghost variant — opens Edit Branch Sheet/Dialog
- No "Add Branch" button — branches are fixed (Antipolo + Iloilo)

**Edit Branch Dialog:**
```
┌─────────────────────────────────────────────┐
│ Edit Branch: Antipolo                  [✕]  │
├─────────────────────────────────────────────┤
│  Branch Name *                              │
│  [Antipolo Branch              ]            │
│                                              │
│  GCash Number                               │
│  [09074984172                  ]            │
│                                              │
│  Address (optional)                          │
│  [Antipolo, Rizal              ]            │
│                                              │
│  [Cancel]                [Save Changes]     │
└─────────────────────────────────────────────┘
```

---

## Data Isolation Visual (Conceptual)

Every API request is automatically filtered by the `X-Branch-Id` header via the `SetActiveBranch` middleware and `HasBranch` trait. From the user's perspective:

```
  ┌── Admin sees: ─────────────────────────────┐
  │  🏫 Antipolo   OR   🏫 Iloilo              │
  │  (switches via topbar — never mixed)       │
  └────────────────────────────────────────────┘

  ┌── Manager/Supervisor/Cashier sees: ─────────┐
  │  🏫 Antipolo  (assigned branch only)        │
  │  (no branch switcher shown)                 │
  └────────────────────────────────────────────┘
```

- Scoped data: Students, Orders, POS Menu Items, Inventory, Weekly Meal Plans
- Admin can switch branches and see reports for either branch
