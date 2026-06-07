# Tasks 06 — POS & Checkout

## 1. Database
- [ ] Migration: `orders` table — `branch_id` (FK), `student_id` (nullable FK), `cashier_id` (FK → users), `receipt_number` (string, unique), `payment_method` (enum: cash/gcash/wallet/subscription), `subtotal` (decimal), `discount_amount` (decimal, default 0), `discount_reason` (nullable), `total` (decimal), `amount_tendered` (nullable), `change_amount` (nullable), `reference_number` (nullable), `notes` (text, nullable), `is_credit` (bool, default false), `credit_amount` (decimal, default 0), `points_earned` (int, default 0), `status` (enum: completed/voided), `voided_at` (nullable), `voided_by` (nullable FK), `void_reason` (nullable), timestamps
- [ ] Migration: `order_items` table — `order_id` (FK), `pos_menu_item_id` (FK), `name` (snapshot), `price` (snapshot), `quantity`, `line_total`
- [ ] `inventory_logs` table already defined in Spec 04 (no duplicate migration needed)
- [ ] Migration: `branch_subscription_configs` table — `branch_id` (FK → branches, unique), `meal_daily_limit` (tinyint unsigned, default 1), `snack_daily_limit` (tinyint unsigned, default 1), `drink_daily_limit` (tinyint unsigned, default 1), `extra_daily_limit` (tinyint unsigned, default 1), timestamps

## 2. Models
- [ ] `Order` model with `HasBranch` trait
- [ ] `OrderItem` model
- [ ] `Order::items()` hasMany relationship
- [ ] `Order::student()` belongsTo relationship
- [ ] `Order::cashier()` belongsTo User relationship
- [ ] `BranchSubscriptionConfig` model:
  - [ ] `$fillable`: `branch_id`, `meal_daily_limit`, `snack_daily_limit`, `drink_daily_limit`, `extra_daily_limit`
  - [ ] `branch()` belongsTo relationship
  - [ ] `static forBranch(int $branchId): self` — `firstOrCreate(['branch_id' => $branchId], [defaults all limits = 1])`
  - [ ] `limitForCategory(MenuCategory $category): int` — match on `MenuCategory` enum to correct limit field
- [ ] `BranchSubscriptionConfigFactory` — default definition with all limits = 1, `branch_id = Branch::factory()`

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
- [ ] `fullStudentData(Student $student)` private method — add `subscription_daily_status` field:
  - For `student_type = subscription`: call `buildDailyStatus($student)` returning `{ meal: {used, limit, remaining}, snack: {...}, drink: {...}, extra: {...} }`
  - For non-subscription students: `subscription_daily_status => null`
- [ ] `buildDailyStatus(Student $student): array` private helper:
  - Query today's completed subscription orders for this student (same query as checkout: `status = completed`, `payment_method = subscription`, `whereDate = today`)
  - Use `OrderItem::menuItem()` relationship (NOT `posMenuItem()`) to group by `category->value`
  - Load `BranchSubscriptionConfig::forBranch($student->branch_id)` for limits
  - Return per-category array keyed by `MenuCategory` value (meal, snack, drink, extra)
- [ ] Routes under `auth:sanctum` + `ability:staff`:
  - `POST /api/v1/pos/students/lookup`
  - `GET /api/v1/pos/students/{student}`

### 4.2 Frontend — QR / Student Search Input
- [x] Auto-focused on page load via `useEffect`; `id="pos-qr-input"`, `autocomplete="off"`
- [x] `onBlur` re-focus with 50ms delay (handles scanner models that steal focus)

