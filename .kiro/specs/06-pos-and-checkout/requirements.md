# Spec 06 — POS & Checkout

## Overview

The POS is the primary operational screen used by Cashiers, Supervisors, Managers, and Admins. It supports registered students (identified via QR scan) and walk-in (unregistered) customers. Payment supports Cash, GCash, and Student Wallet. The cart is managed client-side in Zustand on the POS frontend — only the final checkout payload is submitted to the API.

---

## POS Page Structure

Located at: `pos.sunbites.com.ph/pos`
Roles: All (Admin, Manager, Supervisor, Cashier)

### Sub-views (tab navigation at top)
| Tab | Roles |
|---|---|
| 🛒 POS | All |
| 📋 Transaction History | All |
| 🍽️ Menu Mgmt | Admin, Manager |
| 📦 Inventory | Admin, Manager, Supervisor |

Menu Mgmt and Inventory tabs are covered in Spec 04.

---

## POS Main View

### Layout (two-column on desktop, stacked on tablet)

**Left panel — Item Grid**
- Category tabs across the top (meal | snack | drink | extra)
- Item cards in a grid (3–4 columns)
- Search bar filters items across all categories
- Item card: name (bold), price (primary color), category label (small muted)
- Only `is_available = true` items shown and clickable
- Click item → adds to cart in Zustand store; quantity counter badge appears on card if already in cart

**Right panel — Cart & Checkout**
- Customer type toggle: **Registered Student** | **Walk-In**
- Student QR section (shown when Registered is selected)
- Cart item list (from Zustand store)
- Order summary (subtotal, discount, total)
- Payment method selection
- Checkout button

---

## Customer Identification

### Walk-In Customer
- No student required
- Cart proceeds immediately
- Payment: Cash or GCash only (wallet not available for walk-in)
- Walk-in orders recorded with `student_id = null`

### Registered Student via QR
Two methods to identify a student — both use the **same single input field**:
1. **QR Scan** — USB scanner emits the QR value as rapid keystrokes followed by `Enter`. The scanner fires even without the input box being focused — the document-level listener captures it globally.
2. **Manual Lookup** — cashier types student name or student number as fallback; debounced search shows a dropdown of matches.

**Hardware Requirement (USB QR Scanner):**
- Scanner must be in **HID (keyboard emulation) mode** — standard for virtually all USB barcode/QR scanners
- Scanner must be configured to append **Enter (CR) as a suffix** after each scan
- No drivers or browser extensions required — scanner appears as a keyboard to the browser

**Scan Detection Logic (frontend) — Global Listener:**

The component attaches a `keydown` listener to `document` (not just the input element) so the scanner works regardless of which element currently has focus. When the sequence matches a QR scan, the value is processed silently without ever being shown to the user.

| Signal | Scanner | Human typing |
|---|---|---|
| Character arrival speed | < 100ms between keystrokes | > 100ms between keystrokes |
| Value format on Enter | Matches `SB-[A-Za-z0-9]{12}` exactly | Name or student number (no prefix) |

Behavior:
- A hidden ref (`scanBufferRef`) accumulates characters typed at < 100ms intervals — these chars do **not** appear in the visible input field
- On `Enter` key: if the buffer matches `/^SB-[A-Za-z0-9]{12}$/`, fire QR lookup immediately using the buffered value; clear the buffer; do **not** populate the input box
- If Enter fires but the buffer does not match the QR pattern, treat the buffer as a manual name/number search and fire lookup; clear buffer
- When the cashier types manually into the visible input (keystrokes > 100ms apart), the normal input field handles the value — debounced 300ms → name/number search dropdown
- `onBlur` on the visible input: re-focus after 50ms to recover from scanner models that send a focus-steal event
- `autocomplete="off"` on the visible input

**QR Value Security:**
- The raw QR code string (`SB-XXXXXXXXXXXX`) is **never shown** in the visible input field
- The scan buffer is kept in a React ref only and is cleared immediately after use
- Only the resolved student's name and details appear on screen after a successful lookup

**Change Student Dialog (QR scanned while student already selected):**
- If a QR scan is detected and `selectedStudent !== null`, the system fires the QR lookup in the background
- If the lookup resolves a student (even the same one): show a confirmation dialog — "Switch Student?" with the new student's name, grade, and enrollment status
- Dialog options: **"Yes, Switch"** (replaces selected student) / **"No, Keep Current"** (dismisses dialog; current student retained)
- If the new QR resolves to a non-enrolled student, show the Student Not Found / Not Eligible dialog instead

