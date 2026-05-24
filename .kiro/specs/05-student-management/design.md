# Design 05 — Student Management

---

## Screen: Enrollment Form

**Route:** `pos.sunbites.com.ph/enrollment`
**Nav item:** 📋 Enrollment
**Layout:** `KitchenLayout`, centered card max-width 700px

```
┌──────────────────────────────────────────────────────────┐
│  📋 Student Enrollment Form                              │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  ── BRANCH ──────────────────────────────────────────   │
│  ┌───────────────────┐  ┌───────────────────┐           │
│  │ (●) 🏫 Antipolo   │  │ ( ) 🏫 Iloilo     │           │
│  │     Branch        │  │     Branch        │           │
│  └───────────────────┘  └───────────────────┘           │
│                                                          │
│  ── ENROLLMENT TYPE ──────────────────────────────────  │
│  ┌────────────────────────────┐  ┌──────────────────┐   │
│  │ (●) 📋 Subscription        │  │ ( ) 🪙 Non-Sub   │   │
│  │  Fixed monthly plan.       │  │  QR + wallet     │   │
│  │  Includes daily meal tray. │  │  only. No fee.   │   │
│  └────────────────────────────┘  └──────────────────┘   │
│                                                          │
│  ── SUBSCRIPTION PERIOD ──────────────────────────────  │
│  (shown only when Subscription type is selected)         │
│                                                          │
│  Start Month *                End Month *                │
│  [June ▾]  [2025 ▾]           [March ▾]  [2026 ▾]       │
│                                                          │
│  ┌─ Payment Preview ─────────────────────────────────┐  │
│  │  Month         Days   Amount                      │  │
│  │  June 2025      22    ₱2,970   (configured)       │  │
│  │  July 2025      22    ₱2,970   (configured)       │  │
│  │  August 2025    18    ₱2,430   (default)          │  │
│  │  September 2025 22    ₱2,970   (default)          │  │
│  │  October 2025   22    ₱2,970   (configured)       │  │
│  │  November 2025  16    ₱2,160   (default)          │  │
│  │  December 2025  15    ₱2,025   (default)          │  │
│  │  January 2026   20    ₱2,700   (default)          │  │
│  │  February 2026  18    ₱2,430   (default)          │  │
│  │  March 2026      7    ₱945     (configured)       │  │
│  │  ─────────────────────────────────────────────    │  │
│  │  Total: 10 months   ₱26,400                      │  │
│  └───────────────────────────────────────────────────┘  │
│                                                          │
│  ── STUDENT INFORMATION ─────────────────────────────   │
│  [📷 Photo]   (80×80 upload preview)                    │
│                                                          │
│  First Name *           Last Name *                      │
│  [__________________]   [__________________]             │
│                                                          │
│  Student Number *       Grade & Class *                  │
│  [__________________]   [Grade 3 – Section Mabini]      │
│                                                          │
│  Birthday *                                              │
│  [date picker          ]                                 │
│                                                          │
│  Food Allergies / Medical Restrictions                   │
│  [textarea...]                                           │
│                                                          │
│  Other Notes                                             │
│  [textarea...]                                           │
│                                                          │
│  ── PARENT / GUARDIAN INFORMATION ───────────────────   │
│  Full Name *            Home Address *                   │
│  [__________________]   [__________________]             │
│                                                          │
│  Phone Number *         Email Address *                  │
│  [__________________]   [__________________]             │
│                                                          │
│  ── PERMISSIONS & ACKNOWLEDGEMENT ───────────────────   │
│  ☐ I give permission for my child to receive meals...   │
│  ☐ I acknowledge I am responsible for notifying...      │
│                                                          │
│  Digital Signature (type full name) *                    │
│  [__________________]                                    │
│                                                          │
│  Date: 05/24/2026  (read-only)                          │
│                                                          │
│  [_____ Submit Enrollment → _____]                       │
└──────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Branch selector: radio cards with border highlight on active — `border-primary` when selected
- Type selector: same radio card pattern
- **Subscription Period section** — conditionally rendered when Subscription type is selected:
  - Two side-by-side pairs: `[Month ▾]  [Year ▾]` for start and end
  - Month options: June, July, August, September, October, November, December, January, February, March
  - Year options: current year and next year (dynamic based on today)
  - Defaults: start = June + current year, end = March + (current year + 1)
  - Validation: end must be on or after start in chronological school year order
  - Live preview table renders below pickers — updates on any picker change
  - Preview table columns: Month (e.g. "June 2025"), Days, Amount, Source indicator ("configured" = from `branch_monthly_amounts`, "default" = from config fallback)
  - Preview table footer: total months count + total amount
  - Source indicator: `text-xs text-muted-foreground` — "configured" in primary color, "default" in muted
- Photo: 80×80 circle placeholder, file input, preview swaps in on selection
- Required fields: `*` red asterisk in label
- Checkboxes: custom styled with `accent-primary`
- Submit button: primary, full width, `text-base font-bold py-4`

**Success State (after submission):**
```
┌──────────────────────────────────────────────────────┐
│                      ✅                               │
│             Enrollment Successful!                    │
│                                                       │
│  ┌─── Enrollment Details ────────────────────────┐   │
│  │  Student:    Maria Santos                     │   │
│  │  Type:       📋 Subscription                  │   │
│  │  Number:     ANT-2025-001                     │   │
│  │  Period:     June 2025 – March 2026           │   │
│  │  Enrolled:   05/24/2026                       │   │
│  └───────────────────────────────────────────────┘   │
│                                                       │
│  Student QR Code                                      │
│  ┌────────────────────────────────────────────┐      │
│  │  [QR CODE SVG 140×140]                     │      │
│  │  QR ID: SB-K8mP3xNzQr4w                   │      │
│  └────────────────────────────────────────────┘      │
│                                                       │
│  [🖨️ Print QR Code]                                  │
│  [ Enroll Another Student ]                           │
└──────────────────────────────────────────────────────┘
```
- Green border card (`border-green-300 bg-green-50`)
- QR code: auto-generated SVG, primary color border, format `SB-{12 random alphanumeric}`
- "Period" row shown only for subscription students — displays the configured range
- "Enroll Another" button clears and resets the form

---

## Screen: Student List

**Route:** `pos.sunbites.com.ph/students`
**Nav item:** 👥 Students
**Layout:** `KitchenLayout`

```
┌──────────────────────────────────────────────────────────────┐
│ 👥 Student Portal                                            │
├──────────────────────────────────────────────────────────────┤
│  🔔 Payment Reminder — July Subscription                     │
│     Ensure payment by July 24, 2026 (1 week before 1st)     │
│                                              [14 days left]  │
├──────────────────────────────────────────────────────────────┤
│  [Search name or student number...]  [Enroll Status ▾]      │
│  (Subscription tab only): [Month ▾] [Year ▾] [Paid/Unpaid ▾]│
│                                                              │
│  [All●] [📋 Subscription (3)] [🪙 Non-Subscription (1)]     │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  📋 SUBSCRIPTION STUDENTS (3) ━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                              │
│  ┌── [orange left border] ──────────────────────────────┐   │
│  │  👤 Maria Santos   [📋 Subscription] [Enrolled ✅]   │   │
│  │     Grade 3 – Section Mabini                         │   │
│  │     Parent: Ana Santos · 09171234567                  │   │
│  │                   Enrolled: 06/01/2025  Wallet: ₱450 │   │
│  │                                          ⭐ 0 pts    │   │
│  │  [✏️ Edit]  [💰 Wallet]  [🗑 Remove]                  │   │
│  │  ──────────────────────────────────────────────────  │   │
│  │  MONTHLY SUBSCRIPTION — click badge to toggle        │   │
│  │  [Jun '25 ✓] [Jul '25 ✗] [Aug '25 ✗] [Sep '25 ✗]   │   │
│  │  [Oct '25 ✗] [Nov '25 ✗] [Dec '25 ✗] [Jan '26 ✗]   │   │
│  │  [Feb '26 ✗] [Mar '26 ✗]                            │   │
│  │  [💳 Record Payment]                                 │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  🪙 NON-SUBSCRIPTION STUDENTS (1) ━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                              │
│  ┌── [purple left border] ──────────────────────────────┐   │
│  │  👤 Carlo Mendoza  [🪙 Non-Sub]    [Enrolled ✅]     │   │
│  │     Grade 4 – Section Rizal                          │   │
│  │     Parent: Liza Mendoza · 09151234567               │   │
│  │                   Enrolled: 06/01/2025  Wallet: ₱300 │   │
│  │  [✏️ Edit]  [💰 Wallet]  [🗑 Remove]                  │   │
│  │  ──────────────────────────────────────────────────  │   │
│  │  🪙 Wallet-only account — loads wallet to purchase   │   │
│  │     food items                    [💰 Load Wallet]   │   │
│  └──────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
```

**Payment Reminder Banner (subscription students only, shown 14 days before month end):**
- `bg-gradient-to-r from-primary/5 to-amber-50 border-2 border-primary rounded-xl p-3 mb-4`
- Bell icon + "Payment Reminder — [Month] Subscription" bold title
- Days-left badge: `bg-primary text-primary-foreground rounded-full text-xs font-bold px-3`

**Section Headers (when "All" tab active):**
- Subscription: `bg-primary/10 border-2 border-primary rounded-lg px-4 py-1.5 text-sm font-extrabold text-primary`
- Non-subscription: `bg-purple-50 border-2 border-purple-600 rounded-lg px-4 py-1.5 text-sm font-extrabold text-purple-700`

**Subscription card — month badges:**
- Include abbreviated year in label: "Jun '25", "Jan '26"
- Paid month: `bg-green-100 text-green-700 border-green-300 rounded-full text-[11px] font-bold px-3 py-1` — "Jun '25 ✓"
- Unpaid month: `bg-red-100 text-destructive border-red-300 rounded-full text-[11px] font-bold px-3 py-1` — "Jul '25 ✗"
- Click to toggle (with confirm dialog for marking unpaid)

**Non-subscription card — bottom section:**
- Info box: `bg-purple-50 border border-purple-200 rounded-lg px-3 py-2 text-sm text-purple-800 font-semibold`
- "Load Wallet" button: `bg-purple-600 text-white`

**Enrollment Status Badges:**
```
[Enrolled ✅]   — bg-green-100 text-green-700 border-green-300
[Paused ⏸]     — bg-yellow-100 text-amber-700 border-yellow-300
[Unenrolled ⭕] — bg-muted text-muted-foreground border-border
[Banned 🚫]    — bg-red-100 text-destructive border-red-300
[Graduated 🎓] — bg-purple-100 text-purple-700 border-purple-300
```
All: `text-[11px] font-bold px-3 py-1 rounded-full border cursor-pointer` — click opens status picker

---

## Batch QR Printing

### Student List with Multi-Select

```
┌──────────────────────────────────────────────────────────────────┐
│ 👥 Student Portal                                                │
├──────────────────────────────────────────────────────────────────┤
│  [Search...]    [Grade ▾]  [Status ▾]  [All] [Sub] [Non-Sub]   │
├──────────────────────────────────────────────────────────────────┤
│  ☐  Name              Grade              Status      Wallet  Act │
├──────────────────────────────────────────────────────────────────┤
│  ☑  Maria Santos      Grade 3 – Mabini  [Enrolled✅] ₱450  [View]│
│  ☑  Juan dela Cruz    Grade 5 – Bonif.  [Enrolled✅] ₱200  [View]│
│  ☐  Sofia Reyes       Grade 1 – Luna    [Paused ⏸]  ₱600  [View]│
│  ☑  Carlo Mendoza     Grade 4 – Rizal   [Enrolled✅] ₱300  [View]│
└──────────────────────────────────────────────────────────────────┘

  ┌── Floating action bar (appears at bottom when any row checked) ─┐
  │  ☑ 3 selected   [🖨️ Print QR Codes]   [✕ Clear Selection]    │
  └────────────────────────────────────────────────────────────────┘
