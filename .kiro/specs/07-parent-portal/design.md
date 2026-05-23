# Design 07 — Parent Portal

The Parent Portal lives at `portal.sunbites.com.ph`. It is the `~/sunbites-portal` Next.js application. It uses `PortalLayout` — a minimal top nav with no sidebar. Consumer-facing, clean, mobile-friendly.

---

## Screen: Registration

**Route:** `portal.sunbites.com.ph/register`
**Layout:** `AuthLayout` (centered card, no nav)

```
┌──────────────────────────────────────────────────┐
│  ╭──────╮                                        │
│  │  S   │  Sunbites Kitchen                      │
│  ╰──────╯  School Canteen Portal                 │
│                                                   │
│  Create Your Parent Account                       │
│                                                   │
│  First Name *           Last Name *              │
│  [______________]       [______________]          │
│                                                   │
│  Email Address *                                  │
│  [__________________________________]             │
│                                                   │
│  Phone Number                                     │
│  [__________________________________]             │
│                                                   │
│  Password *             Confirm Password *        │
│  [______________]       [______________]          │
│                                                   │
│  [────── Create Account ──────]                  │
│                                                   │
│  Already have an account?  Sign In               │
└──────────────────────────────────────────────────┘
```

After registration: redirect to email verification notice page.

**Email Verification Notice:**
```
┌──────────────────────────────────────────────────┐
│  ╭──────╮                                        │
│  │  S   │                                        │
│  ╰──────╯                                        │
│                                                   │
│  ✉️  Verify Your Email                            │
│                                                   │
│  We've sent a verification link to:              │
│  ana@email.com                                    │
│                                                   │
│  Click the link in your email to continue.       │
│                                                   │
│  [Resend Verification Email]                      │
└──────────────────────────────────────────────────┘
```

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
│  Don't have an account?  Register                │
└──────────────────────────────────────────────────┘
```

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

## Screen: Student Linking Request

**Route:** `portal.sunbites.com.ph/link-student`

```
┌──────────────────────────────────────────────────────────┐
│  Link a Student                                          │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  Branch *                                                │
│  (●) 🏫 Antipolo Branch   ( ) 🏫 Iloilo Branch         │
│                                                          │
│  Student Name or Student Number *                        │
│  [______________________________]                        │
│  [search results dropdown...]                            │
│                                                          │
│  Relationship *                                          │
│  [Mother ▾]                                              │
│  (Mother / Father / Legal Guardian / Other)             │
│                                                          │
│  [Submit Request]                                        │
└──────────────────────────────────────────────────────────┘
```

Search results show minimal data only — partial first name, grade level, branch.

After submission:
```
  ✅ Your request has been submitted.
     You will be notified once approved.
```

**Pending requests on dashboard:**
```
  ┌── Linked Students ──────────────────────────────────┐
  │  Maria Santos         [Approved ✅]  Grade 3 Mabini  │
  │  Carlos Pendiente     [Pending ⏳]   Awaiting review │
  └──────────────────────────────────────────────────────┘
```

---

## Screen: Weekly Meal Plan (Read-Only)

**Route:** `portal.sunbites.com.ph/meal-plan`
**Layout:** `PortalLayout`

Same layout as Meal Planner Editor (month tabs + week tabs + grid) but all cells are plain text — no inputs, no Save/Reset buttons. Same color-coded column backgrounds.

```
  [Jun●] [Jul] [Aug] [Sep] ... [Mar]
  Week: [Week 1●] [Week 2] [Week 3] [Week 4]

  ┌──────┬────────────────┬──────────────┬────────┬──────────┐
  │ Day  │ Ulam           │ Vegetables   │ Fruit  │ Soup     │
  ├──────┼────────────────┼──────────────┼────────┼──────────┤
  │ Mon  │ Chicken Adobo  │ Chopsuey     │ Mango  │ Nilaga   │
  │ Tue  │ Pork Sinigang  │ Pinakbet     │ Banana │ Miso     │
  │ Wed  │ Fish Tinola    │ Laing        │ Apple  │ Sinigang │
  │ Thu  │ Beef Kaldereta │ Ginisang G.  │ Orange │ Chicken  │
  │ Fri  │ Chicken Inasal │ Ampalaya     │ Waterm.│ Corn     │
  └──────┴────────────────┴──────────────┴────────┴──────────┘
```

---

## Screen: Feedback

**Route:** `portal.sunbites.com.ph/feedback`
**Layout:** `PortalLayout`

```
┌──────────────────────────────────────────────────┐
│  💬 Send Feedback                                │
├──────────────────────────────────────────────────┤
│                                                   │
│  Rating *                                         │
│  ☆ ☆ ☆ ☆ ☆  (tap stars 1–5)                      │
│                                                   │
│  Category *                                       │
│  [Food Quality ▾]                                │
│                                                   │
│  Student (optional)                               │
│  [Maria Santos ▾]                                │
│                                                   │
│  Message (optional)                               │
│  [________________________________]               │
│                                                   │
│  [─── Submit Feedback ───]                       │
└──────────────────────────────────────────────────┘
```

Star rating: 5 clickable stars, filled = `text-yellow-400`, empty = `text-muted`

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

## Screen: Link Requests Review (POS App — Kitchen Staff)

**Route:** `pos.sunbites.com.ph/references/link-requests`
**Layout:** `KitchenLayout`
**Sidebar badge:** unread count on "Link Requests"

```
┌──────────────────────────────────────────────────────────────────┐
│ References > Link Requests                                       │
├──────────────────────────────────────────────────────────────────┤
│  Parent Name        Email             Student       Status  Actions │
│  Ana Santos        ana@email.com     Maria Santos  [Pending] [✓][✕] │
│  Pedro dela Cruz   pedro@email.com   Juan Cruz     [Approved ✅]     │
│  Carla Reyes       carla@email.com   Sofia Reyes   [Rejected ✕]      │
└──────────────────────────────────────────────────────────────────┘
```

**Approve:** creates `parent_student` record, sends email, badge decrements
**Reject `[✕]`:** opens dialog requiring reason input before confirming