**Student Not Found Dialog (QR lookup returns no match):**
- When a QR scan lookup returns no matching student, show a modal dialog — "Student Not Found"
- Dialog text: "No student was found matching this QR code. Please try scanning again or search by name."
- Single **"Close"** button — dismisses dialog; current state (selected student or empty) is retained unchanged
- Inline input error is **not** used for QR lookup failures — always use the dialog

**Backend Lookup Endpoint (`POST /api/v1/pos/students/lookup`):**
Single endpoint with a `type` parameter routing to two distinct lookup strategies:
- `type: "qr"` — exact match on `students.qr_code` column, branch-scoped. Returns full student data (including wallet balance) immediately since this is a confirmed single student.
- `type: "search"` — partial match on name or student number, branch-scoped, limit 8 results. Returns **minimal data only**: `id`, `full_name`, `grade_level`, `photo_path`, `enrollment_status` — no wallet balance, credit, or points in search list results.

**Student Search Data Scoping:**
- **Search results list** (name/number search — may return multiple): minimal data only — `id`, `full_name`, `grade_level`, `photo_path`, `enrollment_status`
- **After student confirmed** (QR scan or search result selected): full data returned including wallet balance

**After student is identified:**
- Student name, grade, photo, wallet balance, points, and credit balance shown in cart panel
- **Enrollment status check at student selection** — if status is not `enrolled`, show blocking error and prevent adding items to cart
- Visible input field cleared and re-focused after successful selection
- Scan buffer always cleared immediately regardless of lookup outcome

---

## Cart Management

Cart is managed entirely **client-side in Zustand** on the POS Next.js frontend. No server-side session cart.

### Cart Item
- Item name, unit price, quantity stepper (+/−), line total, remove button
- Keyboard shortcut: `Delete` key removes last item (Admin/Manager/Supervisor only)

### Cart Rules
- Minimum 1 item to proceed to checkout
- If student is registered and status is not `enrolled`, cart is blocked
- Cart state lives in the Zustand store; cleared on order completion or page refresh

### Order Notes
- Optional text field at bottom of cart for special instructions
- Sanitized server-side with `strip_tags()` before storage

---

## Checkout & Payment

### Payment Methods
| Method | Registered | Walk-In |
|---|---|---|
| Cash | ✅ | ✅ |
| GCash | ✅ | ✅ |
| Student Wallet | ✅ (enrolled students with wallet) | ❌ |

### Discount
- Admin and Manager only can apply a discount
- Discount is a percentage or fixed amount input
- Reason for discount required (logged in order meta)

### Wallet Payment Flow
1. Student QR must be scanned/identified first
2. Payment method "Student Wallet" becomes available
3. Wallet balance shown with remaining balance after deduction
4. If balance < total: show **Insufficient Funds Modal** with two options:
   - **Reload Wallet** — inline top-up locked to the exact shortfall. Cashier collects cash/GCash from parent, system deposits the exact shortfall, immediately proceeds to checkout. Reload is logged with cashier ID and linked order context.
   - **Use Credit** — available only if `credit_balance + shortfall ≤ CREDIT_LIMIT (₱300)`. Deducts shortfall as credit; sets `is_credit = true` on the order; increments `credit_balance`.
5. On confirm: `withdraw()` from student wallet via `bavix/laravel-wallet`

### Inline POS Wallet Reload
- Triggered from Insufficient Funds Modal → "Reload Wallet" option
- Amount field is **locked** to the exact shortfall (not editable)
- Payment method: Cash or GCash (radio buttons)
- GCash: optional reference number field
- On confirm: `deposit()` to student wallet, logs entry with `meta.source = 'pos_inline_reload'`, `meta.cashier_id`, `meta.order_context`

### Credit Use at POS
- Available when wallet is insufficient AND `student.credit_balance + shortfall ≤ CREDIT_LIMIT`
- Walk-in customers cannot use credit
- On confirm: order flagged `is_credit = true`, `credit_amount = shortfall`
- `student.credit_balance` incremented by `shortfall` via `credit_transactions` insert + atomic `DB::transaction()`
- Receipt shows "Credit Used: ₱X" and current credit balance
- Voiding a credit order: restores `credit_balance` (reduces by `credit_amount`)

