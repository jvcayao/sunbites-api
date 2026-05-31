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
- [x] **Layout change**: Move "Add New Item" form to the **top** of the Menu Mgmt tab; item cards grid displayed below the form — currently form is at bottom, needs to be moved up
- [ ] **Terminology change**: Rename all "Ingredients" / "Ingredient" / "Not mapped" labels to **"Linked Stock"** / "Link Stock" / "Not linked":
  - Card button: "Link Stock" (was "Ingredients")
  - Panel title: "Linked Stock: {name}" (was "Ingredients: ...")
  - Warning badge on card: "Not linked" (was "Not mapped")
  - Column header: "Qty Deducted per Sale" (was "Qty Used per Sale")
  - Add action: "Add Link" (was "Add")
  - Warning text: "All menu items must have at least one stock item linked before they can be sold at checkout."

### 1.6 Tests
- [x] `PosMenuItemTest` — admin can create/toggle/delete; manager can too; supervisor/cashier cannot manage; items are branch-scoped

---

## Part 2: Weekly Meal Planner

### 2.1 Database
- [x] Migration: `weekly_meal_plans` table — `branch_id` (FK), `school_month` (enum), `week_number` (tinyint 1–4), `day_of_week` (enum: monday–friday), `ulam`, `vegetables`, `fruit`, `soup` (nullable strings), timestamps
- [x] UNIQUE KEY on `(branch_id, school_month, week_number, day_of_week)`
- [x] Migration: add `snacks` (nullable string) column to `weekly_meal_plans` — done: `2026_05_31_062246_add_snacks_to_weekly_meal_plans_table.php`
- [x] Update migration `2026_05_31_062251` to create `meal_planner_week_visibility` table instead of `meal_planner_category_visibility`:
  - `branch_id` (FK → branches, cascade delete), `school_month` (enum), `week_number` (tinyint 1–4), `visible_to_parents` (boolean, default true), `updated_at`
  - UNIQUE KEY on `(branch_id, school_month, week_number)`

### 2.2 Model
- [x] `WeeklyMealPlan` model with `HasBranch` trait
- [x] Add `snacks` to `$fillable` on `WeeklyMealPlan` — done: `snacks` present in `$fillable`
- [x] Rename `MealPlannerCategoryVisibility` model → `MealPlannerWeekVisibility`:
  - `HasBranch` trait; `$fillable`: `branch_id`, `school_month`, `week_number`, `visible_to_parents`
  - Cast `school_month` to `SchoolMonth` enum; cast `visible_to_parents` to boolean
  - `CREATED_AT = null`; `$table = 'meal_planner_week_visibility'`
- [x] Delete `app/Enums/MealPlannerCategory.php` — no longer needed (was only used for category visibility)

### 2.3 Seeder
- [x] `WeeklyMealPlanSeeder` — applies default week pattern to all 10 months × 4 weeks per branch via `updateOrCreate`:
  - Monday: Chicken Adobo / Chopsuey / Mango / Nilaga Soup
  - Tuesday: Pork Sinigang / Pinakbet / Banana / Miso Soup
  - Wednesday: Fish Tinola / Laing / Apple / Sinigang Broth
  - Thursday: Beef Kaldereta / Ginisang Gulay / Orange / Chicken Broth
  - Friday: Chicken Inasal / Ampalaya / Watermelon / Corn Soup
- [x] Update `WeeklyMealPlanSeeder` to include `snacks` in the default pattern — done: Graham Crackers / Bread Roll / Biscuit / Banana Cue / Puto seeded per day
- [x] Register `WeeklyMealPlanSeeder` in `DatabaseSeeder` after `PosMenuItemSeeder`
- [x] Rename `MealPlannerCategoryVisibilitySeeder` → `MealPlannerWeekVisibilitySeeder`:
  - Seeds all 40 week combinations (10 months × 4 weeks) as `visible_to_parents = true` per branch via `updateOrCreate`
  - Keyed on `(branch_id, school_month, week_number)`
  - Update registration in `DatabaseSeeder` to use new class name

