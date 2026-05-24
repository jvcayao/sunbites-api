# Tasks 04 — Menu & Product Management

## Part 1: POS Menu Items

### 1.1 Database
- [x] Migration: `pos_menu_items` table — `branch_id` (FK), `name`, `price` (decimal 8,2), `category` (enum: meal/snack/drink/extra), `is_available` (bool, default true), `sort_order` (int, default 0), timestamps
- [x] Factory: `PosMenuItemFactory`

### 1.2 Model
- [x] `PosMenuItem` model with `HasBranch` trait, `LogsActivity` trait
- [x] `$logAttributes` allowlist: `name`, `price`, `category`, `is_available`, `sort_order`
- [x] `$recordEvents = ['created', 'updated', 'deleted']`

### 1.3 Seeder
- [x] `PosMenuItemSeeder` — 7 items seeded per branch via `updateOrCreate` keyed on `(branch_id, name)`:
  - Subscription Meal Tray — ₱135.00 — meal
  - Snack A (Bread/Pastry) — ₱15.00 — snack
  - Snack B (Chips/Crackers) — ₱20.00 — snack
  - Snack C (Juice/Water) — ₱15.00 — drink
  - Snack D (Fruit Cup) — ₱25.00 — snack
  - Additional Rice — ₱10.00 — extra
  - Special Snack — ₱30.00 — snack
- [x] Register `PosMenuItemSeeder` in `DatabaseSeeder` after `BranchSeeder`

