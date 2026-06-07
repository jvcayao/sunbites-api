# Spec 04 — Menu & Product Management

## Overview

There are **two distinct and separate menu systems** in this application. They serve different purposes and must not be confused:

1. **POS Menu Items** — the sellable items at the canteen counter (Subscription Meal Tray, snacks, drinks, extras). Managed from the POS tab, used by cashiers.
2. **Weekly Meal Planner** — the daily meal schedule (what food is cooked and served each school day). Managed by admin/manager in the POS app, viewed read-only by parents in the portal.

---

## Part 1 — POS Menu Items

### Purpose
These are the items that appear on the POS screen for cashiers to add to a customer's order. Simple, flat list — no hierarchy, no complex product attributes.

### Data Model
```
pos_menu_items
  id
  branch_id              (FK → branches)
  name                   (string)                        — e.g. "Subscription Meal Tray"
  price                  (decimal 8,2)
  category               (enum: meal, snack, drink, extra)
  is_available           (boolean, default true)
  is_subscription_item   (boolean, nullable)             — null = not configured; true = subscription-eligible; false = not eligible
  sort_order             (int, default 0)
  created_at, updated_at
```

**No** SKU, barcode, VAT, description, image, stock tracking, min/max cart, or discount fields.

### Subscription Eligibility Flag (`is_subscription_item`)

Each menu item must be explicitly configured for its subscription eligibility before it is fully usable on the POS:

- `null` (not configured) — item is **greyed out and not selectable** for anyone on the POS, similar to unmapped inventory items. This is the default for any newly created item.
- `true` (subscription-eligible) — item is covered by the subscription plan. Subscription students can redeem it via subscription payment. A blue "Subscription" badge is displayed on the card. Anyone can still purchase it with cash/GCash/wallet.
- `false` (not eligible) — item is a regular item, available for all payment methods. Not covered by subscription. No badge shown.

Existing items at migration time are set to `false` (not eligible) to preserve current POS behaviour — admins can then mark specific items as `true`.

### Default Items (seeded per branch)
| Name | Price | Category |
|---|---|---|
| Subscription Meal Tray | ₱135.00 | meal |
| Snack A (Bread/Pastry) | ₱15.00 | snack |
| Snack B (Chips/Crackers) | ₱20.00 | snack |
| Snack C (Juice/Water) | ₱15.00 | drink |
| Snack D (Fruit Cup) | ₱25.00 | snack |
| Additional Rice | ₱10.00 | extra |
| Special Snack | ₱30.00 | snack |

### Where It's Managed
Located in the **POS page → Menu Mgmt tab** (not in References sidebar).
Roles: Admin, Manager only.

### Menu Mgmt UI (POS sub-tab)
- Grid of item cards: name (bold), price (large), category label (small, muted), availability toggle, delete button
- "Add New Item" inline form: name input + price input + category dropdown + **Subscription Eligible select** + Add button
- **Subscription Eligible select options**: "Not configured" (null) | "Yes — covered by subscription" (true) | "No — regular only" (false)
- Toggle availability: instant API call, no page reload
- Delete: confirmation before removing
- No edit modal — items are simple enough to delete and re-add if name/price changes

### POS Display
- Items grouped by category tab (meal | snack | drink | extra)
- Only `is_available = true` items shown as clickable
- Unavailable items hidden from POS grid by default
- Items with `is_subscription_item = null` are greyed out and not selectable for anyone (mandatory setup required)
- Items with `is_subscription_item = true` show a blue "Subscription" badge on the card

---

## Part 2 — Weekly Meal Planner

### Purpose
The weekly meal planner is the canteen's **food calendar** — what is being cooked and served each school day. It is structured by school month, week number (1–4), and day of week (Mon–Fri). Each day has 5 meal categories: Ulam, Vegetables, Fruit, Soup, Snacks.

This is **not** related to the POS. It is informational: parents see what their child is eating, admin plans the kitchen work.

