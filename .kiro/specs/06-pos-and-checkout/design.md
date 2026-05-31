# Design 06 — POS & Checkout

---

## Screen: POS Main View

**Route:** `pos.sunbites.com.ph/pos`
**Layout:** `KitchenLayout`
**Default tab:** 🛒 POS

### Tab Navigation
```
[🛒 POS ●]  [📋 Transaction History]  [🍽️ Menu Mgmt]  [📦 Inventory]
```
- Active tab: `bg-primary text-primary-foreground rounded-full`
- Inactive: `border-2 border-border bg-background text-foreground rounded-full hover:border-primary/50`

---

### POS Main — Two-Column Layout

```
┌──────────────────────────────────────────┬─────────────────────────┐
│  LEFT PANEL (flex:1)                     │  RIGHT PANEL (340px)    │
│                                          │                         │
│  ┌── Find Student ────────────────────┐  │  ┌── Order Summary ───┐ │
│  │  📷 Scan QR / Enter ID             │  │  │                   │ │
│  │  [Scan QR code or enter ID...] [Scan]│  │  │  (empty state)    │ │
│  │  ─────── OR ─────────              │  │  │  Add items from   │ │
│  │  🔍 Search by Name                  │  │  │  the menu.        │ │
│  │  [Type first or last name...][Walk-in]│  │  │                   │ │
│  │                                    │  │  └───────────────────┘ │
│  │  [search results dropdown here]    │  │                         │
│  │  [OR selected student card here]   │  │                         │
│  └────────────────────────────────────┘  │                         │
│                                          │                         │
│  ┌── Menu Items ──────────────────────┐  │                         │
│  │  [meal●] [snack] [drink] [extra]   │  │                         │
│  │                                    │  │                         │
│  │  ┌──────────┐ ┌──────────┐ ┌────┐  │  │                         │
│  │  │Sub Meal  │ │Snack A   │ │Sna │  │  │                         │
│  │  │Tray      │ │Bread     │ │ck B│  │  │                         │
│  │  │₱135      │ │₱15       │ │₱20 │  │  │                         │
│  │  │[meal]    │ │[snack]   │ │[sn]│  │  │                         │
│  │  └──────────┘ └──────────┘ └────┘  │  │                         │
│  └────────────────────────────────────┘  │                         │
└──────────────────────────────────────────┴─────────────────────────┘
```

---

### Left Panel: Student Search & QR Input

**QR Scan field (auto-focused on page load):**
```
  ┌─────────────────────────────────────────────────────────────┐
  │  Scan QR code or type student name / number…               │
  └─────────────────────────────────────────────────────────────┘
  Press [Enter] to search  —  Walk-in customer
```
- `id="pos-qr-input"` — auto-focused on mount via `useEffect`
- `F1` keyboard shortcut refocuses this field

**Global Scan Detection (document-level listener):**
- A `keydown` event listener on `document` captures all keystrokes, not just when the input is focused
- Characters arriving < 100ms apart → accumulated in a hidden `scanBufferRef` (a React ref, never rendered)
- Characters arriving > 100ms apart → go into the visible input field (manual typing path)
- On `Enter`: if `scanBufferRef` has content matching `/^SB-[A-Za-z0-9]{12}$/` → fire QR lookup silently; **clear buffer**; input field stays empty
- On `Enter` in visible input (manual path) → fire name/number search as before
- QR code string is **never displayed** in the input box — only the resolved student appears on screen
- If the input already has text when the global listener detects a fast-key sequence, it discards the input text and uses the buffer (scanner takes priority)

**Manual entry:** cashier types in the visible input → debounced 300ms → name/number search dropdown as before

**Name Search field (below OR divider):**
```
  🔍 SEARCH BY NAME
  ┌─────────────────────────────────────────┐ [Walk-In]
  │  Type first or last name...            │
  └─────────────────────────────────────────┘
```
- Debounced 300ms → displays dropdown of matches
- `F2` shortcut focuses this field

**Search Results Dropdown:**
```
  ┌──────────────────────────────────────────────────────┐
  │  3 students found — tap to select                    │
  ├──────────────────────────────────────────────────────┤
  │  👤  Maria Santos                                    │
  │      Grade 3 – Mabini                               │
  │      [📋 Subscription] [Enrolled ✅]                 │
  ├──────────────────────────────────────────────────────┤
  │  👤  Juan dela Cruz                                  │
  │      Grade 5 – Bonifacio                            │
  │      [📋 Subscription] [Enrolled ✅]                 │
  ├──────────────────────────────────────────────────────┤
  │  👤  (grayed) Sofia Reyes                            │
  │      Grade 1 – Luna                                 │
  │      [Paused ⏸] ⛔ Not eligible                     │
  └──────────────────────────────────────────────────────┘
```
- Dropdown: white card, shadow, `border-primary/30`
- Ineligible students: `opacity-65`, no hover, ⛔ badge
- Search results show minimal data only — no wallet balance in dropdown