### 2.4 Backend
- [x] `MealPlannerController`
  - [x] `show()` — returns all 5 day records for given `month` + `week` query params + active branch
  - [x] Update `show()` to include `snacks` field per row AND `visible_to_parents` (boolean) for the current month+week from `meal_planner_week_visibility`
  - [x] `update()` — upserts all 5 rows via `updateOrCreate`; validates fields; logs `meal_planner.saved`
  - [x] Update `update()` to accept and persist `snacks` field per row — done
  - [x] `reset()` — restores default week pattern for given month+week; logs `meal_planner.saved` — done
  - [x] Replace `updateCategoryVisibility()` with `updateWeekVisibility()`:
    - Validates: `month` (SchoolMonth enum), `week` (int 1–4), `visible_to_parents` (boolean)
    - Admin/Manager only
    - `updateOrCreate` on `meal_planner_week_visibility` keyed on `(branch_id, month, week)`
    - Logs `meal_planner.week_visibility_changed` with properties: month, week, visible_to_parents
    - Returns 200 with `visible_to_parents` boolean
- [x] Routes:
  - `GET /api/v1/references/meal-planner` — all authenticated staff roles
  - `PATCH /api/v1/references/meal-planner` — Admin, Manager only
  - `POST /api/v1/references/meal-planner/reset` — Admin, Manager only
  - `GET /api/v1/portal/meal-planner` — portal route, `ability:parent`
- [x] Replace route `PATCH /api/v1/references/meal-planner/category-visibility` with `PATCH /api/v1/references/meal-planner/week-visibility` — Admin, Manager only
- [x] Update portal `GET /api/v1/portal/meal-planner`:
  - If `visible_to_parents = false` for that month+week: return `{ visible_to_parents: false, days: [] }` (no meal data)
  - If `visible_to_parents = true` (or no record = default true): return full `{ visible_to_parents: true, days: [...] }` with snacks included

### 2.5 Frontend — Meal Planner Page (POS App)
- [x] Meal Planner page at `app/(kitchen)/references/meal-planner/page.tsx`
- [x] Month tabs: pill buttons Jun–Mar; active = `bg-primary text-primary-foreground`
- [x] Week tabs: Week 1–4; active = `bg-primary/10 text-primary border-primary font-bold`
- [x] Editable meal grid table (Admin/Manager): rows = Mon–Fri, columns = Day / Ulam / Vegetables / Fruit / Soup
  - [x] Column headers: `bg-primary text-primary-foreground`
  - [x] Day column: `bg-muted text-primary font-bold` — non-editable
  - [x] Cell backgrounds: Ulam=`bg-orange-50`, Vegetables=`bg-green-50`, Fruit=`bg-blue-50`, Soup=`bg-sky-50`
- [x] Add Snacks as 5th data column in the meal grid (after Soup); cell background: `bg-purple-50` — done
- [x] `[💾 Save Week]` button — `bg-green-600 text-white`; toast on success
- [x] `[↺ Reset]` ghost button — confirmation dialog → restores default pattern
- [x] Remove Eye/EyeOff icons from column headers; remove `CategoryVisibilityDialog`; remove `categoryVisibility` state; remove `updateCategoryVisibility` API call — done: all old category-visibility code deleted from page.tsx
- [x] Add week visibility toggle row between week tabs and grid:
  - Reads `visible_to_parents` from API response (`data.visible_to_parents`)
  - Published badge: `bg-green-100 text-green-700 border-green-300` — "● Visible to Parents"
  - Unpublished badge: `bg-muted text-muted-foreground` — "○ Hidden from Parents"
  - Admin/Manager: badge is a clickable button → opens `WeekVisibilityDialog`
  - Supervisor/Cashier: badge is non-interactive (read-only)
- [x] Create `WeekVisibilityDialog`:
  - Props: `isVisible`, `monthLabel`, `weekNumber`, `isPending`, `onConfirm`, `onClose`
  - When publishing: title "Publish [Month] — Week [N] to Parents?", confirm = `bg-primary`, "Yes, Publish It"
  - When hiding: title "Hide [Month] — Week [N] from Parents?", confirm = `bg-destructive`, "Yes, Hide It"
  - Calls `PATCH /api/v1/references/meal-planner/week-visibility` via `useMutation`
  - Optimistic UI: badge updates immediately; reverts on error