### School Months
| Key | Month | School Days | Subscription Amount |
|---|---|---|---|
| june | June | 22 | ₱2,970 |
| july | July | 22 | ₱2,970 |
| august | August | 18 | ₱2,430 |
| september | September | 22 | ₱2,970 |
| october | October | 22 | ₱2,970 |
| november | November | 16 | ₱2,160 |
| december | December | 15 | ₱2,025 |
| january | January | 20 | ₱2,700 |
| february | February | 18 | ₱2,430 |
| march | March | 7 | ₱945 |

The subscription amount per month = school days × ₱135 daily rate.

### Meal Categories (Fixed, Not Configurable)
- **Ulam** — main protein dish
- **Vegetables** — vegetable side dish
- **Fruit** — fresh fruit
- **Soup** — soup or broth
- **Snacks** — afternoon or recess snack item

### Week Visibility (Publish/Unpublish)
Each week within a month has a **parent portal visibility toggle** (per branch, per school_month, per week_number). This controls whether parents can see that specific week's meal plan in the portal.

- When toggled **ON (Published)**: parents can view that week's full meal plan in the portal
- When toggled **OFF (Unpublished)**: parents see "Meal plan not yet available" for that week
- Toggle is only editable by Admin and Manager; requires a confirmation dialog before applying
- Default: **visible (published)** — all weeks are visible unless explicitly hidden
- Supervisor and Cashier can see the current publish state but cannot change it

### Data Model
```
weekly_meal_plans
  id
  branch_id         (FK → branches)
  school_month      (enum: june, july, august, september, october,
                           november, december, january, february, march)
  week_number       (tinyint: 1, 2, 3, 4)
  day_of_week       (enum: monday, tuesday, wednesday, thursday, friday)
  ulam              (string, nullable)
  vegetables        (string, nullable)
  fruit             (string, nullable)
  soup              (string, nullable)
  snacks            (string, nullable)
  created_at, updated_at

  UNIQUE KEY: (branch_id, school_month, week_number, day_of_week)

meal_planner_week_visibility
  id
  branch_id         (FK → branches)
  school_month      (enum: june, july, august, september, october,
                           november, december, january, february, march)
  week_number       (tinyint: 1, 2, 3, 4)
  visible_to_parents (boolean, default true)
  updated_at

  UNIQUE KEY: (branch_id, school_month, week_number)
```

Seeded with all 40 week combinations (10 months × 4 weeks) set to `visible_to_parents = true` per branch on initial setup.

Upsert on save: `updateOrCreate` keyed on the unique constraint.

### Default Meals (seeded per branch)
| Day | Ulam | Vegetables | Fruit | Soup | Snacks |
|---|---|---|---|---|---|
| Monday | Chicken Adobo | Chopsuey | Mango | Nilaga Soup | Graham Crackers |
| Tuesday | Pork Sinigang | Pinakbet | Banana | Miso Soup | Bread Roll |
| Wednesday | Fish Tinola | Laing | Apple | Sinigang Broth | Biscuit |
| Thursday | Beef Kaldereta | Ginisang Gulay | Orange | Chicken Broth | Banana Cue |
| Friday | Chicken Inasal | Ampalaya | Watermelon | Corn Soup | Puto |

Same default week applied to all 4 weeks × all 10 months on initial seed.

### Where It's Managed
Located in **References > Meal Planner** in the POS app.
Roles: Admin and Manager only (edit); Supervisor and Cashier (read-only).

### Meal Planner UI
- **Month tabs**: pill buttons (Jun–Mar), active month highlighted
- **Week tabs**: Week 1 / Week 2 / Week 3 / Week 4
- **Week visibility toggle**: shown between the week tabs and the meal grid; controls whether the currently selected week is visible to parents in the portal
  - Admin/Manager: interactive toggle with confirmation dialog before applying
  - Supervisor/Cashier: read-only badge showing current state (Published / Unpublished)
- **Meal grid table**: rows = Mon–Fri, columns = Ulam / Vegetables / Fruit / Soup / Snacks
  - Each cell is an editable text input (Admin/Manager) or plain text (others)
  - Column headers use the primary color background — no per-column eye icons