**Walk-In Customer (after Walk-In button pressed):**
```
  ┌────────────────────────────────────────────────────┐
  │  🚶 Walk-in Customer                               │
  └────────────────────────────────────────────────────┘
```

**Selected Student Card (after QR scan or search selection):**
```
  ┌─────────────────────────────────────────────────────┐
  │  👤 (photo)  Maria Santos              [Clear ✕]   │
  │              Grade 3 – Mabini                       │
  │              [Enrolled ✅] [📋 Subscription]        │
  │                                                     │
  │  ┌────────────────┐  ┌────────────┐                │
  │  │  Wallet         │  │  Points    │                │
  │  │  ₱450          │  │  ⭐ 2 pts  │               │
  │  └────────────────┘  └────────────┘                │
  │                                                     │
  │  ⚠️ Credit Owed: ₱85.00   (shown when credit > 0)  │
  └─────────────────────────────────────────────────────┘
```

- Points: decorative display only
- Credit owed badge: `bg-red-50 text-destructive text-xs font-semibold`
- Card: `bg-primary/5 border-2 border-primary/20 rounded-xl p-3`
- Clear button: `bg-red-50 text-destructive`

---

### Left Panel: Menu Item Grid

**Category Tabs (above grid):**
```
[Meal●]  [Snack]  [Drink]  [Extra]
```

**Item Cards (3–4 per row, responsive):**
```
  ┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐
  │  Subscription        │  │  Snack A             │  │  Snack C             │
  │  Meal Tray           │  │  (Bread/Pastry)      │  │  (Juice/Water)       │
  │                      │  │                      │  │                      │
  │  ₱135.00             │  │  ₱15.00              │  │  ₱15.00              │
  │  [MEAL]              │  │  [SNACK]             │  │  [DRINK]             │
  │                      │  │  ⚠ Low Stock         │  │  ✕ Out of Stock      │
  └──────────────────────┘  └──────────────────────┘  └──────────────────────┘
       (normal)                  (LOW — clickable)          (OUT — disabled)

  ┌──────────────────────┐
  │  Special Snack       │
  │                      │
  │  ₱30.00              │
  │  [SNACK]             │
  │  ⚠ Not Mapped        │
  └──────────────────────┘
       (unmapped — disabled)
```
- Click: adds to Zustand cart; card background becomes `bg-primary/10` if already in cart
- Quantity counter badge on card top-right when qty > 0: `text-[10px] bg-primary text-primary-foreground rounded-full`
- Unavailable items (`is_available = false`): hidden from grid by default
- **Inventory status on cards** (driven by `inventory_status` + `has_inventory_mapping` from API):
  - `OUT`: card at `opacity-40 cursor-not-allowed`; click disabled; no cart add; badge: `bg-red-50 text-destructive text-[10px]` — "✕ Out of Stock"
  - `LOW`: card clickable; warning badge: `bg-yellow-50 text-amber-700 border-yellow-300 text-[10px]` — "⚠ Low Stock"
  - `has_inventory_mapping = false`: card at `opacity-60 cursor-not-allowed`; click disabled; badge: `bg-orange-50 text-orange-700 text-[10px]` — "⚠ Not Mapped"

---

### Right Panel: Cart / Order Summary

**Empty cart:**
```
  ┌──────────────────────────────────────┐
  │  Order Summary                       │
  │                                      │
  │  Add items from the menu.            │
  └──────────────────────────────────────┘
```

**Cart with items:**
```
  ┌────────────────────────────────────────────────┐
  │  Order Summary                                 │
  │                                                │
  │  Subscription Meal Tray                [✕]    │
  │  x1 × ₱135.00              ₱135.00            │
  │  ─────────────────────────────────────────     │
  │  Snack A (Bread/Pastry)               [✕]    │
  │  x2 × ₱15.00               ₱30.00            │
  │  ─────────────────────────────────────────     │
  │                                                │
  │  ── Order Notes ───────────────────────────    │
  │  [Special instructions (optional)...]         │
  │                                                │
  │  Subtotal:                       ₱165.00      │
  │  ── Discount (Admin/Manager only) ──────       │
  │  Type: [% ▾]  Amount: [___]                   │
  │  Reason: [________________]                    │
  │  Discount:                        -₱0.00      │
  │  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
  │  Total:                          ₱165.00      │
  │                                                │
  │  ── Payment Method ────────────────────────    │
  │  [💵 Cash●]  [📱 GCash]  [👛 Wallet]          │
  │                                                │
  │  ── Cash: Amount Tendered ──────────────────   │
  │  [_____200_____]                               │
  │  Change: ₱35.00                               │
  │                                                │
  │  [──── Confirm Purchase → ────]               │
  └────────────────────────────────────────────────┘
```

