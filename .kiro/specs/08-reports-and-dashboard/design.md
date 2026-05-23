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
│  │  Chicken   8 kg    threshold 10  [LOW ⚠]                  │ │
│  │  Juice Bxs 3 boxes threshold 10  [OUT ✕]                  │ │
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

```
┌───────────────────────────────────────────────────────────────────┐
│ Inventory Report                           [Export to Excel 📥]  │
├───────────────────────────────────────────────────────────────────┤
│  ┌── Summary ─────────────────────────────────────────────────┐  │
│  │  Out of Stock: 1 item   Below Threshold: 2 items           │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  Item        Stock   Unit   Threshold   Status   Last Restocked  │
│  Rice        50      kg     20          [OK ✓]   05/01/2026      │
│  Chicken      8      kg     10          [LOW ⚠]  05/05/2026      │
│  Juice Bxs    0      boxes  10          [OUT ✕]  04/28/2026      │
└───────────────────────────────────────────────────────────────────┘
```

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
```

Under "References" group:
```
  📋 Activity Log   ← Admin/Manager only (hidden for Supervisor)
```

Export buttons: Admin + Manager only — hidden for Supervisor (also enforced server-side).
