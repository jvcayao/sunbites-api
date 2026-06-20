# Design 07 — Parent Portal

The Parent Portal lives at `portal.sunbites.com.ph`. It is the `~/sunbites-portal` Next.js application. It uses `PortalLayout` — a minimal top nav with no sidebar. Consumer-facing, clean, mobile-friendly.

---

## Screen: Account Activation

**Route:** `portal.sunbites.com.ph/activate?token=...&email=...`
**Layout:** `AuthLayout` (centered card, no nav)

> Parent accounts are **not self-registered**. They are created automatically during enrollment. The parent receives an activation email with a one-time link. This page handles first-time password setup.

```
┌──────────────────────────────────────────────────┐
│  ╭──────╮                                        │
│  │  S   │  Sunbites Kitchen                      │
│  ╰──────╯  School Canteen Portal                 │
│                                                   │
│  Set Your Password                               │
│                                                   │
│  Welcome! Your canteen account is ready.         │
│  Create a password to activate your account.    │
│                                                   │
│  New Password *                                  │
│  [__________________________________]            │
│                                                   │
│  Confirm Password *                              │
│  [__________________________________]            │
│                                                   │
│  [────── Activate Account ──────]               │
└──────────────────────────────────────────────────┘
```

On success: redirect to `/login` with toast "Account activated! You can now log in."

Token is read from the URL query string `?token=...&email=...` (Laravel PasswordBroker format). Expired or invalid token shows an error card with a message: "This activation link has expired or is invalid. Please contact the canteen to resend it."

---

## Screen: Forgot Password (Portal)

**Route:** `portal.sunbites.com.ph/forgot-password`
**Layout:** `AuthLayout`

```
┌──────────────────────────────────────────────────┐
│  ╭──────╮                                        │
│  │  S   │  Sunbites Kitchen                      │
│  ╰──────╯  School Canteen Portal                 │
│                                                   │
│  Reset Your Password                             │
│                                                   │
│  Enter your email and we'll send you a link.     │
│                                                   │
│  Email Address *                                 │
│  [__________________________________]            │
│                                                   │
│  [────── Send Reset Link ──────]                │
│                                                   │
│  ← Back to Sign In                              │
└──────────────────────────────────────────────────┘
```

On submit (any email — valid or not): show generic message "If an account with this email exists, we'll send you a link." This prevents account enumeration. Server sends the appropriate email: activation email if not yet activated, reset email if already activated.

---

## Screen: Login (Portal)

**Route:** `portal.sunbites.com.ph/login`
**Layout:** `AuthLayout`

```
┌──────────────────────────────────────────────────┐
│  ╭──────╮                                        │
│  │  S   │  Sunbites Kitchen                      │
│  ╰──────╯  Parent Portal                         │
│                                                   │
│  Email Address                                    │
│  [__________________________________]             │
│                                                   │
│  Password                                         │
│  [__________________________________]             │
│                                                   │
│  Forgot password?                                 │
│                                                   │
│  [────── Sign In ──────]                         │
│                                                   │
│  ℹ️  Don't have an account?                      │
│     Contact the canteen to get access.           │
└──────────────────────────────────────────────────┘
```

**Not-yet-activated error state:**
```
  ┌────────────────────────────────────────────────┐
  │  ⚠️  Your account has not been activated yet.  │
  │      Check your email for the activation link,  │
  │      or contact the canteen to resend it.       │
  └────────────────────────────────────────────────┘
```
Shown as an orange/amber banner above the form when the API returns `account_not_activated` error.

---

## Screen: Parent Dashboard

**Route:** `portal.sunbites.com.ph/dashboard`
**Layout:** `PortalLayout`

### When No Students Linked

```
┌────────────────────────────────────────────────────────┐
│  [S Sunbites]   Dashboard  Meal Plan  Feedback  [👤 ▾] │
├────────────────────────────────────────────────────────┤
│                                                        │
│         ┌────────────────────────────────────┐        │
│         │  👶  No students linked yet        │        │
│         │                                    │        │
│         │  Link your child to start tracking │        │
│         │  their meals and wallet activity.  │        │
│         │                                    │        │
│         │  [+ Link a Student]                │        │
│         └────────────────────────────────────┘        │
└────────────────────────────────────────────────────────┘
```

