# Design 04 — Menu & Products

---

## Part 1: POS Menu Management Tab

**Route:** `pos.sunbites.com.ph/pos` → "Menu Mgmt" tab
**Role:** Admin, Manager only
**Context:** Sub-tab within the POS page

### Screen: Menu Mgmt Tab

```
┌───────────────────────────────────────────────────────────────┐
│ [🛒 POS]  [📋 Transaction History]  [🍽️ Menu Mgmt ●]  [📦 Inventory] │
├───────────────────────────────────────────────────────────────┤
│                                                               │
│  🍽️ Menu Items                                               │
│                                                               │
│  ┌──── Add New Item ─────────────────────────────────┐      │
│  │  Item Name *         Price (₱) *                  │      │
│  │  [________________]  [________]                   │      │
│  │  Category *                                        │      │
│  │  [meal ▾         ]                                │      │
│  │                              [+ Add Item]         │      │
│  └────────────────────────────────────────────────────┘      │
│                                                               │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │              │  │              │  │              │       │
│  │ Subscription │  │ Snack A      │  │ Snack B      │       │
│  │ Meal Tray   │  │ (Bread/Pastry│  │ (Chips/      │       │
│  │   ₱135.00   │  │   ₱15.00    │  │  Crackers)   │       │
│  │  [meal]     │  │  [snack]    │  │   ₱20.00     │       │
│  │             │  │             │  │  [snack]     │       │
│  │ [ON] [✕]   │  │ [ON] [✕]   │  │ [OFF] [✕]   │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
└───────────────────────────────────────────────────────────────┘
```

**Layout order:** Add New Item form is always shown at the **top**, followed by the item grid below. This puts the action first so staff can immediately add without scrolling past a long list.

**Item Card Component:**
```
┌─────────────────────────┐
│  Subscription Meal Tray │  ← font-bold text-sm
│     ₱135.00             │  ← text-2xl font-extrabold text-primary
│     [meal]              │  ← text-[10px] uppercase muted badge
│  ⚠ Not linked           │  ← orange badge when no stock linked
│                         │
│  [ON ●]  [Link Stock]  [✕]  ← toggle + link stock + delete
└─────────────────────────┘
```

- Card: white, `border-border`, 12px radius, `p-3`
- Item name: `text-sm font-bold`
- Price: `text-xl font-extrabold text-primary`
- Category badge: `text-[10px] font-bold uppercase px-2 py-0.5 rounded-full`
  - meal → `bg-primary/10 text-primary`
  - snack → `bg-amber-50 text-amber-700`
  - drink → `bg-blue-50 text-blue-700`
  - extra → `bg-muted text-muted-foreground`
- Toggle: shadcn `Switch` — ON = primary color, OFF = gray; instant API call
- **"Link Stock"** button: small secondary button — opens the Linked Stock panel (inline, below the card) to configure which inventory items are deducted per sale of this menu item
- Delete `[✕]`: small red button, opens confirmation dialog before delete
- Unavailable items: card opacity 50%
- "Not linked" warning: `bg-orange-50 text-orange-700 text-[10px] border border-orange-200 rounded px-2 py-0.5` — shown when `has_inventory_mapping = false`

**Add New Item Form (at top, above item grid):**
- Contained in a card with dashed border `border-dashed`
- Name input: `text-sm`
- Price: number input with ₱ prefix label
- Category: shadcn `Select` with options: Meal / Snack / Drink / Extra
- `[+ Add Item]`: primary button
- Validation: inline errors under each field

---

## Part 2: Weekly Meal Planner

**Route:** `pos.sunbites.com.ph/references/meal-planner`
**Role:** Admin, Manager (edit); Supervisor, Cashier (read-only)
**Layout:** `KitchenLayout`

### Screen: Meal Planner Editor (Admin/Manager)