#### 4.2a Global QR Scanner Detection (document-level — focus independent)
- [x] `useEffect` attaches a `keydown` listener to `document` on mount; removes on unmount
- [x] Hidden `scanBufferRef` (React ref, `useRef<string>("")`) accumulates characters arriving < 100ms apart — never rendered in the UI
- [x] `lastKeyTimeRef` tracks the timestamp of the previous keydown event for inter-keystroke timing
- [x] On each `keydown` in the global listener:
  - If `event.key.length === 1` (printable character) and inter-keystroke time < 100ms → append to `scanBufferRef`; suppress default only if the target is NOT the visible input
  - If `event.key === "Enter"` and `scanBufferRef.current` matches `/^SB-[A-Za-z0-9]{12}$/` → fire QR lookup; clear buffer; do NOT populate the visible input
  - If `event.key === "Enter"` and buffer does not match QR pattern → clear buffer (discard); fall through to normal input Enter handling
- [x] Raw QR string is **never written to the visible input** — the visible input's `value` state is only set by manual typing

#### 4.2b Change Student Dialog
- [x] State: `pendingQrStudent: PosStudent | null` — holds the newly scanned student while the dialog is open
- [x] When a global QR lookup succeeds AND `selectedStudent !== null`:
  - Set `pendingQrStudent` with the result → opens Change Student dialog
  - Do NOT replace `selectedStudent` yet
- [x] Change Student dialog shows: current student name, new student name + grade + enrollment status badge
- [x] "Yes, Switch" → call `onStudentSelected(pendingQrStudent)`; clear `pendingQrStudent`
- [x] "No, Keep Current" / close → clear `pendingQrStudent`; current student unchanged
- [x] If the pending student is not enrolled → skip the Switch dialog entirely; show "Student Not Eligible" dialog instead

#### 4.2c Student Not Found Dialog
- [x] State: `showNotFoundDialog: boolean`
- [x] When a global QR lookup returns no match (API error or empty result) → set `showNotFoundDialog = true`
- [x] Dialog: title "Student Not Found"; body "No student was found matching this QR code. Please try scanning again or search by name."; single "Close" button
- [x] Dialog closes on button click or click-outside (`onOpenChange`)
- [x] **Never show inline input error for QR lookup failures** — only the dialog
- [x] Current state (selected student or empty) is fully preserved when dialog closes

#### 4.2d Manual Search (unchanged from existing)
- [ ] Manual typing in visible input: debounced 300ms → name/number search dropdown
- [ ] On `Enter` in visible input: if value matches QR pattern → QR lookup; else → name/number search
- [ ] Search results dropdown: minimal data only (name, grade, status); ineligible students grayed with ⛔ badge; no wallet balance shown in dropdown
- [ ] Input cleared and re-focused after successful student selection
- [ ] Selected student card: photo, name, grade, status, wallet balance (shown after confirmed), points (decorative), credit owed badge (red, when > 0)
- [ ] `[Walk-In]` button switches to walk-in mode
- [ ] `[Clear ✕]` button on selected student card clears Zustand student state
- [ ] Update `PosStudent` type (`types/order.ts`) to include `subscription_daily_status: { meal: {used, limit, remaining}, snack: {...}, drink: {...}, extra: {...} } | null`

## 5. Menu Item Grid

### 5.1 Frontend
- [ ] Category tabs: Meal | Snack | Drink | Extra — pill style
- [ ] Item cards grid (3–4 columns, responsive): name, price (`text-xl font-extrabold text-primary`), category badge
- [ ] Click item → adds to Zustand cart; quantity badge top-right when qty > 0 (`bg-primary text-primary-foreground rounded-full`)
- [ ] Unavailable items hidden from grid by default
- [ ] Item search bar (debounced, searches across all categories); `F2` shortcut focuses search
- [ ] **Inventory status on cards** (from `inventory_status` field in menu items response):
  - OUT items (`inventory_status = "OUT"`): card shown at `opacity-40`; click disabled; no cart add
  - LOW items (`inventory_status = "LOW"`): show warning badge `bg-yellow-50 text-amber-700 border-yellow-300 text-[10px]` — "Low Stock"
  - Unmapped items (`has_inventory_mapping = false`): show orange badge `bg-orange-50 text-orange-700 text-[10px]` — "Not Mapped"; click disabled