### Cash Payment
- "Amount Tendered" input appears
- Change calculated and displayed: `Change = Tendered - Total`
- Proceed disabled until tendered ≥ total

### GCash Payment
- Reference number field (optional) — if provided, validated server-side as alphanumeric max 50 characters

---

## Order Processing

### On Checkout Confirm

**Atomic Wallet Transaction (Race Condition Prevention):**
When payment method is Student Wallet, the entire checkout is wrapped in a `DB::transaction()` with a pessimistic row lock on the student record:
```php
DB::transaction(function () use ($student, $total) {
    $student = Student::lockForUpdate()->findOrFail($student->id);
    if ($student->wallet->balance < $total) {
        throw new InsufficientFundsException();
    }
    // proceed with withdraw() and order creation
});
```
This prevents double-spend if two concurrent requests both pass the balance check before either commits.

Processing steps:
1. Validate cart is not empty
2. Validate payment method requirements
3. Create `Order` record with: `branch_id`, `student_id` (null for walk-in), `cashier_id`, `payment_method`, `subtotal`, `discount_amount`, `total`, `status: completed`, and all payment-specific fields
4. Create `OrderItem` records for each cart item (snapshots name and price at time of order)
5. If wallet payment: `withdraw()` from student wallet via `bavix/laravel-wallet`
6. If credit was used: insert `credit_transactions` (type=Charged); atomically increment `student.credit_balance` in same transaction
7. Update `student.total_spent` += order total; recalculate `student.points`; set `order.points_earned`
8. Clear cart (Zustand store reset on frontend)
9. Return order data for receipt modal

**Inventory deduction at checkout:**
Checkout automatically deducts inventory stock for every item sold. Each `pos_menu_item` must be mapped to one or more `inventory_items` via the `pos_menu_item_inventory` pivot before it can be sold.

Pre-checkout inventory validation (runs before any payment processing):
1. **Mapping check** — if any item in the cart has no inventory mapping (`has_inventory_mapping = false`), reject the entire order with a clear error: "One or more items are not configured for inventory tracking. Please contact your administrator."
2. **Stock check** — if any linked inventory item's quantity would go below 0 for the cart quantities requested, reject the order with: "[Item Name] is out of stock."

On successful order creation (inside the existing `DB::transaction()`):
- For each `OrderItem`, multiply `quantity_used` from the pivot by the cart item quantity to get total units to deduct
- Deduct from each `inventory_item.quantity` (floor at 0; negative stock not allowed)
- Create one `InventoryLog` per deducted inventory item:
  - `type = sale`
  - `quantity_change` = negative deduction amount
  - `stock_after` = quantity after deduction
  - `item_name_snapshot` = inventory item name at time of sale
  - `order_id` = the new order's id
  - `adjusted_by` = `$cashier->id` (the authenticated user)
  - `reason` = "Order #{receipt_number}"

### Receipt Modal
After successful checkout:
- Receipt number (auto-incremented, branch-prefixed: `ANT-2025-001001`)
- Date and time
- Cashier name
- Student name (if registered) or "Walk-In Customer"
- Item list with quantities and prices
- Payment breakdown (method, tendered, change)
- Wallet balance remaining (if wallet used)
- Credit used line (if `is_credit = true`): "Credit Used: ₱X" + "Outstanding Credit: ₱Y"
- Loyalty points display: "⭐ +1 Point Earned!" shown only when a new point threshold is crossed (`points_earned > 0`)
- `[🖨️ Print Receipt]` button triggers browser print with receipt-optimized CSS — optional
- "New Order" button clears cart Zustand state and starts fresh

---

## Data Models

```
orders
  id
  branch_id           (FK → branches)
  student_id          (nullable, FK → students)
  cashier_id          (FK → users)
  receipt_number      (string, unique)
  payment_method      (enum: cash, gcash, wallet)
  subtotal            (decimal)
  discount_amount     (decimal, default 0)
  discount_reason     (string, nullable)
  total               (decimal)
  amount_tendered     (decimal, nullable — cash)
  change_amount       (decimal, nullable — cash)
  reference_number    (string, nullable — gcash)
  notes               (text, nullable)
  is_credit           (boolean, default false)
  credit_amount       (decimal, default 0)
  points_earned       (int, default 0)
  status              (enum: completed, voided)
  voided_at           (timestamp, nullable)
  voided_by           (FK → users, nullable)
  void_reason         (string, nullable)
  created_at, updated_at

order_items
  id
  order_id            (FK → orders)
  pos_menu_item_id    (FK → pos_menu_items)
  name                (string — snapshot of item name at time of order)
  price               (decimal — snapshot of price at time of order)
  quantity            (int)
  line_total          (decimal)
```