**Discount block (Admin/Manager only — hidden for Cashier/Supervisor):**
- Type toggle: `[% Percent]` or `[₱ Fixed Amount]`
- Amount input: number
- Reason input: required text

**Payment Method Selector:**
```
  [💵 Cash ●]   [📱 GCash]   [👛 Wallet]
```
- Pill buttons, active = `bg-primary text-primary-foreground`
- Wallet button disabled (grayed) for Walk-In customers

**Cash panel:**
- "Amount Tendered" input
- Change display: green if positive; Confirm disabled until tendered ≥ total

**GCash panel:**
```
  Reference Number (optional)
  [____________________]
```

**Wallet panel:**
```
  Wallet: ₱450.00
  After:  ₱285.00   ✓
```
- Green if sufficient. If insufficient: red "Insufficient balance" — triggers Insufficient Funds Modal

---

## Modal: Checkout Receipt

```
┌────────────────────────────────────────────────────────────┐
│  🧾 Order Receipt                                    [✕]  │
├────────────────────────────────────────────────────────────┤
│  Receipt No: ANT-2025-001001                               │
│  Date: 05/09/2026  11:32 AM                               │
│  Cashier: Juan Cashier                                     │
│  Customer: Maria Santos (Grade 3 – Mabini)                │
│  ─────────────────────────────────────────────────────     │
│  Item                            Qty    Price    Total     │
│  Subscription Meal Tray          1     ₱135.00  ₱135.00   │
│  Snack A (Bread/Pastry)          2     ₱15.00   ₱30.00    │
│  ─────────────────────────────────────────────────────     │
│  Subtotal:                                      ₱165.00   │
│  Discount:                                       -₱0.00   │
│  Total:                                         ₱165.00   │
│  Payment Method:  👛 Wallet                               │
│  Wallet Remaining: ₱285.00                                │
│  ─────────────────────────────────────────────────────     │
│  Credit Used: ₱0.00      (shown only when is_credit=true) │
│  Outstanding Credit: ₱85.00                               │
│  ─────────────────────────────────────────────────────     │
│  ⭐ +1 Point Earned!          (shown when points_earned>0) │
│  ─────────────────────────────────────────────────────     │
│  [🖨️ Print Receipt]          [🛒 New Order]               │
└────────────────────────────────────────────────────────────┘
```

- Print button triggers `window.print()` with receipt-optimized CSS — optional
- "New Order": clears Zustand cart state and student selection, closes modal, re-focuses QR field
- Credit and points lines are **conditionally rendered** — hidden when not applicable

---

## Screen: Transaction History Tab

```
┌───────────────────────────────────────────────────────────────┐
│  📋 Daily Purchase History                                    │
│                                                               │
│  Date [05/09/2026 ▾]   Method [All ▾]   [Student...]        │
│                                                               │
│  ┌────────┬──────────┬──────────────┬─────────────────────┐  │
│  │ 🧾     │ Txns: 8  │ Revenue: ₱1,350│ Walk-ins: 2      │  │
│  └────────┴──────────┴──────────────┴─────────────────────┘  │
│                                                               │
│  Time   Receipt #        Customer           Items  Method Total Actions│
│  11:32  ANT-2025-001001  Maria Santos       [Sub]  [Cash]  ₱135 [View][Void]│
│  11:45  ANT-2025-001002  Juan dela Cruz     [Sub]  [GCash] ₱150 [View][Void]│
│  12:01  ANT-2025-001003  Walk-In Customer   [Snack][Cash]  ₱15  [View][Void]│
│  12:15  ANT-2025-001004  ~~Sofia Reyes~~    [Sub]  [Wallet]₱135 [VOIDED]    │
└───────────────────────────────────────────────────────────────┘
```

**Payment method badges:**
```
[Cash]   → bg-green-50 text-green-700
[GCash]  → bg-blue-50 text-blue-700
[Wallet] → bg-purple-50 text-purple-700
```

**Voided row:** strikethrough text, `[VOIDED]` badge `bg-muted text-muted-foreground`, row slightly dimmed