```

- Floating bar: fixed at bottom center, `shadow-lg border rounded-2xl bg-white px-6 py-3`
- Selected count badge: `bg-primary text-primary-foreground rounded-full text-sm font-bold px-3`

### Batch QR Print Preview Modal

```
┌────────────────────────────────────────────────────────────────┐
│  🖨️ Print QR Codes (3 selected)                          [✕]  │
├────────────────────────────────────────────────────────────────┤
│                                                                │
│  Cards per row:  [2]  [4●]                                    │
│                                                                │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │  │
│  │  │ [photo]      │  │ [photo]      │  │ [photo]      │  │  │
│  │  │ Maria Santos │  │ Juan Cruz    │  │ Carlo Mendoza│  │  │
│  │  │ Grade 3      │  │ Grade 5      │  │ Grade 4      │  │  │
│  │  │  [QR CODE]   │  │  [QR CODE]   │  │  [QR CODE]   │  │  │
│  │  │SB-K8mP3xNzQr4│  │SB-aX7kLmN9pQ│  │SB-BcD4eF5gH6j│  │  │
│  │  └──────────────┘  └──────────────┘  └──────────────┘  │  │
│  └─────────────────────────────────────────────────────────┘  │
│  (scrollable preview)                                          │
│                                                                │
│  [Cancel]                               [🖨️ Print All]        │
└────────────────────────────────────────────────────────────────┘
```

**Print output (`@media print` CSS):**
- Modal chrome hidden, only the card grid prints
- Cards do not split across page breaks (`break-inside: avoid`)
- No sidebar, topbar, or browser UI chrome

---

## Screen: Student Detail Page

**Route:** `pos.sunbites.com.ph/students/{id}`
**Layout:** `KitchenLayout`

```
┌────────────────────────────────────────────────────────────┐
│ ← Students     Maria Santos                    [⋮ Actions] │
├────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────────────────────────────────┐  │
│  │  [📷 96×96]    Maria Santos                          │  │
│  │                Grade 3 – Section Mabini              │  │
│  │                [Enrolled ✅]  [📋 Subscription]      │  │
│  │                                                      │  │
│  │  ┌──────────────────────────────────────────────┐   │  │
│  │  │  Wallet Balance    ₱450     QR: SB-K8mP3xNz  │   │  │
│  │  │  [+Top Up]                  [🖨️ Print QR]    │   │  │
│  │  └──────────────────────────────────────────────┘   │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                            │
│  [Profile]  [Wallet]  [Order History]  [Payment]  [Logs]  │
│                                                            │
│  ┌── [tab content] ──────────────────────────────────┐    │
│  │                                                    │    │
│  └────────────────────────────────────────────────────┘    │
└────────────────────────────────────────────────────────────┘
```

**`[⋮ Actions]` dropdown:**
- Change Enrollment Status
- Top Up Wallet
- Print QR Code
- Remove Student (with confirmation)

**Tab: Profile**
```
│  Personal                                                 │
│  Birthday: March 14, 2016                                 │
│  Allergies: None                                          │
│  Notes: Loves vegetables                                  │
│                                                           │
│  Parent / Guardian                                        │
│  Name: Ana Santos                                         │
│  Email: anasantos@email.com                               │
│  Phone: 09171234567                                       │
│  Address: 123 Rizal St, Antipolo                          │
│                                                           │
│  ── QR Code ──────────────────────────────────────────   │
│      ┌───────────────────────────────────┐               │
│      │         [QR CODE 180×180]         │               │
│      │      QR ID: SB-K8mP3xNzQr4w      │               │
│      └───────────────────────────────────┘               │
│      [🖨️ Print QR Code]   [⬇ Download PNG]              │
│      [↺ Regenerate QR Code] (Admin/Manager/Supervisor)   │
```

**Single QR Print Card Layout:**
```
  ┌───────────────────────────────┐
  │  [📷 photo or placeholder]    │
  │                               │
  │  Maria Santos                 │  ← bold, 18px
  │  Grade 3 – Section Mabini    │  ← 13px muted
  │                               │
  │       ┌──────────────┐        │
  │       │  [QR CODE]   │        │  ← 200×200px
  │       └──────────────┘        │
  │  SB-K8mP3xNzQr4w             │  ← 11px mono
  │  Antipolo Branch              │  ← 11px muted
  └───────────────────────────────┘