### With Students Linked

```
┌────────────────────────────────────────────────────────────┐
│  [S Sunbites]  Dashboard  Meal Plan  Feedback  [👤 Ana ▾]  │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  [Maria Santos ▾]  (dropdown if multiple students)        │
│                                                            │
│  ┌── ⚠️ Outstanding Canteen Credit ───────────────────┐   │
│  │  Your child has an outstanding canteen credit of   │   │
│  │  ₱85.00. Please settle with the canteen office.   │   │
│  └────────────────────────────────────────────────────┘   │
│  (shown only when credit_balance > 0 — red/orange card)   │
│                                                            │
│  ┌─────────────────────────────────────────────────────┐  │
│  │  👛 Wallet Balance                                  │  │
│  │                                                     │  │
│  │              ₱450.00                               │  │
│  │                                                     │  │
│  │  Last top-up: May 9, 2026  +₱500                  │  │
│  │                                                     │  │
│  │  Recent Transactions                               │  │
│  │  05/09  Subscription Meal Tray    -₱135  ₱365     │  │
│  │  05/08  Snack A, Juice            -₱30   ₱395     │  │
│  │  05/07  Subscription Meal Tray    -₱135  ₱425     │  │
│  │  05/06  Subscription Meal Tray    -₱135  ₱455     │  │
│  │  05/05  Wallet Top-Up            +₱500   ₱500     │  │
│  │                                                     │  │
│  │  [Set Alert Threshold]                             │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐    │
│  │ Today        │  │ This Week    │  │ This Month   │    │
│  │ ₱135.00      │  │ ₱405.00      │  │ ₱1,215.00    │    │
│  │ spent today  │  │ spent        │  │ spent        │    │
│  └──────────────┘  └──────────────┘  └──────────────┘    │
│                                                            │
│  ── Today's Purchases ─────────────────────────────────   │
│  11:32 AM   Subscription Meal Tray              ₱135.00  │
│  (no other purchases today)                               │
└────────────────────────────────────────────────────────────┘
```

**Outstanding Credit Alert:**
- `bg-red-50 border-2 border-red-300 rounded-xl p-4 mb-4`
- Warning icon + bold message
- Displayed above wallet card — first thing a parent sees

**Wallet Balance Card:**
- Large card, `border-primary/20`, `bg-primary/3`
- Balance: `text-4xl font-extrabold text-green-600`
- Recent transactions: last 5, compact table
- `[Set Alert Threshold]`: small link button `text-primary text-sm underline`

**Spending Summary Cards (3 cards):**
- Each: white card, icon + amount + label
- Amount: `text-2xl font-extrabold`

---

## Screen: Student Activity

**Route:** `portal.sunbites.com.ph/students/{id}/activity`
**Layout:** `PortalLayout`

```
┌────────────────────────────────────────────────────────────┐
│  Activity — Maria Santos                                   │
├────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────────────────────────────┐   │
│  │  [Today●] [This Week] [This Month] [Custom Range]  │   │
│  └────────────────────────────────────────────────────┘   │
│                                                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐   │
│  │ Total Spent  │  │ Visits      │  │ Avg/Day         │   │
│  │ ₱405.00     │  │ 3 days      │  │ ₱135.00         │   │
│  └─────────────┘  └─────────────┘  └─────────────────┘   │
│                                                            │
│  Most purchased: Subscription Meal Tray                   │
│                                                            │
│  Date       Item Purchased              Qty  Amount  Bal  │
│  05/09/26   Subscription Meal Tray      1    ₱135  ₱365  │
│  05/09/26   Snack A (Bread/Pastry)      1    ₱15   ₱350  │
│  05/08/26   Subscription Meal Tray      1    ₱135  ₱395  │
└────────────────────────────────────────────────────────────┘
```

**Date filter tabs:** pill buttons (Today / This Week / This Month / Custom Range)
Custom Range shows a date range picker.

---

## Screen: Wallet History

**Route:** `portal.sunbites.com.ph/students/{id}/wallet`
**Layout:** `PortalLayout`

