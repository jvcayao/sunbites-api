# Tasks 06 — POS & Checkout

## 1. Database
- [ ] Migration: `orders` table — `branch_id` (FK), `student_id` (nullable FK), `cashier_id` (FK → users), `receipt_number` (string, unique), `payment_method` (enum: cash/gcash/wallet), `subtotal` (decimal), `discount_amount` (decimal, default 0), `discount_reason` (nullable), `total` (decimal), `amount_tendered` (nullable), `change_amount` (nullable), `reference_number` (nullable), `notes` (text, nullable), `is_credit` (bool, default false), `credit_amount` (decimal, default 0), `points_earned` (int, default 0), `status` (enum: completed/voided), `voided_at` (nullable), `voided_by` (nullable FK), `void_reason` (nullable), timestamps
- [ ] Migration: `order_items` table — `order_id` (FK), `pos_menu_item_id` (FK), `name` (snapshot), `price` (snapshot), `quantity`, `line_total`
- [ ] `inventory_logs` table already defined in Spec 04 (no duplicate migration needed)

## 2. Models
- [ ] `Order` model with `HasBranch` trait
- [ ] `OrderItem` model
- [ ] `Order::items()` hasMany relationship
- [ ] `Order::student()` belongsTo relationship
- [ ] `Order::cashier()` belongsTo User relationship

## 3. POS Page Structure

### 3.1 Backend
- [ ] `PosController::index()` — returns available menu items (`is_available = true`) and active branch data for the POS page load
- [ ] Route under `auth:sanctum` + `ability:staff`:
  - `GET /api/v1/pos/menu-items`

### 3.2 Frontend — Page Shell
- [ ] POS page at `app/(kitchen)/pos/page.tsx` with tab navigation
- [ ] Tab styles: active = `bg-primary text-primary-foreground rounded-full`, inactive = `border-2 border-border bg-background rounded-full`
- [ ] Cart state in Zustand store (`lib/store/cart.ts`): items array, add/update/remove/clear actions

## 4. Student Lookup

### 4.1 Backend
- [ ] `StudentLookupController::lookup()` — single endpoint with `type` param:
  - `type: "qr"` — exact match on `students.qr_code`, branch-scoped; returns full student data (wallet balance, credit, points)
  - `type: "search"` — partial match on name or student_number, branch-scoped, limit 8; returns minimal data only: `id`, `full_name`, `grade_level`, `photo_path`, `enrollment_status` — NO wallet/credit/points in list
- [ ] Enrollment status check — if not `enrolled`, return error payload that frontend uses to block cart
- [ ] Route under `auth:sanctum` + `ability:staff`:
  - `POST /api/v1/pos/students/lookup`

### 4.2 Frontend — QR / Student Search Input
- [ ] Auto-focused on page load via `useEffect`; `id="pos-qr-input"`, `autocomplete="off"`
- [ ] Scan detection: inter-keystroke time < 100ms → suppress debounce (scanner in progress)
- [ ] On `Enter`: if value matches `/^SB-[A-Za-z0-9]{12}$/` → QR lookup; else → name/number search via `useMutation`
- [ ] Manual typing: debounced 300ms → name/number search dropdown
- [ ] `onBlur` re-focus with 50ms delay (handles scanner models that steal focus)
- [ ] Input cleared and re-focused after successful student selection
- [ ] Search results dropdown: minimal data only (name, grade, status); ineligible students grayed with ⛔ badge; no wallet balance shown in dropdown
- [ ] Selected student card: photo, name, grade, status, wallet balance (shown after confirmed), points (decorative), credit owed badge (red, when > 0)
- [ ] `[Walk-In]` button switches to walk-in mode
- [ ] `[Clear ✕]` button on selected student card clears Zustand student state

## 5. Menu Item Grid

### 5.1 Frontend
- [ ] Category tabs: Meal | Snack | Drink | Extra — pill style
- [ ] Item cards grid (3–4 columns, responsive): name, price (`text-xl font-extrabold text-primary`), category badge
- [ ] Click item → adds to Zustand cart; quantity badge top-right when qty > 0 (`bg-primary text-primary-foreground rounded-full`)
- [ ] Unavailable items hidden from grid by default
- [ ] Item search bar (debounced, searches across all categories); `F2` shortcut focuses search

## 6. Cart

### 6.1 Frontend
- [ ] Right panel cart reads from Zustand store: item list with quantity stepper `[−] N [+]`, line total, remove `[✕]` per item
- [ ] All cart mutations (add/update/remove/clear) update Zustand store only — no API calls until checkout
- [ ] Order notes text field (optional)
- [ ] Subtotal, discount section (Admin/Manager only — hidden for others), total display
- [ ] Payment method selector: `[💵 Cash]` `[📱 GCash]` `[👛 Wallet]` — Wallet disabled for walk-in
- [ ] Cash panel: "Amount Tendered" input, live change calculation; Confirm disabled until tendered ≥ total
- [ ] GCash panel: optional reference number input
- [ ] Wallet panel: shows balance and "After" balance preview; red state if insufficient, triggers Insufficient Funds Modal
- [ ] Discount block (Admin/Manager only): type toggle (% / ₱), amount input, reason input (required)

## 7. Checkout