- [ ] **Subscription status on cards** (from `is_subscription_item` field in menu items response):
  - `is_subscription_item = null`: card at `opacity-40 cursor-not-allowed`; click disabled for ALL payment methods; badge: `bg-gray-100 text-gray-500 text-[10px]` — "⚙ Not Set Up"
  - `is_subscription_item = true`: show blue badge `bg-blue-50 text-blue-700 border-blue-200 text-[10px]` — "SUB" top-left; card otherwise normal
  - `is_subscription_item = false` + subscription payment method active: card at `opacity-40 cursor-not-allowed`; click disabled (not eligible for subscription)
- [ ] **Update `CartItem` type** (`lib/store/cart.ts`): add `category: MenuCategory` field — required for front-end subscription limit checking
- [ ] Wherever cart items are created from `PosMenuItem` clicks, include the `category` field from the menu item

## 6. Cart

### 6.1 Frontend
- [ ] Right panel cart reads from Zustand store: item list with quantity stepper `[−] N [+]`, line total, remove `[✕]` per item
- [ ] All cart mutations (add/update/remove/clear) update Zustand store only — no API calls until checkout
- [ ] Order notes text field (optional)
- [ ] Subtotal, discount section (Admin/Manager only — hidden for others), total display
- [ ] Payment method selector: `[💵 Cash]` `[📱 GCash]` `[👛 Wallet]` `[📋 Subscription]` — Wallet and Subscription disabled for walk-in; Subscription only visible when selected student has `student_type = subscription`
- [ ] Cash panel: "Amount Tendered" input, live change calculation; Confirm disabled until tendered ≥ total
- [ ] GCash panel: optional reference number input
- [ ] Wallet panel: shows balance and "After" balance preview; red state if insufficient, triggers Insufficient Funds Modal
- [ ] Subscription panel: shows per-category usage from `student.subscription_daily_status`; shows warning and disables Confirm if cart would exceed any category limit
- [ ] Discount block (Admin/Manager only): type toggle (% / ₱), amount input, reason input (required); **hidden entirely when subscription payment is selected**
- [ ] **Subscription daily status in student card** (for subscription students):
  - Show per-category pill: `Meal 1/1 ✗` / `Snack 0/1 ✓` — red + ✗ if at limit, green + ✓ if available
  - Show red alert banner immediately if any category limit is already met today
- [ ] **`isCheckoutValid` logic** — extend to include subscription limit check: if `paymentMethod = subscription`, sum cart quantities by category, check against `student.subscription_daily_status`; disable Confirm if any category exceeded

## 7. Checkout

### 7.1 Backend
- [ ] `CheckoutController::store()`
  - [ ] Validate cart items not empty; validate payment method requirements
  - [x] **Pre-checkout inventory mapping check** — for each cart item, verify `pos_menu_item_inventory` has at least one row; if any item is unmapped, return 422: "One or more items are not configured for inventory tracking. Please contact your administrator."
  - [x] **Pre-checkout stock check** — for each cart item, sum up total units to deduct across linked inventory items; if any inventory item's `quantity - deduction < 0`, return 422: "[Item Name] is out of stock."
  - [ ] **Subscription item eligibility check** (when `payment_method = subscription`): filter `$menuItems` for `is_subscription_item !== true`; if any ineligible, return 422: "Item "[name]" is not eligible for subscription payment."
  - [ ] **Subscription daily category limit check** (when `payment_method = subscription`, inside DB transaction):
    - Query `OrderItem` for today's completed subscription orders for this student using `menuItem()` relationship; group by `category->value`, sum quantities
    - Load `BranchSubscriptionConfig::forBranch($branch->id)` for limits
    - Group cart items by category, compare `used + requested > limit`; if exceeded, return 422: "Daily [category] limit of [N] reached for this student."
  - [ ] Wrap all of the following in `DB::transaction()`:
  - [ ] For wallet payment: `Student::lockForUpdate()` — prevents double-spend race condition; re-validate balance inside the lock
  - [ ] Sanitize `notes` with `strip_tags()` before storage
  - [ ] GCash reference validation: alphanumeric, max 50 chars
  - [ ] Auto-generate `receipt_number`: branch prefix + year + padded sequence (e.g. `ANT-2025-001001`)
  - [ ] Create `Order` and `OrderItem` records (name/price snapshots on items)
  - [ ] Wallet payment: `withdraw()` via bavix
  - [ ] Credit used: insert `credit_transactions` (type=Charged); atomically increment `student.credit_balance` in same `DB::transaction()`
  - [ ] Update `student.total_spent` += order total; recalculate `student.points`; set `order.points_earned`
  - [x] **Inventory deduction** (inside the same transaction) — for each `OrderItem`:
    - For each linked `InventoryItem` via pivot: `deduction = pivot.quantity_used × order_item.quantity`
    - Update `inventory_item.quantity -= deduction` (floor at 0)
    - Create `InventoryLog`: `type=sale`, `quantity_change=-deduction`, `stock_after=new_qty`, `item_name_snapshot=$item->name`, `order_id=$order->id`, `adjusted_by=$cashier->id`, `reason="Order #{$receipt_number}"`
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
- [ ] **Subscription payment receipt** — show "Payment Method: 📋 Subscription" only; hide tendered/change/wallet remaining fields; total still shown at actual item prices