---

## Transaction History View

Located on the POS page under the "Transaction History" tab.

### Filters
- Date picker (default: today)
- Payment method filter
- Student search

### Table Columns
- Receipt number
- Time
- Customer (student name or "Walk-In")
- Items (pill summary)
- Payment method badge
- Total amount
- Actions: View Receipt, Void (Admin/Manager/Supervisor only)

### Void Transaction
- Confirmation modal with reason input (required)
- Only Admin/Manager/Supervisor can void
- Voiding a wallet transaction reverses the `withdraw()` via `bavix/laravel-wallet` `refund()`
- Voiding a credit order (`is_credit = true`): also decrements `student.credit_balance` by `order.credit_amount` via `credit_transactions` insert (type=Voided) + atomic `credit_balance` update
- Voiding reverses the `total_spent` increment and recalculates `points`
- Voided orders shown with a strikethrough "VOIDED" badge
- **Inventory restoration on void** — for each `InventoryLog` entry where `order_id = order.id` and `type = sale`, create a new `Restock` log per inventory item:
  - `type = restock`
  - `quantity_change` = the original `quantity_change` converted to positive (restoring the deducted stock)
  - `stock_after` = current quantity after restoration
  - `item_name_snapshot` = item name at time of void
  - `order_id` = voided order's id (for traceability)
  - `adjusted_by` = user performing the void
  - `reason` = "Void: Order #{receipt_number}"
- Inventory restoration runs inside the same `DB::transaction()` as the wallet refund and credit reversal

---

## Keyboard Shortcuts (POS)

| Shortcut | Action |
|---|---|
| `F1` | Focus QR/student search field |
| `F2` | Focus item search |
| `Ctrl + Enter` | Confirm checkout |
| `Escape` | Clear student selection |
| `Alt + W` | Select Walk-In mode |
| `Alt + S` | Select Student mode |
| `Alt + 1/2/3` | Select payment method (Cash/GCash/Wallet) |
| `Delete` | Remove last cart item (Admin/Manager/Supervisor only) |

---

## API Routes

All routes under `auth:sanctum` + `ability:staff` middleware.

| Method | Route | Roles | Description |
|---|---|---|---|
| GET | `/api/v1/pos/menu-items` | all staff | Available menu items for active branch |
| POST | `/api/v1/pos/students/lookup` | all staff | QR or name/number student lookup |
| POST | `/api/v1/pos/checkout` | all staff | Submit cart and create order |
| POST | `/api/v1/pos/inline-reload` | all staff | Inline wallet reload (shortfall only) |
| GET | `/api/v1/pos/transactions` | all staff | Transaction history with filters |
| POST | `/api/v1/pos/transactions/{order}/void` | admin, manager, supervisor | Void a transaction |

---

## Requirements