```
┌────────────────────────────────────────────────────────────┐
│  Wallet — Maria Santos                                     │
├────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐  ┌──────────┐  │
│  │ Balance         │  │ Total Credited   │  │ Total    │  │
│  │ ₱450.00         │  │ ₱500.00 (all time│  │ Spent    │  │
│  └─────────────────┘  └──────────────────┘  └──────────┘  │
│                                                            │
│  ── Alert Setting ─────────────────────────────────────   │
│  Alert me when balance drops below:                        │
│  [₱ ___100___ ]   [Save]                                  │
│                                                            │
│  Date         Type      Amount     Balance After  Note     │
│  05/09/26    [Credit]  +₱500.00   ₱500.00       GCash TU  │
│  05/09/26    [Debit]   -₱135.00   ₱365.00       POS Order │
│  05/09/26    [Debit]   -₱30.00    ₱335.00       POS Order │
└────────────────────────────────────────────────────────────┘
```

**Type badges:**
```
[Credit] → bg-green-100 text-green-700 border-green-300
[Debit]  → bg-red-100 text-destructive border-red-300
```

Alert threshold: inline input + `[Save]` button via `useMutation`; success toast on save.

---

## Screen: Weekly Meal Plan (Read-Only)

**Route:** `portal.sunbites.com.ph/meal-plan`
**Layout:** `PortalLayout`

Same layout as Meal Planner Editor (month tabs + week tabs + grid) but all cells are plain text — no inputs, no Save/Reset/toggle buttons. Same color-coded column backgrounds. All 5 columns always shown.

```
  [Jun●] [Jul] [Aug] [Sep] [Oct] [Nov] [Dec] [Jan] [Feb] [Mar]
  ← horizontally scrollable on mobile; all 10 months always shown →

  Week: [Week 1●] [Week 2] [Week 3] [Week 4]

  Published week:
  ┌──────┬────────────────┬──────────────┬────────┬──────────┬──────────┐
  │ Day  │ Ulam           │ Vegetables   │ Fruit  │ Soup     │ Snacks   │
  ├──────┼────────────────┼──────────────┼────────┼──────────┼──────────┤
  │ Mon  │ Chicken Adobo  │ Chopsuey     │ Mango  │ Nilaga   │ Crackers │
  │ Tue  │ Pork Sinigang  │ Pinakbet     │ Banana │ Miso     │ Bread    │
  │ Wed  │ Fish Tinola    │ Laing        │ Apple  │ Sinigang │ Biscuit  │
  │ Thu  │ Beef Kaldereta │ Ginisang G.  │ Orange │ Chicken  │ Banana C │
  │ Fri  │ Chicken Inasal │ Ampalaya     │ Waterm.│ Corn     │ Puto     │
  └──────┴────────────────┴──────────────┴────────┴──────────┴──────────┘

  Unpublished week (visible_to_parents = false):
  ┌─────────────────────────────────────────────────────────────────┐
  │  📅  Meal plan for this week is not yet available.              │
  └─────────────────────────────────────────────────────────────────┘
  Card: bg-muted, rounded-xl, p-6, centered text, text-muted-foreground
```

**Month Tab Row:**
- Overflow container: `overflow-x-auto` with `flex gap-2 pb-1`
- All 10 months rendered as pill buttons in school year order: Jun → Jul → Aug → Sep → Oct → Nov → Dec → Jan → Feb → Mar
- Active: `bg-primary text-primary-foreground border-primary`
- Inactive: `bg-background text-foreground border-border`
- `text-xs font-semibold rounded-full border-2 px-3 py-1 whitespace-nowrap`

---

## Screen: Feedback (Parent Portal)

**Route:** `portal.sunbites.com.ph/feedback`
**Layout:** `PortalLayout`

The page has two sections stacked vertically: a submit form at the top, then the parent's previous feedback history below.

### Section 1 — Submit Feedback

```
┌──────────────────────────────────────────────────┐
│  Submit Feedback                                  │
├──────────────────────────────────────────────────┤
│                                                   │
│  About (optional)                                 │
│  [General / Not about a specific student ▾]      │
│                                                   │
│  Category *                                       │
│  [Select a category… ▾]                          │
│    Options: Food Quality / Service /              │
│    Portion Size / Cleanliness / General           │
│                                                   │
│  Rating *                                         │
│  ☆ ☆ ☆ ☆ ☆  (tap stars 1–5)                      │
│                                                   │
│  Message *  (min 10 characters)                   │
│  [________________________________]               │
│  [________________________________]               │
│                                                   │
│                    [Submit Feedback →]            │
└──────────────────────────────────────────────────┘
```

