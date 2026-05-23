# Design 01 — Project Foundation

## Design System

### Color Tokens (Tailwind v4 `@theme`)

| Token | Value | Usage |
|---|---|---|
| `--primary` | `oklch(0.577 0.245 27.325)` | Buttons, active nav, links — tomato red |
| `--primary-foreground` | `oklch(0.971 0.013 17.38)` | Text on primary backgrounds |
| `--background` | `oklch(1 0 0)` | Page white |
| `--foreground` | `oklch(0.141 0.005 285.823)` | Body text |
| `--muted` | `oklch(0.967 0.001 286.375)` | Subtle backgrounds |
| `--muted-foreground` | `oklch(0.556 0.014 285.938)` | Labels, helper text |
| `--border` | `oklch(0.92 0.004 286.32)` | Card and input borders |
| `--sidebar` | `oklch(0.985 0 0)` | Sidebar background |
| `--sidebar-primary` | `oklch(0.577 0.245 27.325)` | Sidebar active item |

### Typography (Poppins — Google Fonts)

| Usage | Size | Weight | Tailwind Class |
|---|---|---|---|
| Badge / label | 11–12px | 700 | `text-xs font-bold` |
| Table rows / body | 13–14px | 400 / 600 | `text-sm` |
| Standard text | 16px | 400 | `text-base` |
| Page headings | 18–20px | 700 | `text-lg font-bold` |
| Stat card numbers | 24px | 800 | `text-2xl font-extrabold` |
| Auth page title | 28px | 800 | `text-3xl font-extrabold` |

---

## Screen: Login — POS App (`pos.sunbites.com.ph/login`)

Layout: `AuthLayout` — centered card, no sidebar, no topbar.

```
┌─────────────────── viewport ───────────────────┐
│                                                 │
│          ┌──────────────────────────┐           │
│          │       ╭─────────╮       │           │
│          │       │    S    │       │           │
│          │       ╰─────────╯       │           │
│          │      Sunbites Kitchen    │           │
│          │  School Canteen System   │           │
│          │                          │           │
│          │  Email Address           │           │
│          │  ┌──────────────────┐   │           │
│          │  │ email@domain.com │   │           │
│          │  └──────────────────┘   │           │
│          │                          │           │
│          │  Password                │           │
│          │  ┌──────────────────┐   │           │
│          │  │ ••••••••••       │   │           │
│          │  └──────────────────┘   │           │
│          │                          │           │
│          │  ┌──────────────────┐   │           │
│          │  │    Sign In →     │   │           │  ← bg-primary, full width
│          │  └──────────────────┘   │           │
│          └──────────────────────────┘           │
│                                                 │
└─────────────────────────────────────────────────┘
```

**Details:**
- Card: white, `rounded-2xl`, `shadow-lg`, max-width 420px
- Logo: `<AppLogo variant="icon" />` — 72×72px circle, brand red fill
- Title: `text-3xl font-extrabold text-primary`
- Subtitle: `text-sm text-muted-foreground`
- Error banner: `bg-destructive/10 text-destructive rounded-md px-4 py-2` — shown only on login failure
- Sign In button: full-width, primary background, 14px vertical padding
- Enter key on either field submits the form

**States:**
- Default: clean card
- Error: error banner above inputs — "Invalid email or password."
- No-branch: "Your account has no branch assigned. Contact your administrator."
- Loading: button disabled, spinner icon

---

## Screen: Branch Selector — POS App (`pos.sunbites.com.ph/branch`)

Shown after login when a staff member has access to multiple branches. Uses the same `AuthLayout`.

```
┌─────────────────── viewport ───────────────────┐
│                                                 │
│              ╭─────────╮                       │
│              │    S    │                       │
│              ╰─────────╯                       │
│            Sunbites Kitchen                     │
│         Welcome back, [Name]!                   │
│      Please select your branch                  │
│                                                 │
│   ┌───────────────┐   ┌───────────────┐        │
│   │      🏫       │   │      🏫       │        │
│   │               │   │               │        │
│   │   Antipolo    │   │    Iloilo     │        │
│   │    Branch     │   │    Branch     │        │
│   └───────────────┘   └───────────────┘        │
│                                                 │
└─────────────────────────────────────────────────┘
```

