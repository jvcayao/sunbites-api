# POS Menu Management — Search & Category Filter

**Date:** 2026-06-30
**Scope:** Frontend only — `sunbites-pos`
**File changed:** `components/pos/menu-mgmt-tab.tsx`

---

## Problem

The Menu Management tab renders all menu items in a flat grid with no way to narrow the list. As the menu grows, staff cannot quickly find a specific item to edit, toggle, or manage its stock links.

---

## Solution

Add a search input and category filter pills above the items grid, client-side. No API changes required — the full list is already fetched and cached.

---

## UI

### Layout (inserted between "Add New Item" card and items grid)

```
[ Search menu items…                    ]   ← Input (full width)

[ All ] [ Meal ] [ Snack ] [ Drink ] [ Extra ]  ← filter pills
```

### Search input

- Uses the existing `Input` component (already imported in the file).
- `sr-only` `<Label>` for accessibility (`htmlFor` pointing at input id).
- Placeholder: "Search menu items…"
- 300ms debounce via the existing `useDebounce` hook.

### Category pills

- Options: **All**, **Meal**, **Snack**, **Drink**, **Extra** — matching the 4 existing `MenuCategory` values plus an "all" sentinel.
- Active pill: `bg-primary text-primary-foreground`
- Inactive pill: `border border-border bg-background text-foreground hover:border-primary/50`
- Shape: `rounded-full px-3.5 py-1 text-sm font-medium` — identical to `menu-grid.tsx`.
- `aria-pressed` on each button for accessibility.

---

## Filter Logic

```ts
const debouncedSearch = useDebounce(search, 300);

const filteredItems = (items ?? []).filter((item) => {
  const matchesCategory =
    activeCategory === "all" || item.category === activeCategory;
  const matchesSearch =
    !debouncedSearch ||
    item.name.toLowerCase().includes(debouncedSearch.toLowerCase());
  return matchesCategory && matchesSearch;
});
```

Applied to every render. Filters all items regardless of `is_available` state (unavailable items remain visible in the management tab, just dimmed).

---

## State additions (in `MenuMgmtTab`)

```ts
const [activeCategory, setActiveCategory] = useState<"all" | MenuCategory>("all");
const [search, setSearch] = useState("");
```

The `useDebounce` hook is imported from `@/hooks/use-debounce`.

---

## Empty States

| Condition | Message |
|---|---|
| `items` is empty (no items exist at all) | "No menu items yet." (existing copy, unchanged) |
| `filteredItems` is empty but `items` is not | "No items match your search." + "Clear filters" button |

The "Clear filters" button resets both `search` to `""` and `activeCategory` to `"all"`.

---

## What Does NOT Change

- No new files created.
- No API endpoints added or modified.
- No backend (Laravel) changes.
- The `MenuItemCard`, `IngredientsPanel`, edit dialog, and delete dialog are untouched.
- The loading skeleton and error state are untouched.