- Star rating: 5 interactive buttons; filled = `text-amber-400`, empty = `text-muted-foreground/30`
- Student selector: dropdown of the parent's linked students; "General" option when no specific student
- Validation: Zod schema; field-level errors on submit; button disabled while pending
- On success: toast "Feedback submitted. Thank you!"; form resets; feedback list invalidated

### Section 2 — My Previous Feedback

```
┌──────────────────────────────────────────────────┐
│  My Previous Feedback                             │
├──────────────────────────────────────────────────┤
│                                                   │
│  ┌────────────────────────────────────────────┐  │
│  │  [Food Quality]          Jun 10, 2026       │  │
│  │  The sinigang was overcooked today…         │  │
│  │                                             │  │
│  │  ┌── Staff Reply ─────────────────────┐    │  │
│  │  │ Thank you for letting us know!     │    │  │
│  │  │ We'll address this tomorrow.       │    │  │
│  │  │                              Jun 11 │    │  │
│  │  └────────────────────────────────────┘    │  │
│  └────────────────────────────────────────────┘  │
│                                                   │
│  ┌────────────────────────────────────────────┐  │
│  │  [Service]               Jun 5, 2026        │  │
│  │  Staff were very accommodating today.       │  │
│  └────────────────────────────────────────────┘  │
│                                                   │
└──────────────────────────────────────────────────┘
```

- Each card: `rounded-xl border bg-card`
- Category badge: colored by category (green = Food Quality, blue = Service, amber = Portion Size, purple = Cleanliness, muted = General)
- Admin reply block: `bg-primary/5 border-primary/20` — shows reply text + replied_at date
- Empty state: dashed border card with `MessageSquare` icon + "No feedback submitted yet."
- Loading state: 2 skeleton cards

---

## Screen: References > Feedback (POS App — Kitchen Staff)

**Route:** `pos.sunbites.com.ph/references/feedback`
**Layout:** `KitchenLayout`
**Roles:** Admin, Manager, Supervisor

```
┌──────────────────────────────────────────────────────────────────┐
│  References                                                       │
│  Feedback                                                         │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  [Search feedback…      ]   [● Unread only]                      │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ [💬]  [Food Quality] [Unread]                            │    │
│  │       The sinigang was overcooked today…                 │    │
│  │       Maria Santos (2024-001) · Jun 10, 2026             │    │
│  └──────────────────────────────────────────────────────────┘    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ [💬]  [Service] [Replied]                                │    │
│  │       Staff were great! Very accommodating…              │    │
│  │       General · Jun 5, 2026                              │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                   │
│  1–25 of 48        [← Prev]  1 / 2  [Next →]                   │
└──────────────────────────────────────────────────────────────────┘
```

- Feedback cards: `rounded-xl border bg-card border-l-4 border-l-destructive`; unread items have `bg-primary/5` tint
- `[Unread]` badge: `bg-primary/10 text-primary border-primary/30`
- `[Replied]` badge: same styling
- Click: opens FeedbackDetailSheet (right-side drawer)
- Search: debounced 300ms, resets to page 1
- "Unread only" toggle: shadcn `Switch`

**FeedbackDetailSheet (right drawer, Sheet component):**

```
┌────── Food Quality Feedback ─────────────────────────┐
│  [Food Quality] [Unread]                              │
│  Food Quality Feedback                                │
│  From: Maria Santos (2024-001) · Jun 10, 2026         │
│                                                        │
│  ┌── Message ─────────────────────────────────────┐  │
│  │ The sinigang was overcooked today and the       │  │
│  │ portion seemed smaller than usual.              │  │
│  └────────────────────────────────────────────────┘  │
│                                                        │
│           [Mark as Read]                               │
│                                                        │
│  Write a Reply                                         │
│  [____________________________________________]        │
│  [____________________________________________]        │
│                                                        │
│                     [Send Reply]                       │
└────────────────────────────────────────────────────────┘
```

