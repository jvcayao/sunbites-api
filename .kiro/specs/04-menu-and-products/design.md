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
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │              │  │              │  │              │       │
│  │ Subscription │  │ Snack A      │  │ Snack B      │       │
│  │ Meal Tray   │  │ (Bread/Pastry│  │ (Chips/      │       │
│  │   ₱135.00   │  │   ₱15.00    │  │  Crackers)   │       │
│  │  [meal]     │  │  [snack]    │  │   ₱20.00     │       │
│  │             │  │             │  │  [snack]     │       │
│  │ [ON] [✕]   │  │ [ON] [✕]   │  │ [OFF] [✕]   │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                               │
│  ┌──── Add New Item ─────────────────────────────────┐      │
│  │  Item Name *         Price (₱) *                  │      │
│  │  [________________]  [________]                   │      │
│  │  Category *                                        │      │
│  │  [meal ▾         ]                                │      │
│  │                              [+ Add Item]         │      │
│  └────────────────────────────────────────────────────┘      │
└───────────────────────────────────────────────────────────────┘
```

**Item Card Component:**
```
┌─────────────────────────┐
│  Subscription Meal Tray │  ← font-bold text-sm
│     ₱135.00             │  ← text-2xl font-extrabold text-primary
│     [meal]              │  ← text-[10px] uppercase muted badge
│                         │
│  [ON ●]     [✕]        │  ← toggle + delete
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
- Delete `[✕]`: small red button, opens confirmation dialog before delete
- Unavailable items: card opacity 50%

**Add New Item Form (inline at bottom):**
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
┌───────────────────────────────────────────────────────────┐
│  📦 Inventory — Antipolo Branch                          │
├──────────────────────────────────────────────────────────┤
│  Item Name       Qty    Unit    Threshold   Status  Action│
├──────────────────────────────────────────────────────────┤
│  Rice            50     kg      20          [OK ✓]  [Edit]│
│  Chicken          8     kg      10          [LOW ⚠] [Edit]│
│  Vegetables      15     kg       5          [OK ✓]  [Edit]│
│  Bread           30     pcs     20          [OK ✓]  [Edit]│
│  Juice Boxes      3     boxes   10          [OUT ✕] [Edit]│
└──────────────────────────────────────────────────────────┘
```

**Status Badges:**
```
[OK ✓]   → bg-green-100 text-green-700 border-green-300
[LOW ⚠]  → bg-yellow-100 text-amber-700 border-yellow-300
[OUT ✕]  → bg-red-100 text-destructive border-red-300
```

**Stock Adjustment Modal:**
```
┌─────────────────────────────────────────────┐
│  Adjust Stock: Chicken                 [✕]  │
├─────────────────────────────────────────────┤
│  Current Stock: 8 kg                        │
│                                              │
│  Adjustment Type                            │
│  (●) Add Stock   ( ) Deduct Stock          │
│                                              │
│  Quantity to Add (kg) *                     │
│  [___10___]                                  │
│                                              │
│  Reason *                                    │
│  [Restocked ▾         ]                     │
│                                              │
│  New Total: 18 kg  ← live calculation       │
│                                              │
│  [Cancel]              [Save Adjustment]    │
└─────────────────────────────────────────────┘
```

### Screen: Full Inventory Management (References > Inventory)

**Route:** `pos.sunbites.com.ph/references/inventory`
**Role:** Admin, Manager, Supervisor

Same table as POS tab + additional columns and full CRUD:
- Edit name / unit / threshold per item
- Delete item (guard: warn if has log history)
- Inventory log history per item (expandable)
- "Add Item" inline form at bottom

```
│  Item Name       Qty    Unit    Threshold   Status  Actions         │
│  Rice            50     kg      20          [OK ✓]  [Edit][Log][✕] │
```