```
┌──────────────────────────────────────────────────────────────────────┐
│ References > Meal Planner                  [💾 Save Week] [↺ Reset]  │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌── Month ──────────────────────────────────────────────────────┐  │
│  │ [Jun●] [Jul] [Aug] [Sep] [Oct] [Nov] [Dec] [Jan] [Feb] [Mar] │  │
│  └───────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  Week:  [Week 1●]  [Week 2]  [Week 3]  [Week 4]                     │
│                                                                      │
│  ── June — Week 1 ──────────────── [● Visible to Parents]  (Admin)  │
│                                                                      │
│  ┌────────┬──────────────┬────────────┬────────┬───────┬──────────┐ │
│  │ Day    │ Ulam         │ Vegetables │ Fruit  │ Soup  │ Snacks   │ │
│  ├────────┼──────────────┼────────────┼────────┼───────┼──────────┤ │
│  │ Monday │[Chicken Adobo│[Chopsuey ] │[Mango ]│[Nilaga│[Crackers]│ │
│  │ Tuesday│[Pork Sinigang│[Pinakbet ] │[Banana]│[Miso S│[Bread   ]│ │
│  │ Wednes │[Fish Tinola ]│[Laing    ] │[Apple ]│[Sinig │[Biscuit ]│ │
│  │ Thursd │[Beef Kaldera │[Ginisang G]│[Orange]│[Chicke│[Banana C]│ │
│  │ Friday │[Chicken Inas │[Ampalaya ] │[Waterm]│[Corn S│[Puto    ]│ │
│  └────────┴──────────────┴────────────┴────────┴───────┴──────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

**Month Tabs:**
- Pill buttons: `rounded-full border-2`
- Active: `bg-primary text-primary-foreground border-primary`
- Inactive: `bg-background text-foreground border-border hover:border-primary/50`
- Font: `text-xs font-semibold`

**Week Tabs:**
- Active week: `bg-primary/10 text-primary border-primary font-bold`

**Week Visibility Toggle (between week tabs and grid):**
- Shown as a row: `── [Month] — Week [N] ──────── [● Visible to Parents]`
- **Admin/Manager**: interactive — clicking opens the confirmation dialog
  - Published state: green pill badge `bg-green-100 text-green-700 border-green-300` — "● Visible to Parents"
  - Unpublished state: muted pill badge `bg-muted text-muted-foreground` — "○ Hidden from Parents"
- **Supervisor/Cashier**: same badge but read-only, no click interaction
- Badge updates optimistically on confirm; reverts on API error

**Week Visibility Confirmation Dialog:**
```
┌──────────────────────────────────────────────────┐
│  Hide June — Week 1 from Parents?                │
│                                                  │
│  Parents will no longer see this week's meal     │
│  plan in the portal.                             │
│                                                  │
│  [Cancel]              [Yes, Hide It]            │
└──────────────────────────────────────────────────┘
```
- When publishing: "Publish June — Week 1 to Parents?" / "Parents will be able to see this week's meal plan." / confirm button = `bg-primary text-white` / "Yes, Publish It"
- When hiding: "Hide June — Week 1 from Parents?" / confirm button = `bg-destructive text-white` / "Yes, Hide It"

**Meal Grid Table:**
- Column headers (Day, Ulam, Vegetables, Fruit, Soup, Snacks): `bg-primary text-primary-foreground text-sm font-semibold px-3 py-2`
- No per-column eye icons — column headers are plain labels only
- "Day" column: `bg-muted text-primary font-bold text-sm` — non-editable
- Cell backgrounds:
  - Ulam cells: `bg-orange-50`
  - Vegetables cells: `bg-green-50`
  - Fruit cells: `bg-blue-50`
  - Soup cells: `bg-sky-50`
  - Snacks cells: `bg-purple-50`
- Inputs: `text-sm p-1.5 border border-input rounded-lg w-full`
- Table min-width: 800px (horizontal scroll on mobile)

**Save Week Button:** `bg-green-600 text-white` — saves all 5 rows via single PATCH request

**Reset Button:** ghost button → confirmation dialog → restores default pattern

---

## Part 2b: Meal Planner Read-Only (Parent Portal View)

Same grid table but all `<input>` replaced with plain `<td>` text. Same color-coded cell backgrounds. All 5 columns always shown (Day, Ulam, Vegetables, Fruit, Soup, Snacks).

**Published week:**
```
│  Monday │ Chicken Adobo  │ Chopsuey     │ Mango  │ Nilaga │ Crackers │
│  Tuesday│ Pork Sinigang  │ Pinakbet     │ Banana │ Miso S │ Bread    │
```

**Unpublished week** (visible_to_parents = false for that week):
```
  ┌────────────────────────────────────────────────┐
  │  Meal plan for this week is not yet available. │
  └────────────────────────────────────────────────┘