- **Save Week** button: single API call upserts all 5 rows (Mon–Fri)
- **Reset to Defaults** button: restores default pattern with confirmation prompt
- On save: toast success — *"Week {N} of {Month} menu saved."*

### Parent Portal View (Read-Only)
Parents see the same month/week structure but all inputs are replaced with plain text cells. Full detail in Spec 07.

---

## Inventory Management

Stock tracking for **ready-to-consume packaged canteen goods** (juice boxes, snack packs, bread rolls, bottled water, biscuits, etc.). This is **not** raw ingredient tracking — all items are discrete, countable units. Each inventory item maps to one or more POS menu items via an ingredient mapping pivot, enabling automatic stock deduction at checkout.

### Data Model
```
inventory_items
  id
  branch_id            (FK → branches)
  name                 (string)              — e.g. "Juice Tetra Pack", "Graham Crackers"
  quantity             (decimal 8,2)         — current stock in units
  unit                 (string)              — display label: "piece", "bottle", "pack", "box"
  restock_threshold    (decimal 8,2)         — triggers LOW status
  overstock_threshold  (decimal 8,2, nullable) — triggers OVER status when qty exceeds this
  cost_per_unit        (decimal 8,2, nullable) — optional cost tracking per unit
  is_archived          (boolean, default false) — soft-hide discontinued items
  created_at, updated_at

inventory_logs
  id
  branch_id
  inventory_item_id    (FK → inventory_items)
  order_id             (nullable FK → orders) — set when log is auto-created by checkout
  adjusted_by          (FK → users)           — cashier ID for Sale logs; staff ID for manual logs
  type                 (enum: restock, waste, manual, sale)
  quantity_change      (decimal 8,2)          — positive for add, negative for deduct
  stock_after          (decimal 8,2)
  item_name_snapshot   (string)               — item name at time of log (preserved if item renamed)
  reason               (string)
  created_at

pos_menu_item_inventory  — ingredient mapping pivot
  id
  pos_menu_item_id     (FK → pos_menu_items)
  inventory_item_id    (FK → inventory_items)
  quantity_used        (integer, default 1)   — units deducted per 1 sale of the menu item
```

### Log Type Rules
- `restock` — manual stock addition by kitchen staff
- `waste`   — manual stock deduction for spoilage/disposal
- `manual`  — general manual correction by staff
- `sale`    — **system-only**, auto-created by `CheckoutController` on completed order; never accepted from manual adjustment endpoint

### Status Logic
- `quantity = 0` → **OUT** (red) — checkout blocks sales of linked menu items
- `quantity <= restock_threshold` → **LOW** (yellow) — checkout warns but does not block
- `quantity > overstock_threshold` (when set) → **OVER** (orange) — informational only
- otherwise → **OK** (green)

### Inventory UI (POS page → Inventory tab)
- Table: Item name, Qty, Unit, Restock threshold, Status badge, Adjust button per row
- Adjust button: opens modal — Add Stock or Deduct Stock; type: Restock / Waste / Manual only (no Sale)
- `Sale` type hidden from manual adjustment — it is auto-generated by checkout only

### References > Inventory (Full Management)
Roles: Admin, Manager, Supervisor
- Full CRUD: add items, edit name/unit/threshold/overstock threshold/cost, archive/unarchive items
- Delete guard: blocked if item has any log history (archive instead)
- Log history per item: expandable per-item view
- **History page/tab**: cross-item log view — all adjustments across all items; filterable by date range (`from`/`to`), log type, item; paginated; color-coded by type (green = add, red = deduct/sale/waste, gray = manual)
- Auto-log on creation: when a new item is created with `quantity > 0`, a `Restock` log is auto-created with `reason = "Initial stock"` and `adjusted_by = creating user`

### Ingredient Mapping (References > Menu Items)
Each POS menu item optionally maps to one or more inventory items via `pos_menu_item_inventory`. Kitchen staff configure the mapping per menu item — specifying which inventory items are consumed per sale and in what quantity.

- Checkout **blocks** the sale if any item in the cart has no inventory mapping configured
- Every POS menu item (including Subscription Meal Tray) must be mapped before it can be sold
- `quantity_used` is a whole number (units consumed per sale of that menu item, e.g. 1)