### 1.4 Backend
- [x] `PosMenuItemController`
  - [x] `index()` — returns all items for active branch
  - [x] `store()` — validates name/price/category; creates item; logs `menu.item_created`
  - [x] `toggleAvailability()` — flips `is_available`; logs `menu.item_toggled`
  - [x] `destroy()` — deletes with confirmation; logs `menu.item_deleted`
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin|manager`:
  - `GET /api/v1/pos/menu-items`
  - `POST /api/v1/pos/menu-items`
  - `POST /api/v1/pos/menu-items/{item}/toggle`
  - `DELETE /api/v1/pos/menu-items/{item}`
- [x] `PosMenuItemPolicy` — Admin/Manager can manage; all staff roles can view

### 1.5 Frontend (POS App — `~/sunbites-pos`)
- [x] POS page at `app/(kitchen)/pos/page.tsx` with tab navigation: POS | Transaction History | Menu Mgmt | Inventory
- [x] Menu Mgmt tab — grid of item cards:
  - [x] Item name, price, category badge, availability toggle (`Switch`), delete button
  - [x] Unavailable cards shown at 50% opacity
  - [x] Toggle calls `PATCH /api/v1/pos/menu-items/{item}/toggle` via `useMutation`
  - [x] Delete calls `DELETE /api/v1/pos/menu-items/{item}` via `useMutation` with confirmation dialog
- [x] Category badge colors: meal=`bg-primary/10 text-primary`, snack=`bg-amber-50 text-amber-700`, drink=`bg-blue-50 text-blue-700`, extra=`bg-muted text-muted-foreground`
- [x] Inline "Add New Item" form (dashed border card): name input, ₱ price input, category Select, `[+ Add Item]` button with inline validation errors

### 1.6 Tests
- [x] `PosMenuItemTest` — admin can create/toggle/delete; manager can too; supervisor/cashier cannot manage; items are branch-scoped

---

## Part 2: Weekly Meal Planner

### 2.1 Database
- [x] Migration: `weekly_meal_plans` table — `branch_id` (FK), `school_month` (enum), `week_number` (tinyint 1–4), `day_of_week` (enum: monday–friday), `ulam`, `vegetables`, `fruit`, `soup` (nullable strings), timestamps
- [x] UNIQUE KEY on `(branch_id, school_month, week_number, day_of_week)`

### 2.2 Model
- [x] `WeeklyMealPlan` model with `HasBranch` trait

### 2.3 Seeder
- [x] `WeeklyMealPlanSeeder` — applies default week pattern to all 10 months × 4 weeks per branch via `updateOrCreate`:
  - Monday: Chicken Adobo / Chopsuey / Mango / Nilaga Soup
  - Tuesday: Pork Sinigang / Pinakbet / Banana / Miso Soup
  - Wednesday: Fish Tinola / Laing / Apple / Sinigang Broth
  - Thursday: Beef Kaldereta / Ginisang Gulay / Orange / Chicken Broth
  - Friday: Chicken Inasal / Ampalaya / Watermelon / Corn Soup
- [x] Register `WeeklyMealPlanSeeder` in `DatabaseSeeder` after `PosMenuItemSeeder`

### 2.4 Backend
- [x] `MealPlannerController`
  - [x] `show()` — returns all 5 day records for given `month` + `week` query params + active branch
  - [x] `update()` — upserts all 5 rows via `updateOrCreate`; validates fields; logs `meal_planner.saved`
  - [x] `reset()` — restores default week pattern for given month+week; logs `meal_planner.saved`
- [x] Routes:
  - `GET /api/v1/references/meal-planner` — all authenticated staff roles
  - `PATCH /api/v1/references/meal-planner` — Admin, Manager only
  - `POST /api/v1/references/meal-planner/reset` — Admin, Manager only
  - `GET /api/v1/portal/meal-planner` — portal route, `ability:parent`

### 2.5 Frontend — Meal Planner Page (POS App)
- [x] Meal Planner page at `app/(kitchen)/references/meal-planner/page.tsx`
- [x] Month tabs: pill buttons Jun–Mar; active = `bg-primary text-primary-foreground`
- [x] Week tabs: Week 1–4; active = `bg-primary/10 text-primary border-primary font-bold`
- [x] Editable meal grid table (Admin/Manager): rows = Mon–Fri, columns = Day / Ulam / Vegetables / Fruit / Soup
  - [x] Column headers: `bg-primary text-primary-foreground`
  - [x] Day column: `bg-muted text-primary font-bold` — non-editable
  - [x] Cell backgrounds: Ulam=`bg-orange-50`, Vegetables=`bg-green-50`, Fruit=`bg-blue-50`, Soup=`bg-sky-50`
- [x] Read-only view for Supervisor/Cashier (plain text cells, same color coding, no Save/Reset)
- [x] `[💾 Save Week]` button — `bg-green-600 text-white`; toast on success
- [x] `[↺ Reset]` ghost button — confirmation dialog → restores default pattern

### 2.6 Tests
- [x] `MealPlannerTest` — admin/manager can save and reset; supervisor/cashier cannot save; data is branch-scoped; upsert works correctly

---

## Part 3: Inventory Management

### 3.1 Database
- [x] Migration: `inventory_items` table — `branch_id` (FK), `name`, `quantity` (decimal 8,2), `unit`, `restock_threshold` (decimal 8,2), timestamps
- [x] Migration: `inventory_logs` table — `branch_id`, `inventory_item_id` (FK), `adjusted_by` (FK → users), `type` (enum: restock/waste/manual/sale), `quantity_change` (decimal 8,2), `stock_after` (decimal 8,2), `reason`, `created_at`
- [x] Factory: `InventoryItemFactory`

### 3.2 Model
- [x] `InventoryItem` model with `HasBranch` trait, `LogsActivity` trait
- [x] `$logAttributes` allowlist: `name`, `quantity`, `unit`, `restock_threshold`
- [x] Status accessor: `qty == 0` → `OUT`, `qty <= restock_threshold` → `LOW`, else `OK`
- [x] `InventoryLog` model

### 3.3 Seeder
- [x] `InventoryItemSeeder` — 5 items per branch via `updateOrCreate` keyed on `(branch_id, name)`:
  - Rice — 50 kg — restock threshold 20
  - Chicken — 8 kg — restock threshold 10
  - Vegetables — 15 kg — restock threshold 5
  - Bread — 30 pcs — restock threshold 20
  - Juice Boxes — 3 boxes — restock threshold 10
- [x] Register `InventoryItemSeeder` in `DatabaseSeeder` after `WeeklyMealPlanSeeder`

### 3.4 Backend — Inventory APIs
- [x] `InventoryController::index()` — returns all items for active branch with computed status
- [x] `InventoryController::adjust()` — validates type/quantity/reason; updates quantity; creates `inventory_logs` entry; logs `inventory.adjusted`
- [x] `InventoryController::store()` / `update()` / `destroy()` — full CRUD for References page
- [x] `InventoryController::logs()` — returns log entries for a specific item
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin|manager|supervisor`:
  - `GET /api/v1/pos/inventory`
  - `POST /api/v1/pos/inventory/{item}/adjust`
  - `GET|POST /api/v1/references/inventory`
  - `PUT|DELETE /api/v1/references/inventory/{item}`
  - `GET /api/v1/references/inventory/{item}/logs`

### 3.5 Frontend — Inventory Tab (POS App)
- [x] Inventory tab within POS page — table: Item Name, Qty, Unit, Threshold, Status, Action
- [x] Status badges: OK=`bg-green-100 text-green-700`, LOW=`bg-yellow-100 text-amber-700`, OUT=`bg-red-100 text-destructive`
- [x] Stock Adjustment modal per row:
  - [x] Adjustment type radio: Add Stock / Deduct Stock
  - [x] Quantity input, Reason select (presets + Other)
  - [x] Live "New Total" preview calculation
  - [x] `[Save Adjustment]` button via `useMutation`

### 3.6 Frontend — References > Inventory (POS App)
- [x] Full inventory page at `app/(kitchen)/references/inventory/page.tsx`
- [x] Same table + Edit name/unit/threshold, Log history, Delete
- [x] Inline "Add Item" form at bottom
- [x] Expandable log history per item

### 3.7 Tests
- [x] `InventoryTest` — admin/manager/supervisor can view and adjust; cashier returns 403; adjustment creates log entry; branch-scoped