```
Shown in place of the table when the selected week is not published.

No Save, Reset, or visibility toggle controls visible in the portal.

---

## Part 3: Inventory Management

### Screen: Inventory Tab (POS page → Inventory tab)

```
┌────────────────────────────────────────────────────────────────────┐
│  📦 Inventory — Antipolo Branch                                    │
├────────────────────────────────────────────────────────────────────┤
│  Item Name          Qty   Unit    Threshold  Status    Action      │
├────────────────────────────────────────────────────────────────────┤
│  Juice Tetra Pack   48    piece   20         [OK ✓]    [Adjust]    │
│  Graham Crackers    12    pack    15         [LOW ⚠]   [Adjust]    │
│  Bread Roll          0    piece   10         [OUT ✕]   [Adjust]    │
│  Biscuit            60    pack    20         [OVER ▲]  [Adjust]    │
│  Banana Cue          5    piece   10         [LOW ⚠]   [Adjust]    │
└────────────────────────────────────────────────────────────────────┘
```

**Status Badges:**
```
[OK ✓]   → bg-green-100 text-green-700 border-green-300
[LOW ⚠]  → bg-yellow-100 text-amber-700 border-yellow-300
[OUT ✕]  → bg-red-100 text-destructive border-red-300
[OVER ▲] → bg-orange-100 text-orange-700 border-orange-300
```

**Stock Adjustment Modal (Inventory Tab — POS page):**
```
┌─────────────────────────────────────────────┐
│  Adjust Stock: Juice Tetra Pack        [✕]  │
├─────────────────────────────────────────────┤
│  Current Stock: 48 pieces                    │
│                                              │
│  Adjustment Type                            │
│  (●) Add Stock   ( ) Deduct Stock          │
│                                              │
│  Log Type *                                 │
│  [Restock ▾     ]  ← Restock / Waste /     │
│                       Manual only            │
│                       (Sale is system-only)  │
│                                              │
│  Quantity *                                 │
│  [___24___]                                  │
│                                              │
│  Reason *                                    │
│  [___________________________]              │
│                                              │
│  New Total: 72 pieces  ← live calculation   │
│                                              │
│  [Cancel]              [Save Adjustment]    │
└─────────────────────────────────────────────┘
```

### Screen: Full Inventory Management (References > Inventory)

**Route:** `pos.sunbites.com.ph/references/inventory`
**Role:** Admin, Manager, Supervisor
**Layout:** Two tabs — **Inventory** and **History**

```
┌───────────────────────────────────────────────────────────────────┐
│  References > Inventory                                           │
│                                                                   │
│  [Inventory ●]  [History]                                        │
└───────────────────────────────────────────────────────────────────┘
```

---

#### Tab 1: Inventory

**Layout order:** Add New Item form at the **top**, active items table below, archived items section at the bottom.

```
┌───────────────────────────────────────────────────────────────────┐
│  [Inventory ●]  [History]                                        │
├───────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──── Add New Inventory Item ──────────────────────────────┐    │
│  │  Name *               Unit *         Initial Qty *        │    │
│  │  [________________]  [________]     [____0____]          │    │
│  │  Low Alert Qty *      Overstock Qty  Cost per Unit (₱)    │    │
│  │  [________]          [________]     [________]           │    │
│  │                                          [+ Add Item]     │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                   │
│  Active Items                                                     │
│  Item Name     Qty    Unit   Low Alert  Overstock  Cost/Unit  Status   Actions         │
│  ─────────────────────────────────────────────────────────────────────────────────    │
│  Juice Tetra   48     piece  20         100        ₱12.00    [OK ✓]   [Edit][History][✕]  │
│  Graham Crac   12     pack   15         —          —         [LOW ⚠]  [Edit][History][✕]  │
│  Bread Roll     0     piece  10         —          —         [OUT ✕]  [Edit][History][✕]  │
│                                                                   │
│  ▾ Archived Items (collapsed)                                    │
│    Banana Cue   —     piece  —          —          —          archived  [Unarchive]    │
└───────────────────────────────────────────────────────────────────┘
```

- Add form is always visible at the top — no scrolling needed to add a new item
- Delete `[✕]` blocked if item has log history — show "Archive instead" prompt
- Archive replaces delete when history exists; archived items in collapsed section at bottom
- Unarchive button on each archived row
- If Initial Qty > 0 on creation, a `Restock` log is auto-created on save

**Edit Item Modal** (unchanged):
```
┌──── Edit: Juice Tetra Pack ───────────────────────────────────────┐
│  Name *               Unit *                                        │
│  [Juice Tetra Pack]  [piece ]                                      │
│  Low Alert Qty *      Overstock Qty  Cost per Unit (₱)             │
│  [___20___]          [__100__]      [__12.00__]                   │
│  [Cancel]                              [Save Changes]              │
└────────────────────────────────────────────────────────────────────┘
```

**Per-Item History Dialog** (modal, opened by `[History]` action button):
```
┌──── Stock History: Juice Tetra Pack ──────────────────────────────┐
│  Date/Time           Type      Change   Stock After  Reason        │
│  2026-06-01 08:00    Restock   +48      48           Initial stock │
│  2026-06-02 12:30    Sale      −1       47           Order #001    │
│  2026-06-03 09:00    Restock   +24      71           Delivery      │
└────────────────────────────────────────────────────────────────────┘
```

---

#### Tab 2: History

Cross-item log view — all stock movements across all inventory items.

```
┌───────────────────────────────────────────────────────────────────┐
│  [Inventory]  [History ●]                                        │
├───────────────────────────────────────────────────────────────────┤
│                                                                   │
│  From [2026-06-01] To [2026-06-30]  Type [All ▾]  Item [All ▾]  │
│                                                    [Apply Filters]│
│                                                                   │
│  Date/Time           Item               Type      Change  After  │
│  ────────────────────────────────────────────────────────────     │
│  2026-06-03 09:00    Juice Tetra Pack   Restock   +24     71     │  ← green row
│  2026-06-02 12:30    Juice Tetra Pack   Sale      −1      47     │  ← red row
│  2026-06-02 10:15    Graham Crackers    Waste     −3      9      │  ← red row
│  2026-06-01 08:00    Bread Roll         Restock   +30     30     │  ← green row
│                                                                   │
│  Showing 1–25 of 48 entries   [← Prev]  [Next →]               │
└───────────────────────────────────────────────────────────────────┘
```

Row color coding:
- Green row: `bg-green-50` — Restock (add)
- Red row: `bg-red-50` — Sale / Waste / Deduct
- Gray row: `bg-muted/30` — Manual

Tab style:
- Active tab: `border-b-2 border-primary text-primary font-semibold`
- Inactive tab: `text-muted-foreground hover:text-foreground`

---

### Screen: Linked Stock Panel (Menu Mgmt tab — per menu item)

**Terminology:** "Linked Stock" replaces "Ingredients" throughout. The linked stock panel shows which inventory items are deducted from stock when this menu item is sold at the POS. It is accessed via the **"Link Stock"** button on each menu item card.

**Route:** Inline panel on `pos.sunbites.com.ph/pos` → Menu Mgmt tab (opens below the card)
**Role:** Admin, Manager only

```
┌──── Linked Stock: Subscription Meal Tray ─────────────────────────┐
│  Inventory Item          Qty Deducted per Sale   Action            │
│  ──────────────────────────────────────────────────────            │
│  Juice Tetra Pack        1 piece                 [Remove]          │
│  Graham Crackers         1 pack                  [Remove]          │
│                                                                     │
│  + Link Inventory Item                                              │
│  [Select inventory item ▾]  Qty: [1]  [Add Link]                  │
│                                                                     │
│  ⚠ All menu items must have at least one stock item linked         │
│    before they can be sold at checkout.                            │
└────────────────────────────────────────────────────────────────────┘
```

Label and terminology:
- Button on card: **"Link Stock"** — opens/closes the panel
- Panel title: **"Linked Stock: {Menu Item Name}"**
- Column header: "Qty Deducted per Sale" (not "Qty Used per Sale")
- Add row action: **"Add Link"**
- Remove row action: **"Remove"**
- Warning: "All menu items must have at least one stock item linked before they can be sold at checkout."

Warning badge on card when `has_inventory_mapping = false`:
```
│  Subscription Meal Tray  │
│     ₱135.00              │
│     [meal]               │
│  ⚠ Not linked            │  ← orange warning badge (was "Not mapped")
│  [ON ●]  [Link Stock] [✕]│
```