---

## Requirements

**POS Menu Items**
- [ ] `pos_menu_items` table with `branch_id`, `name`, `price`, `category`, `is_available`, `is_subscription_item` (nullable boolean), `sort_order`
- [ ] `HasBranch` trait applied
- [ ] `LogsActivity` trait applied with `$logAttributes` allowlist: `name`, `price`, `category`, `is_available`, `is_subscription_item`, `sort_order`
- [ ] Default items seeded per branch (7 items as listed) with `is_subscription_item = false`
- [ ] Migration for new items: `is_subscription_item` nullable boolean, default null; existing rows updated to `false`
- [ ] API endpoints: list, create, update, toggle availability, delete — under `role:admin,manager`
- [ ] `GET /api/v1/pos/menu-items` — returns all items for active branch including `is_subscription_item`
- [ ] `POST /api/v1/pos/menu-items` — create (accepts `is_subscription_item: nullable boolean`)
- [ ] `PUT /api/v1/pos/menu-items/{item}` — update (accepts `is_subscription_item: nullable boolean`)
- [ ] `POST /api/v1/pos/menu-items/{item}/toggle` — flip is_available
- [ ] `DELETE /api/v1/pos/menu-items/{item}` — delete
- [ ] Menu Mgmt sub-tab on POS page in POS app (Admin/Manager only)
- [ ] **Layout: "Add New Item" form shown at the top; item cards grid displayed below** — staff can add without scrolling past the list
- [ ] Item cards with availability toggle (instant), "Link Stock" button (opens inline Linked Stock panel), and delete (with confirmation)
- [ ] Inline "Add New Item" form: name + price + category + **Subscription Eligible select** (Not configured / Yes / No) + Add button
- [ ] Items with `is_subscription_item = null` greyed out and not selectable on POS (for all customers)
- [ ] Items with `is_subscription_item = true` show blue "Subscription" badge on POS item card
- [ ] `PosMenuItemFactory`: add `subscriptionEligible()` and `notSubscriptionEligible()` states; default definition sets `is_subscription_item = false`

**Weekly Meal Planner**
- [ ] `weekly_meal_plans` table with unique constraint on `(branch_id, school_month, week_number, day_of_week)`
- [ ] `snacks` column (nullable string) added to `weekly_meal_plans`
- [ ] `HasBranch` trait applied
- [ ] Default week seeded for all months × 4 weeks per branch (includes default Snacks values)
- [ ] `meal_planner_week_visibility` table: `(branch_id, school_month, week_number, visible_to_parents)`; unique key on `(branch_id, school_month, week_number)`
- [ ] Seeder: all 40 week combinations seeded as `visible_to_parents = true` per branch
- [ ] `GET /api/v1/references/meal-planner` — returns week data for given month + week + active branch (includes `snacks` field + `visible_to_parents` boolean for current week)
- [ ] `PATCH /api/v1/references/meal-planner` — upserts all 5 rows including `snacks` (Admin, Manager only)
- [ ] `POST /api/v1/references/meal-planner/reset` — restores default pattern including `snacks` (Admin, Manager only)
- [ ] `PATCH /api/v1/references/meal-planner/week-visibility` — sets `visible_to_parents` for the given month + week (Admin, Manager only); requires confirmation on frontend
- [ ] Meal Planner page in POS app: month tabs + week tabs + week visibility toggle + editable grid (Admin/Manager) or read-only (Supervisor/Cashier)
- [ ] Grid includes Snacks as 5th column with `bg-purple-50` cell background; no per-column eye icons
- [ ] Week visibility toggle shown between week tabs and grid; Admin/Manager can toggle; Supervisor/Cashier see read-only badge
- [ ] Toggling week visibility prompts confirmation: "Publish [Month] — Week [N] to Parents?" or "Hide [Month] — Week [N] from Parents?"
- [ ] Toast on save: success message referencing which month/week was saved
- [ ] `GET /api/v1/portal/meal-planner` — read-only endpoint; returns week data only if `visible_to_parents = true` for that week; returns a "not published" indicator otherwise