- Sheet: `sm:max-w-lg`, scrollable
- Message block: `bg-muted/30 border rounded-lg`
- Existing reply block: `bg-primary/5 border-primary` — shown above reply textarea when `admin_reply` is set; textarea pre-filled
- "Mark as Read" button: shown only when `is_read = false`; hidden after marking
- Textarea: min-length 5, max 2000 characters
- On reply success: toast "Reply sent."; drawer closes; list invalidated
- On mark-read success: toast "Marked as read."; badge removed from card

---

## Screen: Parent Profile

**Route:** `portal.sunbites.com.ph/profile`
**Layout:** `PortalLayout`

```
┌──────────────────────────────────────────────────┐
│  My Profile                                      │
├──────────────────────────────────────────────────┤
│  [📷 Photo upload]                               │
│                                                   │
│  First Name        Last Name                      │
│  [________]        [________]                    │
│  Phone                                            │
│  [__________________________]                    │
│  Address                                          │
│  [__________________________]                    │
│                                                   │
│  ── Change Password ────────────────────────     │
│  Current Password     New Password               │
│  [____________]       [____________]             │
│                                                   │
│  [Save Changes]                                  │
└──────────────────────────────────────────────────┘
```

---

## Screen: Parent Management (POS App — Kitchen Staff)

**Route:** `pos.sunbites.com.ph/references/parents`
**Layout:** `KitchenLayout`
**Roles:** Admin, Manager, Supervisor

Parent accounts are created automatically at enrollment — there is no manual linking queue. This page gives staff a global view of all parent accounts across their active branch.

```
┌──────────────────────────────────────────────────────────────────┐
│ References > Parents                                             │
├──────────────────────────────────────────────────────────────────┤
│  [Search name or email...]   [Activation ▾]   [Branch ▾]        │
├──────────────────────────────────────────────────────────────────┤
│  Name              Email              Status      Linked  Date   │
├──────────────────────────────────────────────────────────────────┤
│  Ana Santos        ana@email.com     [Activated] 2        May 24 │
│  Pedro Cruz        pedro@email.com   [Pending ⏳]  1       Jun 01 │
│  Carla Reyes       carla@email.com   [Disabled 🚫] 1      Jun 05 │
└──────────────────────────────────────────────────────────────────┘
  [← 1  2  3 →]
```

**Status badges:**
- `[Activated ✅]` — `bg-green-100 text-green-700` — `email_verified_at` is set
- `[Pending ⏳]` — `bg-yellow-100 text-amber-700` — activation email sent, not yet activated
- `[Disabled 🚫]` — `bg-red-100 text-destructive` — `disabled_at` is set
- `[Deleted 🗑]` — muted — soft-deleted (`deleted_at` is set)

---

## Screen: Parent Detail (POS App)

**Route:** `pos.sunbites.com.ph/references/parents/{id}` (or side-drawer)
**Layout:** `KitchenLayout`

```
┌──────────────────────────────────────────────────────────────────┐
│ ← Parents     Ana Santos                       [⋮ Actions]      │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Name: Ana Santos            Status: [Activated ✅]             │
│  Email: ana@email.com         Registered: May 24, 2026           │
│  Phone: 09171234567           Last Login: Jun 01, 2026           │
│                                                                  │
│  ── Linked Students ─────────────────────────────────────────   │
│  ┌── Maria Santos ─────────────────────────────────────────┐    │
│  │  Grade 3 – Section Mabini  |  Antipolo Branch  [View →] │    │
│  └──────────────────────────────────────────────────────────┘   │
│  ┌── Carlos Santos ────────────────────────────────────────┐    │
│  │  Grade 1 – Section Luna    |  Antipolo Branch  [View →] │    │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

**`[⋮ Actions]` dropdown (Admin/Manager only):**
- `Resend Activation Email` — visible only when `email_verified_at` is null; rate-limited max 3/24hr
- `Send Password Reset Email` — visible only when `email_verified_at` is not null
- `Disable Account` — sets `disabled_at`; grayed out if already disabled
- `Enable Account` — clears `disabled_at`; grayed out if not disabled
- `Delete Account` — soft-deletes; shows confirmation dialog
- `Restore Account` — visible only on soft-deleted parents; clears `deleted_at`

Supervisor role: sees `Resend Activation Email` only — no disable/enable/delete/restore actions.