- [ ] `orders` and `order_items` tables with all fields
- [ ] Cart managed entirely client-side in Zustand on the POS frontend — no server-side session cart
- [ ] POS page layout: left item grid + right cart panel
- [ ] Category tab navigation on item grid (meal/snack/drink/extra)
- [ ] Item search across categories (debounced, `F2` shortcut)
- [ ] QR/student input: auto-focused on page load and after every completed order; `autocomplete="off"`; `onBlur` re-focus with 50ms delay
- [ ] **Global document-level `keydown` listener** — QR scanner captured even when the input is not focused; characters buffered in a hidden ref (`scanBufferRef`), never shown in the visible input
- [ ] **QR value never displayed in input** — raw `SB-XXXXXXXXXXXX` string is captured silently in the ref buffer; only the student's name/details appear on screen after successful lookup
- [ ] Scan detection: inter-keystroke timing < 100ms → characters go into hidden buffer; > 100ms → characters go into visible input (manual typing)
- [ ] On global `Enter` with buffer matching `/^SB-[A-Za-z0-9]{12}$/` → fire QR lookup; clear buffer; input field unchanged
- [ ] On `Enter` in visible input (manual mode) → name/number search
- [ ] `POST /api/v1/pos/students/lookup` with `type: qr|search` — QR returns full student data; search returns minimal data only (id, full_name, grade_level, photo_path, enrollment_status — no wallet/credit/points)
- [ ] **Change Student dialog** — shown when QR scan fires and a student is already selected; displays new student's name + grade; "Yes, Switch" replaces current; "No, Keep Current" dismisses
- [ ] **Student Not Found dialog** — shown (not inline error) when QR lookup returns no match; text: "No student was found matching this QR code."; single "Close" button; current state preserved
- [ ] Input cleared and re-focused after student is successfully selected
- [ ] Walk-in / registered toggle
- [ ] Enrollment status check at student selection time — blocks non-enrolled students immediately on select
- [ ] Cart item CRUD in Zustand: add, update quantity, remove, clear
- [ ] Payment method selector: Cash, GCash, Student Wallet — Wallet disabled for walk-in
- [ ] Discount input restricted to Admin/Manager with reason required
- [ ] Cash: tendered amount + live change calculation; Confirm disabled until tendered ≥ total
- [ ] GCash: optional reference number field — alphanumeric max 50 chars, server-side validated if provided
- [ ] Wallet: balance + "After" preview; red state if insufficient
- [ ] Insufficient Funds Modal with "Reload Wallet" and "Use Credit" options
- [ ] Inline POS wallet reload: amount locked to exact shortfall; logged with `meta.source = 'pos_inline_reload'`
- [ ] Credit use: `is_credit`, `credit_amount` on orders; `credit_transactions` insert + atomic `credit_balance` increment in `DB::transaction()`
- [ ] Credit blocked if `credit_balance + shortfall > CREDIT_LIMIT (₱300)`
- [ ] Credit unavailable for walk-in customers
- [ ] Wallet payment checkout wrapped in `DB::transaction()` with `lockForUpdate()` on student
- [ ] Re-validate balance inside the lock before processing
- [ ] Checkout creates Order and OrderItems with name/price snapshots
- [ ] Pre-checkout inventory mapping check — reject order if any cart item has no inventory mapping; error: "One or more items are not configured for inventory tracking"
- [ ] Pre-checkout stock check — reject order if any linked inventory item would go below 0; error: "[Item Name] is out of stock"
- [ ] On successful checkout: deduct linked inventory items inside `DB::transaction()`; create `Sale` InventoryLog per item with `order_id`, `item_name_snapshot`, `adjusted_by = cashier_id`, `reason = "Order #{receipt_number}"`
- [ ] POS menu grid reflects inventory status: OUT items greyed out and unselectable; LOW items show warning badge (driven by `inventory_status` field on menu items response)
- [ ] Auto-generated receipt number: branch prefix + year + padded sequence (e.g. `ANT-2025-001001`)
- [ ] Update `student.total_spent` and recalculate `student.points`; set `order.points_earned`
- [ ] Zustand cart cleared on successful order completion
- [ ] Receipt modal: optional print button; conditionally shows credit lines, points earned
- [ ] Transaction history tab: date filter, payment method filter, student search
- [ ] Void: reverses wallet via `refund()`; credit balance restoration via `credit_transactions` (type=Voided) + atomic `credit_balance` update; total_spent/points recalculation
- [ ] Void: restore inventory stock — for each `InventoryLog` where `order_id = order.id` and `type = sale`, create reversal `Restock` log with positive quantity, `order_id`, `adjusted_by = voiding user`, `reason = "Void: Order #{receipt_number}"`; runs inside same `DB::transaction()`
- [ ] Void blocked for Cashiers (403)
- [ ] Order notes sanitized with `strip_tags()` server-side before storage
- [ ] POS keyboard shortcuts: F1, F2, Ctrl+Enter, Escape, Alt+W, Alt+S, Alt+1/2/3, Delete
- [ ] `OrderPolicy`: create (all roles), void (admin/manager/supervisor), view (all roles)
- [ ] Log `pos.order_created` (properties: receipt_number, total, payment_method, student_id or "walk-in", cashier_id, is_credit)
- [ ] Log `pos.order_voided` (properties: receipt_number, amount, void_reason, voided_by, payment_method)
- [ ] Log `pos.discount_applied` when discount > 0 (properties: discount_type, amount, reason, receipt_number)
- [ ] Log `wallet.inline_reload` on inline reload (properties: amount, payment_method, cashier_id, order context)