**Details:**
- Branch cards: white background, `border-2 border-primary`, `rounded-xl`, 220px wide
- Hover: `bg-primary/5`
- Branch name: `text-2xl font-extrabold text-primary`
- "Branch" label: `text-sm text-muted-foreground`
- On select: stores active branch in Zustand, API call to set active branch, redirects to dashboard

---

## Screen: KitchenLayout Shell — POS App

```
┌──────────┬──────────────────────────────────────────┐
│ Sunbites │  Students        [🏫 Antipolo ⇄] [👤 Admin] │
│ Kitchen  │──────────────────────────────────────────│
│──────────│                                          │
│ ANTIPOLO │                                          │
│  BRANCH  │         [page content renders here]      │
│──────────│                                          │
│📋 Enrollment                                        │
│👥 Students                                          │
│🛒 POS                                               │
│                                                     │
│  ── Reports ──                                      │
│  ⊞ Dashboard                                        │
│  💳 Sales                                           │
│  👥 Student Report                                  │
│  👛 Wallet Report                                   │
│  📦 Inventory Report                               │
│  🧾 Daily Summary                                   │
│                                                     │
│  ── References ──                                   │
│  👨‍💼 Users                                           │
│  🏫 Branches                                        │
│  🍽️ Meal Planner                                    │
│  📦 Inventory                                       │
│  📋 Activity Log                                    │
│                                                     │
│  ◀  [Logout]                                        │
└──────────┴──────────────────────────────────────────┘
  220px          flex-1
```

**Sidebar (expanded — 220px):**
- Background: `--sidebar` (near white)
- Right border: `1px solid var(--border)`
- Logo area: `<AppLogo variant="full" />`
- Branch indicator: `text-xs font-bold text-primary bg-primary/5 rounded px-2 py-1`
- Nav items default: `text-sm text-muted-foreground`
- Nav item active: `bg-primary/10 text-primary font-bold border-l-[3px] border-primary`
- Section headers: `text-xs font-extrabold text-muted-foreground uppercase tracking-wider`
- Collapse toggle: bottom of sidebar, small arrow button
- Logout: `text-destructive` bordered button

**Sidebar (collapsed — 60px):**
- Icons only, text hidden
- Logo: `<AppLogo variant="icon" />` (40×40px)
- Branch pill hidden
- Logout shows icon only

**Topbar (56px):**
- Background: white, bottom border `--border`
- Left: page title `text-lg font-bold text-primary`
- Right: branch switcher pill (admin only) + `text-sm text-muted-foreground` user name + role badge

---

## Screen: PortalLayout Shell — Parent Portal

```
┌──────────────────────────────────────────────────┐
│  [S] Sunbites    Dashboard   Menu   Wallet  [👤 ▾] │
├──────────────────────────────────────────────────┤
│                                                   │
│              [page content here]                  │
│                                                   │
└──────────────────────────────────────────────────┘
```

**Details:**
- Top nav: white, `border-b border-border`, height 56px
- Logo left: `<AppLogo variant="full" />`
- Nav links center (hidden on mobile)
- User dropdown right: display name + logout
- Mobile: hamburger → slide-down nav drawer

---

## Screen: AuthLayout Shell

Used by both apps for login pages.

```
┌─────────────────── viewport ───────────────────┐
│                                                 │
│           [centered content here]              │
│                                                 │
└─────────────────────────────────────────────────┘
```

- White page background
- No nav, no sidebar
- `<AppLogo />` inside the card, above the form fields

---

## Component: AppLogo

**Full variant** (sidebar expanded, auth pages):
- Circle icon 36×36px: primary fill, inner "S" in white
- Wordmark "Sunbites": `font-extrabold text-primary`
- Tagline "Your Healthy Kitchen": `text-[10px] text-muted-foreground`
- Rendered inline (SVG-based React component)

**Icon variant** (sidebar collapsed):
- Circle only: 40×40px, primary fill, white "S"

---

## Toast System

Position: `top-center` via Sonner `<Toaster position="top-center" />`

```
         ┌───────────────────────────────┐
         │ ✅  Student enrolled!          │  ← success: green
         └───────────────────────────────┘

         ┌───────────────────────────────┐
         │ ❌  Invalid email or password. │  ← error: destructive red
         └───────────────────────────────┘

         ┌───────────────────────────────┐
         │ ⚠️  Wallet balance is low: ₱20 │  ← warning: amber
         └───────────────────────────────┘
```

Triggered from `useMutation` callbacks and the API error handler — not from server-sent flash messages.