## 7a. Subscription Config (Admin References)

### 7a.1 Backend
- [ ] `SubscriptionConfigController`:
  - [ ] `show()` — returns `BranchSubscriptionConfig::forBranch($branch->id)` as JSON (uses branch from authenticated user's active branch)
  - [ ] `update()` — validates per-category limits (`integer|min:0|max:10`); saves and returns updated config
- [ ] Routes under `auth:sanctum` + `ability:staff` + `role:admin|manager`:
  - `GET /api/v1/pos/subscription-config`
  - `PUT /api/v1/pos/subscription-config`

### 7a.2 Frontend
- [ ] `app/(kitchen)/references/subscription-config/page.tsx` — check if `page.tsx` exists; if not, create it
- [ ] Page: form with four numeric inputs (Meal, Snack, Drink, Extra daily limits; each min 0, max 10)
- [ ] Fetches current config on load via `useQuery` → `GET /api/v1/pos/subscription-config`
- [ ] Saves via `useMutation` → `PUT /api/v1/pos/subscription-config`
- [ ] Show loading skeleton while fetching; show success toast on save; disable save button while mutation is pending

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
- [ ] `TransactionController::void()` — Admin/Manager/Supervisor only; requires reason; inside `DB::transaction()`:
  - [ ] Reverses wallet via `refund()` (if wallet payment)
  - [ ] If `is_credit`: insert `credit_transactions` (type=Voided) + atomically decrement `student.credit_balance`
  - [ ] Reverses `total_spent`/`points`
  - [x] **Inventory restoration** — query `InventoryLog` where `order_id = order.id` and `type = sale`; for each log: restore `inventory_item.quantity += abs(quantity_change)`; create new `InventoryLog`: `type=restock`, `quantity_change=+restored_amount`, `stock_after=new_qty`, `item_name_snapshot=original_log.item_name_snapshot`, `order_id=order.id`, `adjusted_by=voiding_user->id`, `reason="Void: Order #{$receipt_number}"`
  - [ ] Logs `pos.order_voided`
- [ ] Routes under `auth:sanctum` + `ability:staff`:
  - `GET /api/v1/pos/transactions`
  - `POST /api/v1/pos/transactions/{order}/void` — role:admin,manager,supervisor

### 9.2 Frontend
- [ ] Transaction History tab: date filter, payment method filter, student search via `useQuery`
- [ ] Summary row: total txns, revenue today, walk-ins
- [ ] Table: time, receipt#, customer, items (pill summary), payment badge, total, `[View]` `[Void]`
- [ ] Voided rows: strikethrough text, `[VOIDED]` badge, dimmed
- [ ] Void modal: customer, amount, payment method, wallet refund notice, reason input (required); `useMutation` for submit
- [ ] **Subscription void modal** — replace "Wallet will be refunded ₱X" with "Daily allowance will be restored." when `payment_method = subscription`
- [ ] Transaction history subscription badge: `bg-teal-50 text-teal-700` — "Subscription"

## 10. Keyboard Shortcuts
- [ ] `F1` — focus QR/student search field
- [ ] `F2` — focus item search field
- [ ] `Ctrl + Enter` — confirm checkout (if cart non-empty and form valid)
- [ ] `Escape` — clear student selection
- [ ] `Alt + W` — select Walk-In mode
- [ ] `Alt + S` — select Student mode
- [ ] `Alt + 1/2/3/4` — select payment method (Cash/GCash/Wallet/Subscription — `Alt+4` only activates if subscription is available for the current student)
- [ ] `Delete` — removes last cart item (Admin/Manager/Supervisor only)

## 11. Policies
- [ ] `OrderPolicy`: create (all roles), void (admin/manager/supervisor), view (all roles)

## 12. Tests
- [ ] `PosCheckoutTest` — cash checkout creates order; gcash checkout with/without reference; wallet checkout with sufficient balance; receipt number generation; points calculation on threshold
- [x] Update `PosCheckoutTest`:
  - [x] Checkout blocked when cart item has no inventory mapping (422 with correct error message)
  - [x] Checkout blocked when linked inventory item is OUT (422 with item name in error)
  - [x] Successful checkout deducts correct inventory quantities and creates `sale` InventoryLog entries with `order_id`, `item_name_snapshot`, `adjusted_by = cashier_id`
  - [ ] Checkout with LOW inventory proceeds (warns but does not block)
- [ ] `PosWalletLockTest` — concurrent wallet checkout with `lockForUpdate()` prevents double-spend; balance re-validated inside lock
- [ ] `PosStudentLookupTest` — QR lookup returns full student data including wallet; search returns minimal data only (no wallet/credit); non-enrolled student returns blocking error
  - [ ] QR lookup for subscription student includes `subscription_daily_status` with per-category `{used, limit, remaining}` structure
  - [ ] QR lookup for non-subscription student returns `subscription_daily_status: null`
  - [ ] Daily status counts reflect only completed subscription orders from today (not voided, not other payment methods, not other days)
- [ ] `PosVoidTest` — void reverses wallet via refund(); void of credit order inserts Voided credit_transaction and decrements credit_balance; total_spent/points restored; cashier cannot void (403)
- [x] Update `PosVoidTest`:
  - [x] Void restores inventory stock — `InventoryLog` records with `type=restock` created for each deducted item, quantities restored
  - [ ] Void inventory restoration runs inside same transaction — if restoration fails, wallet refund also rolls back
- [ ] `PosInsufficientFundsTest` — inline reload locked to exact shortfall; credit use inserts Charged credit_transaction; credit limit enforcement blocks use when exceeded
- [ ] `SubscriptionCheckoutTest` (new file):
  - [ ] Subscription payment blocked if any cart item has `is_subscription_item = false`
  - [ ] Subscription payment blocked if any cart item has `is_subscription_item = null`
  - [ ] Subscription payment succeeds when all items have `is_subscription_item = true` and limits not exceeded
  - [ ] Checkout blocked when daily limit for a category is already met (used ≥ limit)
  - [ ] Checkout allowed when different category is full (meal full → snack order still proceeds)
  - [ ] Daily limit check counts only `status = completed` subscription orders from today (not voided, not other payment methods)
  - [ ] Voided subscription order is excluded from daily count — allowance effectively restored
  - [ ] Per-category limits are independent — one category reaching limit does not affect others
  - [ ] Non-subscription student cannot use subscription payment method (422)
- [ ] `SubscriptionConfigTest` (new file):
  - [ ] Admin can GET current branch subscription config (returns defaults if no row exists yet)
  - [ ] Admin can PUT per-category limits; changes are persisted
  - [ ] Manager can GET and PUT subscription config
  - [ ] Cashier/Supervisor receives 403 on PUT
  - [ ] Validation rejects limits below 0 or above 10