- [x] Update `types/meal-planner.ts`: replace `CategoryVisibility` type with `visible_to_parents: boolean` on `MealPlannerResponse` — done: removed `CategoryVisibility`, `UpdateCategoryVisibilityPayload`, `UpdateCategoryVisibilityResponse`; added `visible_to_parents` + `UpdateWeekVisibilityResponse`
- [x] Update `lib/api/meal-planner.ts`: replace `updateCategoryVisibility()` with `updateWeekVisibility(month, week, visibleToParents)` — done: calls `PATCH /references/meal-planner/week-visibility`
- [x] Read-only view for Supervisor/Cashier (plain text cells, same color coding, no Save/Reset)
- [x] Read-only view includes Snacks column

### 2.6 Tests
- [x] `MealPlannerTest` — admin/manager can save and reset; supervisor/cashier cannot save; data is branch-scoped; upsert works correctly
- [x] Update `MealPlannerTest` — save and reset operations persist `snacks` field correctly — done
- [x] Rename `MealPlannerCategoryVisibilityTest` → `MealPlannerWeekVisibilityTest`:
  - Admin/manager can toggle week visibility → 200, `visible_to_parents` updated in DB
  - Supervisor/cashier cannot toggle → 403
  - Portal endpoint returns `visible_to_parents: false` + empty `days` when week is unpublished
  - Portal endpoint returns full meal data when week is published
  - Branch-scoped: one branch's visibility does not affect another

---

## Part 3: Inventory Management

### 3.1 Database
- [x] Migration: `inventory_items` table — `branch_id` (FK), `name`, `quantity` (decimal 8,2), `unit`, `restock_threshold` (decimal 8,2), timestamps
- [x] Migration: add `overstock_threshold` (decimal 8,2, nullable), `cost_per_unit` (decimal 8,2, nullable), `is_archived` (boolean, default false) to `inventory_items`
- [x] Migration: `inventory_logs` table — `branch_id`, `inventory_item_id` (FK), `adjusted_by` (FK → users), `type` (enum: restock/waste/manual/sale), `quantity_change` (decimal 8,2), `stock_after` (decimal 8,2), `reason`, `created_at`
- [x] Migration: add `item_name_snapshot` (string) and `order_id` (nullable FK → orders) to `inventory_logs`
- [x] Migration: `pos_menu_item_inventory` pivot — `pos_menu_item_id` (FK → pos_menu_items, cascade delete), `inventory_item_id` (FK → inventory_items, cascade delete), `quantity_used` (integer, default 1); UNIQUE KEY on `(pos_menu_item_id, inventory_item_id)`
- [x] Factory: `InventoryItemFactory`

### 3.2 Model
- [x] `InventoryItem` model with `HasBranch` trait, `LogsActivity` trait
- [x] `$logAttributes` allowlist: `name`, `quantity`, `unit`, `restock_threshold`
- [x] Add `overstock_threshold`, `cost_per_unit`, `is_archived` to `$fillable`
- [x] Update status accessor: `qty == 0` → `OUT`, `qty <= restock_threshold` → `LOW`, `overstock_threshold !== null && qty > overstock_threshold` → `OVER`, else `OK`
- [x] Add `scopeActive()` — excludes `is_archived = true` items (applied by default on all list queries)
- [x] Add `menuItems()` belongsToMany relationship via `pos_menu_item_inventory`
- [x] `InventoryLog` model
- [x] Add `item_name_snapshot`, `order_id` to `InventoryLog` `$fillable`
- [x] Add `order()` belongsTo relationship on `InventoryLog`

### 3.3 Seeder
- [x] Update `InventoryItemSeeder` — replace raw ingredient items with packaged canteen goods (5 items per branch via `updateOrCreate` keyed on `(branch_id, name)`):
  - Juice Tetra Pack — 48 pieces — restock threshold 20
  - Graham Crackers — 30 packs — restock threshold 15
  - Bread Roll — 24 pieces — restock threshold 10
  - Biscuit — 36 packs — restock threshold 20
  - Banana Cue — 20 pieces — restock threshold 10
- [x] Register `InventoryItemSeeder` in `DatabaseSeeder` after `WeeklyMealPlanSeeder`