```

**Tab: Wallet**
```
│  Current Balance: ₱450                    [+Top Up]      │
│                                                            │
│  Date       Type      Amount    Balance After  Note       │
│  05/09     Credit    +₱500     ₱500           GCash TU   │
│  05/09     Debit     -₱135     ₱365           POS Order  │
│  05/10     Debit     -₱135     ₱230           POS Order  │
```

**Tab: Order History**
```
│  Date       Time     Items                  Amount  Type  │
│  05/09/26  11:32AM  Subscription Meal Tray  ₱135   ✓ Paid│
│  05/09/26  11:45AM  Snack A, Juice          ₱30  📋 Credit│
│                                     Total: ₱165          │
```

**Tab: Payment (Subscription only)**

The payment tab shows all `student_monthly_payments` records for this student, grouped by year, with the year displayed as a section header. Each row shows the month name, year, days, daily rate, payment status badge, and amount.

```
│  [+ Add Subscription Period]      (Admin/Manager/Supervisor)│
│                                                             │
│  ── 2025 ─────────────────────────────────────────────    │
│  ┌──── June 2025 ──────────────────────────────────────┐  │
│  │  22 school days · ₱135/day    [PAID ✓]  ₱2,970    │  │
│  │  [Download Receipt 📄]                               │  │
│  └─────────────────────────────────────────────────────┘  │
│  ┌──── July 2025 ──────────────────────────────────────┐  │
│  │  22 school days · ₱135/day   [UNPAID]   ₱2,970    │  │
│  │  [Mark as Paid →]                                    │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
│  ── 2026 ─────────────────────────────────────────────    │
│  ┌──── January 2026 ───────────────────────────────────┐  │
│  │  20 school days · ₱135/day   [UNPAID]   ₱2,700    │  │
│  │  [Mark as Paid →]                                    │  │
│  └─────────────────────────────────────────────────────┘  │
```

For non-subscription students this tab shows a "No subscription plan" empty state.

**Tab: Notes / Logs**
```
│  Date         Actor        Event                         │
│  05/09/26     Admin        Enrollment status → Enrolled  │
│  05/09/26     Juan C.      Wallet reloaded +₱500 (GCash) │
│  05/10/26     Admin        Credit settled — ₱135 cleared │
│  05/10/26     System       POS order #ANT-2025-001001    │
```
- Read-only — no actions in this tab

---

## Dialog: Add Subscription Period

Opens from the `[+ Add Subscription Period]` button on the Payment tab of Student Detail.

```
┌──────────────────────────────────────────────────────────┐
│  Add Subscription Period — Maria Santos            [✕]   │
├──────────────────────────────────────────────────────────┤
│                                                           │
│  Start Month *                End Month *                 │
│  [June ▾]   [2025 ▾]          [March ▾]   [2026 ▾]       │
│                                                           │
│  ┌─ Payment Preview ──────────────────────────────────┐  │
│  │  Month           Days   Amount                     │  │
│  │  June 2025        22    ₱2,970   (configured)      │  │
│  │  July 2025        22    ₱2,970   (default)         │  │
│  │  ...                                               │  │
│  │  ──────────────────────────────────────────────    │  │
│  │  Total: 10 months   ₱26,400                       │  │
│  └────────────────────────────────────────────────────┘  │
│                                                           │
│  ⚠ Months that already have a payment record for this    │
│    student will be highlighted in red and blocked.        │
│                                                           │
│  [Cancel]                    [Create Payment Records →]  │
└──────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Same range picker pattern as the enrollment form subscription period section
- Preview table renders live as pickers change
- If any month in the range already has a `student_monthly_payments` record for this student (same school_month + year), that row is highlighted `bg-red-50 border-l-2 border-red-500` with a "⚠ Already exists" indicator
- Submit button disabled if any conflicting months are present
- On success: payment tab list refreshes, dialog closes, success toast shown

