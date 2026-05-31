# Design 08 — Reports & Dashboard

---

## Screen: Dashboard

**Route:** `pos.sunbites.com.ph/dashboard`
**Nav item:** ⊞ Dashboard
**Layout:** `KitchenLayout`
**Roles:** Admin, Manager, Supervisor

```
┌──────────────────────────────────────────────────────────────────┐
│ ⊞ Dashboard                   [🏫 Antipolo Branch ⇄]  [👤 Admin]│
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Today's Overview                                                │
│  05/09/2026                                                      │
│                                                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────┐│
│  │  👥           │  │  ✅           │  │  🍱           │  │ 💰  ││
│  │    45         │  │    38         │  │    3          │  │₱405 ││
│  │  Total Stu.   │  │  Enrolled     │  │  Meals Today  │  │Rev. ││
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────┘│
│                                                                  │
│  ┌──────────────┐  ┌──────────────┐                            │
│  │  🚶           │  │  👛           │                           │
│  │    2          │  │    1          │                           │
│  │  Walk-Ins     │  │  Wallet Txns  │                           │
│  └──────────────┘  └──────────────┘                            │
│                                                                  │
│  ┌── 👨‍💼 Today's Staff Roster ──────────────────────────────┐   │
│  │  3 staff · 05/09/2026                                   │   │
│  │                                                         │   │
│  │  ┌──────────────────────┐  ┌──────────────────────┐    │   │
│  │  │  👤 Joy Mendoza       │  │  👤 Mark Reyes        │    │   │
│  │  │  Canteen Staff        │  │  Cashier              │    │   │
│  │  │  [🟢 Working ▾]      │  │  [🟢 Working ▾]      │    │   │
│  │  └──────────────────────┘  └──────────────────────┘    │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ┌── 🍱 Today's Transactions ─────────────────────────────────┐ │
│  │  Time    Receipt #        Customer         Items      Total │ │
│  │  11:32   ANT-2025-001001  Maria Santos     [Sub Meal] ₱135  │ │
│  │  11:45   ANT-2025-001002  Juan dela Cruz   [Sub][Juice]₱150 │ │
│  │  12:01   ANT-2025-001003  Walk-In          [Snack A]  ₱15   │ │
│  │                        [→ View Full Transaction History]    │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌── ⚠️ Low Stock Alerts ─────────────────────────────────────┐ │
│  │  Graham Crackers  12 packs  threshold 15  [LOW ⚠]         │ │
│  │  Bread Roll        0 pieces threshold 10  [OUT ✕]          │ │
│  │  Banana Cue        5 pieces threshold 10  [LOW ⚠]          │ │
│  │                       [→ Go to Inventory Management]        │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌── 💳 Outstanding Credit ───────────────────────────────────┐ │
│  │  Maria Santos   Gr. 3   ₱85.00   [→ View Student]         │ │
│  │  Juan dela Cruz Gr. 5   ₱135.00  [→ View Student]         │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌── 🏆 Top Items Today ──────────────────────────────────────┐ │
│  │  1. Subscription Meal Tray     — 3 orders                  │ │
│  │  2. Snack C (Juice/Water)      — 1 order                   │ │
│  │  3. Snack A (Bread/Pastry)     — 1 order                   │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

**Stat Cards:**
- White cards, `border-border`, 16px radius
- Number: `text-3xl font-extrabold` — colored per metric:
  - Students: `text-primary`
  - Enrolled: `text-green-600`
  - Meals / Walk-Ins: `text-purple-600`
  - Revenue: `text-blue-600`
  - Wallet: `text-violet-600`
- Label: `text-xs text-muted-foreground`
- Grid: `grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4`

**Staff Roster Cards:**
- Status dropdown: inline `<Select>` from shadcn/ui; updates immediately via `useMutation`
- Status badge colors (displayed inside card):
  - Working 🟢 — `bg-green-50 border-green-600`
  - Off ⚫ — `bg-muted`
  - On Leave 🔵 — `bg-blue-50 border-blue-600`
  - Emergency 🔴 — `bg-red-50 border-destructive`
  - On Break 🟡 — `bg-yellow-50 border-yellow-600`

**Outstanding Credit Alerts:**
- `bg-orange-50 border-orange-300` card style
- Each row: student name + grade + credit amount + "View Student" link
- Hidden entirely when no students have outstanding credit

---

## Screen: Sales Report

**Route:** `pos.sunbites.com.ph/reports/sales`
**Layout:** `KitchenLayout`

```
┌───────────────────────────────────────────────────────────────────┐
│ Sales Report                               [Export to Excel 📥]  │
├───────────────────────────────────────────────────────────────────┤
│  ┌── Filters ────────────────────────────────────────────────┐   │
│  │  [Today●] [This Week] [This Month] [Last Month] [Custom]  │   │
│  │                                                           │   │
│  │  Payment [All ▾]   Customer [All ▾]   Cashier [All ▾]    │   │
│  └────────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌── Summary ─────────────────────────────────────────────────┐  │
│  │  ┌────────────────┐  ┌────────────────┐  ┌──────────────┐ │  │
│  │  │ Total Revenue   │  │ Total Orders   │  │ Avg Order    │ │  │
│  │  │   ₱1,350.00     │  │    10          │  │   ₱135.00    │ │  │
│  │  └────────────────┘  └────────────────┘  └──────────────┘ │  │
│  │  ┌────────────────┐  ┌────────────────┐                    │  │
│  │  │ Total Discounts │  │ Net Revenue    │                    │  │
│  │  │   ₱0.00         │  │   ₱1,350.00    │                    │  │
│  │  └────────────────┘  └────────────────┘                    │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  Receipt #       Date/Time    Cashier  Customer       Pay   Total │
│  ANT-2025-001001  05/09 11:32  Juan C.  Maria Santos   Cash  ₱135 │
│  ANT-2025-001002  05/09 11:45  Juan C.  Juan dela Cruz GCash ₱150 │
│  ANT-2025-001003  05/09 12:01  Juan C.  Walk-In        Cash  ₱15  │
│                                                                   │
│  [← 1  2  3 →]                     Total: ₱1,350.00             │
└───────────────────────────────────────────────────────────────────┘
```

**Date range pills:** Active = `bg-primary text-primary-foreground`
**Export button:** top right, Admin/Manager only — hidden for Supervisor
**Payment method column badges:** Cash / GCash / Wallet (same as POS history)

---

## Screen: Student Report

**Route:** `pos.sunbites.com.ph/reports/students`

```
┌───────────────────────────────────────────────────────────────────┐
│ Student Report                             [Export to Excel 📥]  │
├───────────────────────────────────────────────────────────────────┤
│  Filters: [Enrollment Status ▾]  [Grade Level ▾]  [Type ▾]     │
├───────────────────────────────────────────────────────────────────┤
│  ┌── Summary ─────────────────────────────────────────────────┐  │
│  │  Total Enrolled: 45                                        │  │
│  │                                                            │  │
│  │  By Grade Level:                                           │  │
│  │  Grade 1: 8    Grade 2: 7    Grade 3: 10   Grade 4: 9     │  │
│  │  Grade 5: 11                                               │  │
│  │                                                            │  │
│  │  By Enrollment Status:                                     │  │
│  │  Enrolled: 38   Paused: 3   Unenrolled: 2   Banned: 2     │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  Name          Std No.  Grade  Section  Status   Wallet  Spent   │
│  Maria Santos  STU-001  Gr. 3  Mabini   [Enroll] ₱450   ₱270    │
│  Juan Cruz     STU-002  Gr. 5  Bonif.   [Enroll] ₱200   ₱150    │
│  Sofia Reyes   STU-003  Gr. 1  Luna     [Paused] ₱600   ₱405    │
└───────────────────────────────────────────────────────────────────┘
```

**Summary section:** plain text counts — no charts or graphs.

---

## Screen: Wallet Report

**Route:** `pos.sunbites.com.ph/reports/wallet`
**Roles:** Admin, Manager

```
┌───────────────────────────────────────────────────────────────────┐
│ Wallet Report                              [Export to Excel 📥]  │
│  Date range [This Month ▾]                                       │
├───────────────────────────────────────────────────────────────────┤
│  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────┐ │
│  │ Total Credits    │  │ Total Debits     │  │ Net Movement   │ │
│  │  ₱25,000.00      │  │  ₱18,900.00      │  │  +₱6,100.00    │ │
│  └──────────────────┘  └──────────────────┘  └────────────────┘ │
│                                                                   │
│  ⚠️ Students with balance below ₱100:                            │
│  Juan dela Cruz — ₱55.00   |   Carlo Mendoza — ₱80.00           │
│                                                                   │
│  Name          Grade   Balance   Credited   Debited   Last Txn  │
│  Maria Santos  Gr. 3   ₱450.00  ₱500.00   ₱270.00   05/09     │
│  Juan Cruz     Gr. 5    ₱55.00  ₱200.00   ₱150.00   05/08     │
└───────────────────────────────────────────────────────────────────┘
```

**Low balance warning:** `bg-yellow-50 border-yellow-300 rounded-xl px-4 py-3 text-sm font-semibold text-amber-800`

---

## Screen: Inventory Report

**Route:** `pos.sunbites.com.ph/reports/inventory`
**Roles:** Admin, Manager, Supervisor

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Inventory Report                                    [Export to Excel 📥]     │
│                                  (Admin/Manager only — hidden for Supervisor) │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌── Summary ──────────────────────────────────────────────────────────┐    │
│  │  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐        │    │
│  │  │  OUT of Stock  │  │  Below Alert   │  │  Overstocked   │        │    │
│  │  │       1        │  │       2        │  │       1        │        │    │
│  │  │  (red number)  │  │ (amber number) │  │(orange number) │        │    │
│  │  └────────────────┘  └────────────────┘  └────────────────┘        │    │
│  │  Overstock card hidden when no items have overstock_threshold set   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│  ── Current Stock Snapshot ────────────────  Status filter: [All ▾]        │
│                                                                              │
│  Item               Stock  Unit   Low Alert  Overstock  Cost/Unit  Status        Last Restocked │
│  Juice Tetra Pack   48     piece  20         100        ₱12.00     [OK ✓]        05/01/2026     │
│  Graham Crackers    12     pack   15         —          —          [LOW ⚠]       05/05/2026     │
│  Bread Roll          0     piece  10         —          —          [OUT ✕]       04/28/2026     │
│  Biscuit            60     pack   20         50         ₱8.00      [OVER ▲]      05/03/2026     │
│                                                                              │
│  ── Movement History ──────────────────────────────────────────────────    │
│                                                                              │
│  [Today] [This Week] [This Month●] [Custom: From ____ To ____]              │
│  Type [All ▾]   Item [All ▾]                                                │
│                                                                              │
│  Date/Time           Item               Type       Change  After   Reason   Order │
│  2026-06-03 09:00    Juice Tetra Pack   [Restock]  +24     72      Delivery  —    │  ← bg-green-50
│  2026-06-02 12:30    Juice Tetra Pack   [Sale]     −1      48      Order#001 #001 │  ← bg-red-50
│  2026-06-02 10:15    Graham Crackers    [Waste]    −3      12      Spoilage  —    │  ← bg-red-50
│  2026-06-01 08:00    Bread Roll         [Restock]  +30     30      Initial   —    │  ← bg-green-50
│                                                                              │
│  Showing 1–25 of 48 entries                   [← Prev]  [Next →]            │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Summary cards:**
- OUT count: `text-destructive font-extrabold text-3xl`
- LOW count: `text-amber-700 font-extrabold text-3xl`
- OVER count: `text-orange-700 font-extrabold text-3xl` (card hidden when no overstock_threshold configured)

**Status badges in snapshot table:**
```
[OK ✓]   → bg-green-100 text-green-700 border-green-300
[LOW ⚠]  → bg-yellow-100 text-amber-700 border-yellow-300
[OUT ✕]  → bg-red-100 text-destructive border-red-300
[OVER ▲] → bg-orange-100 text-orange-700 border-orange-300
```

**Movement history log type badges:**
```
[Restock]  → bg-green-100 text-green-700
[Sale]     → bg-red-100 text-destructive
[Waste]    → bg-red-100 text-destructive
[Manual]   → bg-muted text-muted-foreground
```

**Row color coding in history table:**
- Restock rows: `bg-green-50`
- Sale / Waste rows: `bg-red-50`
- Manual rows: `bg-muted/30`

```
│  ── Discrepancy Summary ───────────────────────────────────────────    │
│  (Manual adjustments only — same date range as Movement History above) │
│                                                                         │
│  Item               Adjustments   Net Change   Last Adjusted           │
│  Graham Crackers    3             −15 packs    2026-06-03              │  ← bg-red-50  (net negative)
│  Bread Roll         1             +10 pieces   2026-06-01              │  ← bg-green-50 (net positive)
│                                                                         │
│  ℹ️  Manual adjustments indicate stock corrections. High frequency or  │
│     large negative values may suggest unrecorded loss or waste.        │
│                                                                         │
│  (Empty state when no manual adjustments in range:)                    │
│  No manual adjustments recorded for this period.                       │
└─────────────────────────────────────────────────────────────────────────┘
```

**Discrepancy table:**
- Net negative (quantity_change sum < 0): row `bg-red-50`, net change shown in `text-destructive`
- Net positive (quantity_change sum > 0): row `bg-green-50`, net change shown in `text-green-700`
- "# Adjustments" column: high count (≥ 3) shown in `text-amber-700 font-semibold` as a soft warning
- Info note below table: muted text explaining what manual adjustments indicate

**Export button:** Admin/Manager only — Supervisor sees report but not the export button (enforced server-side too)
- Export generates three sheets: "Current Stock", "Movement History", "Discrepancy"

---

## Screen: Daily Summary (End-of-Day)

**Route:** `pos.sunbites.com.ph/reports/daily-summary`
**Roles:** Admin, Manager

```
┌──────────────────────────────────────────────────────────┐
│  Date [05/09/2026 ▾]              [🖨️ Print Summary]    │
├──────────────────────────────────────────────────────────┤
│                                                          │
│           ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━           │
│                     SUNBITES KITCHEN                     │
│                   ANTIPOLO BRANCH                        │
│                END-OF-DAY SUMMARY                        │
│                   May 9, 2026                            │
│           ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━           │
│                                                          │
│  TOTAL ORDERS:          10                               │
│                                                          │
│  BY PAYMENT METHOD:                                      │
│  Cash:              6 orders    ₱810.00                  │
│  GCash:             3 orders    ₱405.00                  │
│  Wallet:            1 order     ₱135.00                  │
│                                                          │
│  DISCOUNTS APPLIED:              ₱0.00                  │
│  ────────────────────────────────────                   │
│  TOTAL REVENUE:                  ₱1,350.00               │
│                                                          │
│  PER-CASHIER BREAKDOWN:                                  │
│  Juan Cashier:    8 orders    ₱1,080.00                  │
│  Ana Cashier:     2 orders    ₱270.00                    │
│                                                          │
│  ITEMS SOLD:                                             │
│  Subscription Meal Tray    8                             │
│  Snack A (Bread/Pastry)    3                             │
│  Snack C (Juice/Water)     2                             │
│  Additional Rice           1                             │
│           ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━           │
└──────────────────────────────────────────────────────────┘
```

**Print layout:** `@media print` hides sidebar, topbar, date picker, print button — only the summary box prints.

---

## Screen: Activity Log

**Route:** `pos.sunbites.com.ph/reports/activity`
**Roles:** Admin, Manager only

```
┌───────────────────────────────────────────────────────────────────┐
│ 📋 Activity Log                                                   │
├───────────────────────────────────────────────────────────────────┤
│  [Today●] [This Week] [This Month] [Custom]                       │
│  User [All Staff ▾]   Category [All ▾]   [Search action...]      │
├───────────────────────────────────────────────────────────────────┤
│  Date/Time      User            Category       Action             │
│  05/10 09:12   Juan Cashier   [POS 🟢]       Order ANT-2025-001010│
│  05/10 09:10   Ana Manager    [Students 🟠]  Status changed →    │
│                                               Enrolled            │
│  05/10 08:55   Juan Cashier   [Auth 🔵]      Logged in           │
│  05/10 08:30   Admin          [Wallet 🟣]    Top-up ₱500 for     │
│                                               Maria Santos        │
│                               [→ 1  2  3 ...]                     │
└───────────────────────────────────────────────────────────────────┘
```

**Category badge colors:**
```
[Auth 🔵]       — bg-blue-100 text-blue-700
[POS 🟢]        — bg-green-100 text-green-700
[Students 🟠]   — bg-orange-100 text-orange-700
[Wallet 🟣]     — bg-purple-100 text-purple-700
[Payments 🟡]   — bg-yellow-100 text-amber-700
[Menu 🩷]       — bg-pink-100 text-pink-700
[Inventory ⚫]  — bg-gray-100 text-gray-700
[Users 🔴]      — bg-red-100 text-destructive
```

**Expandable detail row (click any row):**
```
  ▼  05/10 09:12  Juan Cashier  [POS 🟢]  Order created
     Subject: Order #ANT-2025-001010
     ┌── Properties ────────────────────────────────┐
     │  total:          ₱135.00                     │
     │  payment_method: wallet                      │
     │  student:        Maria Santos (STU-001)      │
     │  branch:         Antipolo                    │
     └──────────────────────────────────────────────┘