### 3.4 Backend — Inventory APIs
- [x] `InventoryController::index()` — returns all active (non-archived) items for active branch with computed status
- [x] Update `InventoryController::index()` — include `overstock_threshold`, `cost_per_unit`, `is_archived`, `has_inventory_mapping` (bool derived from `pos_menu_item_inventory`) in response
- [x] Update `InventoryController::store()` — accept `overstock_threshold`, `cost_per_unit` fields; auto-create `Restock` log if `quantity > 0` on creation (set `item_name_snapshot`, `adjusted_by = auth()->id()`, `reason = "Initial stock"`)
- [x] Update `InventoryController::update()` — accept `overstock_threshold`, `cost_per_unit` fields
- [x] `InventoryController::adjust()` — validates type/quantity/reason; updates quantity; creates `inventory_logs` entry; logs `inventory.adjusted`
- [x] Update `InventoryController::adjust()` — reject `sale` type with 422 validation error ("Sale adjustments are recorded automatically by checkout"); snapshot `item_name_snapshot = $item->name` on every log
- [x] `InventoryController::archive(InventoryItem $item)` — sets `is_archived = true`; logs `inventory.archived`
- [x] `InventoryController::unarchive(InventoryItem $item)` — sets `is_archived = false`; logs `inventory.unarchived`
- [x] `InventoryController::history()` — cross-item log view; filters: `from` (date), `to` (date), `type` (enum), `item_id`; ordered newest first; paginated 25/page; branch-scoped via `inventory_items.branch_id`
- [x] `InventoryController::logs()` — returns log entries for a specific item (fix URL bug: `{id}` → `${id}` in frontend API service)
- [x] `InventoryIngredientController::index(PosMenuItem $menuItem)` — returns all inventory items mapped to a menu item with `quantity_used`
- [x] `InventoryIngredientController::attach(PosMenuItem $menuItem)` — validates `inventory_item_id` + `quantity_used`; creates pivot row (upsert on duplicate)
- [x] `InventoryIngredientController::detach(PosMenuItem $menuItem, InventoryItem $item)` — removes pivot row
- [x] Update `PosMenuItemController::index()` — include `has_inventory_mapping` (bool) and `inventory_status` (worst status among linked items: OUT > LOW > OVER > OK) per item in response
- [x] Routes under `auth:sanctum` + `ability:staff` + `role:admin|manager|supervisor`:
  - `GET /api/v1/pos/inventory`
  - `POST /api/v1/pos/inventory/{item}/adjust`
  - `GET|POST /api/v1/references/inventory`
  - `PUT|DELETE /api/v1/references/inventory/{item}`
  - `GET /api/v1/references/inventory/{item}/logs`
- [x] New routes:
  - `GET /api/v1/references/inventory/history` — role:admin,manager,supervisor
  - `PATCH /api/v1/references/inventory/{item}/archive` — role:admin,manager
  - `PATCH /api/v1/references/inventory/{item}/unarchive` — role:admin,manager
  - `GET /api/v1/references/menu-items/{item}/ingredients` — role:admin,manager,supervisor
  - `POST /api/v1/references/menu-items/{item}/ingredients` — role:admin,manager
  - `DELETE /api/v1/references/menu-items/{item}/ingredients/{inventoryItem}` — role:admin,manager

### 3.5 Frontend — Inventory Tab (POS App)
- [x] Inventory tab within POS page — table: Item Name, Qty, Unit, Threshold, Status, Action
- [x] Update status badges — add OVER: `bg-orange-100 text-orange-700 border-orange-300` — done
- [x] Stock Adjustment modal per row:
  - [x] Adjustment type radio: Add Stock / Deduct Stock
  - [x] Quantity input, Reason select (presets + Other)
  - [x] Live "New Total" preview calculation
  - [x] `[Save Adjustment]` button via `useMutation`
- [x] Remove `Sale` from log type options in adjustment modal — show only: Restock, Waste, Manual — done

### 3.6 Frontend — References > Inventory (POS App)
- [x] Full inventory page at `app/(kitchen)/references/inventory/page.tsx`
- [x] Update table columns — add Overstock Threshold, Cost/Unit columns; add Archive action — done
- [x] Update Add Item form — add `overstock_threshold`, `cost_per_unit` fields (both optional) — done
- [x] Update Edit modal — add `overstock_threshold`, `cost_per_unit` fields — done
- [x] Update status badges — add orange OVER badge — done
- [x] Archive/Unarchive action: replace Delete with Archive when item has log history; show "Archived Items" collapsed section; unarchive button per archived item — done
- [x] Fix `inventoryApi.logs()` URL bug — confirmed correct (`${id}` template literal)
- [x] Expandable per-item log history dialog (modal)
- [x] Add **History tab** — cross-item log view: — done
  - Date range pickers (`from`, `to`)
  - Type filter dropdown (All / Restock / Waste / Manual / Sale)
  - Item filter dropdown (All / specific item)
  - Paginated table: Date/Time, Item Name, Type, Change, Stock After, Reason, Adjusted By
  - Row color coding: green=Restock, red=Sale/Waste/Deduct, gray=Manual