### 7.1 Backend
- [ ] `CheckoutController::store()`
  - [ ] Validate cart items not empty; validate payment method requirements
  - [ ] For wallet payment: wrap in `DB::transaction()` with `Student::lockForUpdate()` — prevents double-spend race condition
  - [ ] Re-validate balance inside the lock
  - [ ] Sanitize `notes` with `strip_tags()` before storage
  - [ ] GCash reference validation: alphanumeric, max 50 chars
  - [ ] Auto-generate `receipt_number`: branch prefix + year + padded sequence (e.g. `ANT-2025-001001`)
  - [ ] Create `Order` and `OrderItem` records (name/price snapshots on items)
  - [ ] Wallet payment: `withdraw()` via bavix
  - [ ] Credit used: insert `credit_transactions` (type=Charged); atomically increment `student.credit_balance` in same `DB::transaction()`
  - [ ] Update `student.total_spent` += order total; recalculate `student.points`; set `order.points_earned`
  - [ ] Log `pos.order_created` (properties: receipt_number, total, payment_method, student_id or "walk-in", cashier_id, is_credit)
  - [ ] Log `pos.discount_applied` if discount > 0
- [ ] Route under `auth:sanctum` + `ability:staff`:
  - `POST /api/v1/pos/checkout`

### 7.2 Frontend — Receipt Modal
- [ ] `useMutation` for `POST /api/v1/pos/checkout`; on success: clear Zustand cart and open receipt modal
- [ ] Receipt modal after successful checkout
- [ ] Shows: receipt number, date/time, cashier, customer (or "Walk-In"), items, payment breakdown
- [ ] Wallet remaining (if wallet payment)
- [ ] Credit Used + Outstanding Credit lines (conditionally rendered when `is_credit = true`)
- [ ] `⭐ +X Point Earned!` (conditionally rendered when `points_earned > 0`)
- [ ] `[🖨️ Print Receipt]` optional button — browser print, receipt-optimized CSS
- [ ] `[🛒 New Order]` button: clears Zustand cart + student state, closes modal, re-focuses QR field

## 8. Insufficient Funds Modal

### 8.1 Frontend
- [ ] Shown when wallet selected but balance < total
- [ ] Displays: student name, wallet balance, order total, shortfall
- [ ] **Reload Wallet** option (green card): amount locked to exact shortfall (read-only); payment method Cash/GCash; GCash ref field; `[Reload ₱X & Continue]` via `useMutation`
- [ ] **Use Credit** option (orange card): disabled if `credit_balance + shortfall > CREDIT_LIMIT (₱300)`; disabled message when limit reached
- [ ] `[Cancel]` button

### 8.2 Backend
- [ ] `InlineReloadController::store()` — validates shortfall amount; `deposit()` to wallet; logs `wallet.inline_reload` with `meta.source = 'pos_inline_reload'`, `meta.cashier_id`, `meta.order_context`
- [ ] Route under `auth:sanctum` + `ability:staff`:
  - `POST /api/v1/pos/inline-reload`

## 9. Transaction History Tab

### 9.1 Backend
- [ ] `TransactionController::index()` — branch-scoped, filtered by date (default today), payment method, student search; paginated
- [ ] `TransactionController::void()` — Admin/Manager/Supervisor only; requires reason; reverses wallet via `refund()`; if `is_credit`: insert `credit_transactions` (type=Voided) + atomically decrement `student.credit_balance`; reverses `total_spent`/`points`; logs `pos.order_voided`
- [ ] Routes under `auth:sanctum` + `ability:staff`:
  - `GET /api/v1/pos/transactions`
  - `POST /api/v1/pos/transactions/{order}/void` — role:admin,manager,supervisor

### 9.2 Frontend
- [ ] Transaction History tab: date filter, payment method filter, student search via `useQuery`
- [ ] Summary row: total txns, revenue today, walk-ins
- [ ] Table: time, receipt#, customer, items (pill summary), payment badge, total, `[View]` `[Void]`
- [ ] Voided rows: strikethrough text, `[VOIDED]` badge, dimmed
- [ ] Void modal: customer, amount, payment method, wallet refund notice, reason input (required); `useMutation` for submit

## 10. Keyboard Shortcuts
- [ ] `F1` — focus QR/student search field
- [ ] `F2` — focus item search field
- [ ] `Ctrl + Enter` — confirm checkout (if cart non-empty and form valid)
- [ ] `Escape` — clear student selection
- [ ] `Alt + W` — select Walk-In mode
- [ ] `Alt + S` — select Student mode
- [ ] `Alt + 1/2/3` — select payment method (Cash/GCash/Wallet)
- [ ] `Delete` — removes last cart item (Admin/Manager/Supervisor only)

## 11. Policies
- [ ] `OrderPolicy`: create (all roles), void (admin/manager/supervisor), view (all roles)

## 12. Tests
- [ ] `PosCheckoutTest` — cash checkout creates order; gcash checkout with/without reference; wallet checkout with sufficient balance; receipt number generation; points calculation on threshold
- [ ] `PosWalletLockTest` — concurrent wallet checkout with `lockForUpdate()` prevents double-spend; balance re-validated inside lock
- [ ] `PosStudentLookupTest` — QR lookup returns full student data including wallet; search returns minimal data only (no wallet/credit); non-enrolled student returns blocking error
- [ ] `PosVoidTest` — void reverses wallet via refund(); void of credit order inserts Voided credit_transaction and decrements credit_balance; total_spent/points restored; cashier cannot void (403)
- [ ] `PosInsufficientFundsTest` — inline reload locked to exact shortfall; credit use inserts Charged credit_transaction; credit limit enforcement blocks use when exceeded