---

## Screen: References → Subscription Config

**Route:** `pos.sunbites.com.ph/references/subscription-config`
**Nav item:** References (submenu)
**Layout:** `KitchenLayout`
**Roles:** Admin, Manager, Supervisor

```
┌──────────────────────────────────────────────────────────────┐
│ 📅 Subscription Config                                        │
│ Configure school days and monthly amounts per year            │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  School Year:  [2025 ▾]                [+ Add Month]        │
│                                                              │
│  Base daily rate: ₱135/day  (from config)                   │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  Month          Days    Amount      Source    Actions        │
├──────────────────────────────────────────────────────────────┤
│  June           22      ₱2,970      ✅ Set    [Edit] [Del]  │
│  July           22      ₱2,970      ✅ Set    [Edit] [Del]  │
│  August         18      ₱2,430      ⬜ Default  [Set]       │
│  September      22      ₱2,970      ✅ Set    [Edit] [Del]  │
│  October        22      ₱2,970      ⬜ Default  [Set]       │
│  November       16      ₱2,160      ⬜ Default  [Set]       │
│  December       15      ₱2,025      ⬜ Default  [Set]       │
│  January        20      ₱2,700      ⬜ Default  [Set]       │
│  February       18      ₱2,430      ⬜ Default  [Set]       │
│  March           7      ₱945        ✅ Set    [Edit] [Del]  │
└──────────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Year selector at top: shows current year and adjacent years; defaults to the current school year
- Table shows all 10 school months — always all 10 rows, whether configured or not
- Source column:
  - "✅ Set" — a `branch_monthly_amounts` record exists for this branch + month + year; badge `bg-green-100 text-green-700`
  - "⬜ Default" — no record exists; showing system fallback; badge `bg-muted text-muted-foreground`
- Amount column: shows the effective amount (configured or computed from fallback)
- Days column: shows the effective days (configured or system default)
- **[Set] button** (for Default rows): opens the Add/Edit modal to configure this month
- **[Edit] button** (for Set rows): opens the Add/Edit modal pre-filled with existing values
- **[Del] button** (for Set rows): deletes the record with a confirmation prompt; row reverts to Default
- **[+ Add Month] button**: opens the modal with month + year pre-set to the first unconfigured month

### Add / Edit Month Modal

```
┌──────────────────────────────────────────────────────┐
│  Configure Month — June 2025                   [✕]   │
├──────────────────────────────────────────────────────┤
│                                                       │
│  Month         Year                                   │
│  [June ▾]      [2025 ▾]   (read-only when editing)   │
│                                                       │
│  School Days *                                        │
│  [__22__]                                             │
│                                                       │
│  Computed Amount                                      │
│  ₱2,970  (₱135 × 22 days)  — live preview            │
│                                                       │
│  [Cancel]                       [Save Configuration] │
└──────────────────────────────────────────────────────┘
```

- School days input: integer, min 1, max 31
- Computed amount: `text-lg font-bold text-primary`, updates live as days changes
- Formula shown inline: `(₱{daily_rate} × {days} days)`
- Amount is always computed server-side as `daily_meal_rate × days` — not sent directly by client
- On save: table row updates, success toast

---

## Modal: Wallet Top-Up

```
┌──────────────────────────────────────────────────────┐
│  Top Up Wallet — Maria Santos              [✕]   │
├──────────────────────────────────────────────────────┤
│  Current Balance: ₱450                           │
│                                                   │
│  Amount to Add (₱) *                             │
│  [__500__]                                        │
│                                                   │
│  Payment Method *                                 │
│  (●) GCash    ( ) Cash    ( ) Bank Transfer      │
│                                                   │
│  Reference Number (GCash)                         │
│  [______________]                                 │
│                                                   │
│  New Balance After Top-Up: ₱950                  │
│  (live calculation)                               │
│                                                   │
│  [Cancel]                      [Confirm Top-Up]  │
└──────────────────────────────────────────────────┘
```

**Component Notes:**
- "New Balance" preview: `text-lg font-extrabold text-green-600`
- Confirm button: primary, disabled until amount > 0

---

## Enrollment Status Picker

```
  ┌─── Change Status ──────────────────────────────┐
  │  (●) Enrolled ✅  — Can avail meals            │
  │  ( ) Paused ⏸   — Temp paused, no meals        │
  │  ( ) Unenrolled ⭕ — No longer enrolled         │
  │  ( ) Banned 🚫  — Banned from canteen           │
  │  ( ) Graduated 🎓 — Completed program           │
  └────────────────────────────────────────────────┘
```

Instant save on selection — no submit button needed. Shows success toast.