```

- `properties` shown as a clean key-value list (not raw JSON)
- No actions on this page — read-only, immutable

---

## Screen: Subscription Billing Report

**Route:** `pos.sunbites.com.ph/reports/billing`
**Roles:** Admin, Manager, Supervisor

```
┌───────────────────────────────────────────────────────────────────┐
│ Subscription Billing                       [Export to Excel 📥]  │
├───────────────────────────────────────────────────────────────────┤
│  Filters: [Year 2026 ▾]  [Month: June ▾]  [Status: All ▾]  [Grade ▾] │
├───────────────────────────────────────────────────────────────────┤
│  ┌── Summary ─────────────────────────────────────────────────┐  │
│  │  ┌────────────────┐  ┌────────────────┐  ┌──────────────┐ │  │
│  │  │ Total Sub.      │  │ Collected       │  │ Outstanding  │ │  │
│  │  │    38           │  │   ₱4,185.00     │  │  ₱945.00     │ │  │
│  │  └────────────────┘  └────────────────┘  └──────────────┘ │  │
│  │  Collection Rate: 81.6%                                    │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  Student         Std No.  Grade   Month    Amount  Status  Paid On│
│  Maria Santos   STU-001  Gr. 3   June 26  ₱135  [Paid✓]  05/02  │
│  Juan dela Cruz STU-002  Gr. 5   June 26  ₱135  [Unpaid] —      │
│  Sofia Reyes    STU-003  Gr. 1   June 26  ₱135  [Paid✓]  05/01  │
│                                                                   │
│  [← 1  2  3 →]                              Sorted: Unpaid first │
└───────────────────────────────────────────────────────────────────┘
```

**Status badges:**
- Paid — `bg-green-50 border-green-600 text-green-700`
- Unpaid — `bg-red-50 border-destructive text-destructive`

**Export button:** Admin/Manager only — hidden for Supervisor (also enforced server-side).

---

## Screen: Credit Collection Report

**Route:** `pos.sunbites.com.ph/reports/credits`
**Roles:** Admin, Manager only — Supervisor excluded

```
┌───────────────────────────────────────────────────────────────────┐
│ 💳 Credit Collection                                              │
├───────────────────────────────────────────────────────────────────┤
│  [Today] [This Week] [This Month●] [Custom]                       │
│  Type [All ▾]   Student [Search by name or number...]            │
├───────────────────────────────────────────────────────────────────┤
│  ┌── Summary ─────────────────────────────────────────────────┐  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐ │  │
│  │  │ Charged       │  │ Settled       │  │ Net Outstanding  │ │  │
│  │  │  ₱1,350.00    │  │  ₱810.00      │  │  ₱540.00         │ │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────┘ │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  Date/Time     Student          Grade  Type       Amount  Order # │
│  05/10 12:01  Maria Santos     Gr. 3  [Charged🔴] ₱135   #001012 │
│  05/10 14:30  Maria Santos     Gr. 3  [Settled🟢] ₱135   —       │
│  05/09 11:45  Juan dela Cruz   Gr. 5  [Charged🔴] ₱270   #001008 │
│  05/09 09:10  Sofia Reyes      Gr. 1  [Voided⚫]  ₱135   #001005 │
│                                                                   │
│  [← 1  2  3 →]                           Sorted: Newest first    │
└───────────────────────────────────────────────────────────────────┘
```

**Type badges:**
- Charged — `bg-red-50 border-destructive text-destructive`
- Settled — `bg-green-50 border-green-600 text-green-700`
- Voided — `bg-muted text-muted-foreground`

No export button — credit audit trail stays in-system only. No delete or edit actions.

---

## Sidebar Navigation for Reports

Under a "Reports" group in `KitchenLayout` sidebar:
```
  ── Reports ──
  📊 Dashboard
  💳 Sales Report
  👥 Student Report
  👛 Wallet Report
  📦 Inventory Report
  🧾 Daily Summary
  🗓️ Billing
```

Under "References" group (Admin/Manager only — hidden for Supervisor):
```
  📋 Activity Log
  💳 Credit Collection
```

Export buttons: Admin + Manager only — hidden for Supervisor (also enforced server-side).