- [x] Update `types/inventory.ts`: — done
  - Add `overstock_threshold`, `cost_per_unit`, `is_archived`, `has_inventory_mapping`, `inventory_status` to `InventoryItem`
  - Add `item_name_snapshot`, `order_id` to `InventoryLog`
  - Add `"OVER"` to `InventoryStatus` union type
  - Remove `"sale"` from manual `InventoryLogType` (keep in type for read purposes, remove from form options)
- [x] Update `inventoryApi` in `lib/api/inventory.ts`: — done
  - Fix `logs()` URL: confirmed correct
  - Add `history(filters)` → `GET /references/inventory/history`
  - Add `archive(id)` → `PATCH /references/inventory/{id}/archive`
  - Add `unarchive(id)` → `PATCH /references/inventory/{id}/unarchive`
- [x] **Layout change**: Convert References > Inventory to a 2-tab layout:
  - Tab 1 "Inventory": Add form at the **top**, active items table below, archived items collapsed at bottom
  - Tab 2 "History": cross-item log view (currently rendered below the table as a section — move into dedicated tab)

### 3.7 Frontend — Linked Stock (Menu Mgmt tab — was "Ingredient Mapping")
- [x] Add Linked Stock panel to menu item cards in Menu Mgmt tab — done: `IngredientsPanel` in `menu-mgmt-tab.tsx`
- [x] Per menu item: table of linked inventory items with `quantity_used` column — done
- [x] Attach form: inventory item selector (dropdown of active items) + quantity input + Add button — done
- [x] Detach: Remove button per row with inline confirmation — done
- [x] Warning badge on menu item card when `has_inventory_mapping = false` — done: orange badge
- [x] Ingredient API methods in `lib/api/inventory.ts` — done
- [x] **Terminology rename** — update all user-facing labels from "Ingredients" → "Linked Stock":
  - Card button label: "Link Stock" (was "Ingredients")
  - Panel title: "Linked Stock: {name}" (was "Ingredients: ...")
  - Warning badge on card: "Not linked" (was "Not mapped")
  - Column header: "Qty Deducted per Sale" (was "Qty Used per Sale")
  - Add action button: "Add Link" (was "Add")
  - Warning notice text: "All menu items must have at least one stock item linked before they can be sold at checkout."

### 3.8 Frontend — POS Menu Grid (Inventory Status)
- [x] Update POS menu item grid — `GET /api/v1/pos/menu-items` response now includes `inventory_status` and `has_inventory_mapping` — done
- [x] OUT items (`inventory_status = "OUT"`): greyed out (`opacity-40 cursor-not-allowed`), unselectable (click disabled) — done
- [x] LOW items (`inventory_status = "LOW"`): show warning badge on card (`bg-yellow-50 border-yellow-300 text-amber-700 text-[10px]` — "Low Stock") — done
- [x] Unmapped items (`has_inventory_mapping = false`): show orange warning badge — done
- [x] **Terminology**: rename badge text from "Not Mapped" → "Not linked" to match the Linked Stock terminology

### 3.9 Tests
- [x] `InventoryTest` — admin/manager/supervisor can view and adjust; cashier returns 403; adjustment creates log entry; branch-scoped
- [x] Update `InventoryTest`:
  - `sale` type rejected from manual adjust endpoint (422)
  - Creating item with `quantity > 0` auto-creates a `Restock` log with `reason = "Initial stock"`
  - Archive/unarchive actions work; archived items excluded from active list
  - History endpoint returns paginated cross-item logs; date range and type filters narrow results
  - `item_name_snapshot` populated on every log entry
- [x] `InventoryIngredientTest`:
  - Admin/manager can attach/detach ingredients per menu item
  - Supervisor can list but cannot attach/detach (403)
  - Duplicate attach is idempotent (upsert)
  - `has_inventory_mapping` reflects correct state on menu item list response