**Inventory**
- [ ] `inventory_items` table — fields: `branch_id`, `name`, `quantity`, `unit`, `restock_threshold`, `overstock_threshold` (nullable), `cost_per_unit` (nullable), `is_archived` (bool, default false), timestamps
- [ ] `inventory_logs` table — fields: `branch_id`, `inventory_item_id` (FK), `order_id` (nullable FK → orders), `adjusted_by` (FK → users), `type` (enum: restock/waste/manual/sale), `quantity_change`, `stock_after`, `item_name_snapshot` (string), `reason`, `created_at`
- [ ] `pos_menu_item_inventory` pivot table — `pos_menu_item_id` (FK), `inventory_item_id` (FK), `quantity_used` (integer, default 1)
- [ ] `HasBranch` trait applied to `inventory_items`
- [ ] `LogsActivity` trait applied with `$logAttributes` allowlist
- [ ] Status accessor: OUT (qty=0), LOW (qty ≤ restock_threshold), OVER (qty > overstock_threshold when set), OK (otherwise)
- [ ] `is_archived` global scope — archived items excluded from active list; separate archive/unarchive actions
- [ ] Default inventory items seeded per branch (updated to packaged goods: Juice Tetra Pack, Graham Crackers, Bread Roll, Biscuit, Banana Cue)
- [ ] API endpoints for inventory management under `role:admin,manager,supervisor`
- [ ] `InventoryController::store()` — auto-creates `Restock` log if `quantity > 0` on creation (`reason = "Initial stock"`, `adjusted_by = auth user`)
- [ ] `InventoryController::adjust()` — accepts types: restock/waste/manual only; `sale` type rejected with 422; snapshots `item_name_snapshot` on every log
- [ ] `InventoryController::archive()` / `unarchive()` — soft-hide items; archived items excluded from active list
- [ ] `InventoryController::history()` — cross-item log view; filters: `from`, `to`, `type`, `item_id`; paginated 25/page; branch-scoped
- [ ] `InventoryIngredientController` — CRUD for `pos_menu_item_inventory`: list ingredients per menu item, attach (with quantity_used), detach
- [ ] `GET /api/v1/pos/menu-items` response includes `inventory_status` (derived: OUT/LOW/OVER/OK) and `has_inventory_mapping` (boolean) per item
- [ ] Inventory tab on POS page: table with status badges (OK/LOW/OUT/OVER); Adjust modal shows Restock/Waste/Manual types only (no Sale)
- [ ] References > Inventory uses a **2-tab layout**: Tab 1 "Inventory" (Add form at top, active items list below, archived items collapsed at bottom); Tab 2 "History" (cross-item log view with filters)
- [ ] Full inventory CRUD in References > Inventory (Inventory tab) — including overstock_threshold and cost_per_unit fields; archive/unarchive actions; Add form above the list
- [ ] Cross-item Inventory History in References > Inventory (History tab): all logs, date range + type + item filters, paginated, color-coded
- [ ] **Linked Stock** panel on Menu Mgmt tab (not "Ingredients" / "Ingredient Mapping"): per menu item, manage which inventory items are deducted per sale; button on card labelled "Link Stock"; panel title "Linked Stock: {name}"; warning "Not linked" badge when unmapped; action buttons "Add Link" / "Remove"
- [ ] POS menu grid: OUT items greyed out and unselectable; LOW items show warning badge; unmapped items show "Not linked" badge
- [ ] `inventory_logs` entry created on every quantity change (manual or auto)
- [ ] Log `menu.item_created` when a POS menu item is added
- [ ] Log `menu.item_toggled` when availability is toggled
- [ ] Log `menu.item_deleted` when a POS menu item is deleted
- [ ] Log `meal_planner.saved` when a meal planner week is saved (properties: month, week_number, branch)
- [ ] Log `inventory.adjusted` when stock quantity changes (properties: item name, old_qty, change, new_qty, reason, type)
- [ ] Log `inventory.sale_deducted` when checkout auto-deducts stock (properties: item name, qty_deducted, stock_after, order_id)