**Void Transaction Modal:**
```
┌────────────────────────────────────────────────┐
│  Void Transaction ANT-2025-001004         [✕]  │
├────────────────────────────────────────────────┤
│  ⚠️  This will reverse the transaction.        │
│  Customer: Sofia Reyes                          │
│  Amount: ₱135.00 (Wallet)                      │
│  Wallet will be refunded ₱135.00               │
│  Inventory stock will be restored.             │
│                                                 │
│  Void Reason *                                  │
│  [___________________________]                  │
│                                                 │
│  [Cancel]               [Confirm Void]         │
└────────────────────────────────────────────────┘
```
- "Inventory stock will be restored." line shown on all voids (stock was always deducted at checkout)

---

## Modal: Insufficient Wallet Funds

```
┌────────────────────────────────────────────────────┐
│  💳 Insufficient Wallet Balance              [✕]  │
├────────────────────────────────────────────────────┤
│  Maria Santos — Wallet: ₱50.00                     │
│  Order Total:  ₱135.00                             │
│  Shortfall:     ₱85.00                             │
│                                                    │
│  ┌──────────────────────────────────────────────┐  │
│  │  💰 Reload Wallet                            │  │
│  │  Collect ₱85.00 from parent and add it       │  │
│  │  directly to the wallet to cover this order. │  │
│  │                                              │  │
│  │  Amount to reload (locked):  ₱85.00          │  │
│  │  Payment Method: (●) Cash   ( ) GCash        │  │
│  │  GCash Ref # (optional): [____________]      │  │
│  │                                              │  │
│  │  [Reload ₱85.00 & Continue]                  │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  ┌──────────────────────────────────────────────┐  │
│  │  📋 Use Credit                               │  │
│  │  Charge ₱85.00 as canteen credit.            │  │
│  │  Outstanding after: ₱85.00 / ₱300 limit.    │  │
│  │  Admin/Manager will follow up with parent.   │  │
│  │                                              │  │
│  │  [Use Credit & Continue]                     │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  [Cancel]                                          │
└────────────────────────────────────────────────────┘
```

**Reload Wallet option:**
- Amount field is read-only, locked to shortfall — no partial loads
- `bg-green-50 border-green-200` card

**Use Credit option:**
- Greyed out and disabled if `credit_balance + shortfall > ₱300`
- Disabled message: "Credit limit reached (₱300). Reload wallet to continue."
- `bg-orange-50 border-orange-200` card; red tint if limit reached

---

## Modal: Change Student (QR scanned while student already selected)

Shown when a QR scan is detected and `selectedStudent !== null`. The new student's lookup fires in the background; this dialog appears with the result.

```
┌────────────────────────────────────────────────────┐
│  🔄 Switch Student?                          [✕]  │
├────────────────────────────────────────────────────┤
│  A new QR code was scanned.                        │
│                                                    │
│  Current:  Maria Santos  (Grade 3)                 │
│  New:      Juan dela Cruz  (Grade 5 — Enrolled ✅) │
│                                                    │
│  Switch to the new student?                        │
│                                                    │
│  [No, Keep Current]        [Yes, Switch]           │
└────────────────────────────────────────────────────┘
```

- "Yes, Switch" button: `bg-primary text-primary-foreground` — replaces current student with the new one; closes dialog
- "No, Keep Current" button: `variant="outline"` — dismisses dialog; current student unchanged
- If new student is not enrolled: show Student Not Eligible dialog instead (see below) — do not show Switch dialog

---

## Modal: Student Not Found (QR lookup returns no match)

Shown when a QR scan lookup returns no student record. Uses a dialog — **not** an inline error — so it is visible regardless of what the cashier was doing when the scan happened.

```
┌────────────────────────────────────────────────────┐
│  🔍 Student Not Found                        [✕]  │
├────────────────────────────────────────────────────┤
│                                                    │
│  No student was found matching this QR code.       │
│  Please try scanning again or search by name.      │
│                                                    │
│                   [Close]                          │
└────────────────────────────────────────────────────┘
```

- Single "Close" button dismisses the dialog
- Current state is fully preserved — if a student was already selected, they remain selected
- Dialog also closes on click-outside (`onOpenChange`)
- Never show the raw QR code value in this dialog

---

## Enrollment Status Block Error

```
┌────────────────────────────────────────────────────┐
│                    🚫                              │
│          Student Not Eligible                      │
│                                                    │
│  Sofia Reyes enrollment status is Paused.         │
│  Cannot process order.                             │
│                                                    │
│                   [Close]                          │
└────────────────────────────────────────────────────┘
```
