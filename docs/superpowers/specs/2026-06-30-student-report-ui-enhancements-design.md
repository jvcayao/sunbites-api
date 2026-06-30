# Student Report Page — UI Enhancements & Export Upgrade

**Date:** 2026-06-30
**Status:** Approved
**Projects affected:** `sunbites-api` (backend), `sunbites-pos` (frontend)

---

## Overview

Enhance the student report page (`/reports/students`) in the POS app with:

1. A text search input (debounced) across name, student number, and section
2. Toggle-chip filter pills replacing the current 3 `<Select>` dropdowns
3. Expandable table rows revealing allergies and notes per student
4. Two new columns in the Excel export — Allergies and Notes

---

## 1. Search

A single search input sits at the top of the filter toolbar.

- **Searches across:** `first_name`, `last_name`, `student_number`, `section`
- **Debounce:** 300 ms before firing a new API request
- **Behavior:** Resets pagination to page 1 on any change
- **Backend:** New optional `search` query param on `GET /reports/students` and `GET /reports/students/export`; implemented as an OR `LIKE %search%` condition across the four fields above — a row matches if the search term appears in any one of them (case-insensitive via `LOWER()` or database collation)
- No submit button — search fires automatically after the debounce interval

---

## 2. Filter Pills

Replace all three `<Select>` dropdowns with toggle-chip pill rows. A new reusable component `FilterPillGroup` handles each row.

### Layout

```
🔍 [ Search input                                ]

Status  [ All ] [ Enrolled ] [ Paused ] [ Unenrolled ] [ Banned ] [ Graduated ]

Grade   [ All ] [ Nursery ] [ K1 ] [ K2 ] [ Gr.1 ] [ Gr.2 ] [ Gr.3 ] ...
         (wraps naturally — no horizontal scroll)

Type    [ All ] [ Subscription ] [ Non-Subscription ]
```

### FilterPillGroup component

**File:** `components/reports/filter-pill-group.tsx`

```typescript
interface FilterPillGroupProps {
  label: string;
  options: { label: string; value: string }[];
  value: string;           // empty string = "All" / no filter
  onChange: (value: string) => void;
  className?: string;
}
```

- Selected pill: `bg-primary text-primary-foreground` ring
- Unselected pill: `bg-muted text-muted-foreground hover:bg-muted/80`
- Clicking the already-active pill deselects it (sets value back to `""`)
- Clicking "All" clears the selection
- Any selection change resets page to 1

### Filter options

| Group | Values |
|-------|--------|
| Status | `enrolled`, `paused`, `unenrolled`, `banned`, `graduated` |
| Grade | `Nursery`, `Kinder 1`, `Kinder 2`, `Grade 1` … `Grade 12` |
| Type | `subscription`, `non_subscription` |

---

## 3. Table — Expandable Rows

### Column layout (unchanged)

| Name | Student # | Grade | Section | Status | Balance | Spent | ‹chevron› |
|------|-----------|-------|---------|--------|---------|-------|-----------|

The existing 7 columns remain. A chevron icon column is appended on the far right.

### Expansion behavior

- Clicking anywhere on a row toggles the expanded panel for that row
- Only **one row can be open at a time** — clicking a second row closes the first
- The chevron rotates 90° (→ ↓) when the row is open via CSS `transition-transform`
- No page-level state is persisted — `expandedRowId` is local `useState<number | null>`

### Expanded panel

A full-colspan `<tr>` injected immediately after the clicked row:

```
┌──────────────────────────────────────────────────────────────────────┐
│  🥜 Allergies                    │  📝 Notes                         │
│  Peanuts, shellfish              │  Brings packed lunch on Fridays.  │
└──────────────────────────────────────────────────────────────────────┘
```

- Two side-by-side cards (`grid grid-cols-2 gap-4`)
- Each card has a labelled header and the full (untruncated) field text
- If **both** fields are `null` or empty: render a single centered line —
  *"No notes or allergies recorded for this student."*
- Field values come from the **report API response** — no additional fetch on row click

### API response change

The `GET /reports/students` paginated rows will include two new fields per student:

```typescript
// Updated StudentReportRow in lib/api/reports.ts
notes: string | null;
allergies: string | null;
```

The backend `StudentReportController::index()` selects these fields from the `students` table (direct model fields — no eager-load relationship needed).

---

## 4. Excel Export — Allergies & Notes Columns

### Changes to `StudentsExport`

**File:** `app/Exports/StudentsExport.php`

Two columns appended after the existing 12:

| # | Column header | Source |
|---|---------------|--------|
| 13 | Allergies | `$student->allergies ?? ''` |
| 14 | Notes | `$student->notes ?? ''` |

- Existing column order is preserved — columns 1–12 are unchanged
- `null` values become empty strings (not `NULL`) in the Excel output for clean readability
- The explicit sensitive-field blocklist (SSN, PhilHealth, etc.) on the export class remains untouched

### Export endpoint — search param

`GET /reports/students/export` will also accept the `search` query param so the exported file always reflects exactly what the user sees on the filtered/searched page.

---

## 5. Backend Changes Summary

**File:** `app/Http/Controllers/Kitchen/StudentReportController.php`

| Method | Change |
|--------|--------|
| `index()` | Accept `search` param; add `LIKE` filter; include `notes` and `allergies` in the per-row select |
| `export()` | Accept and forward `search` param to `StudentsExport` |

**File:** `app/Exports/StudentsExport.php`

| Change | Detail |
|--------|--------|
| Add columns 13–14 | `Allergies`, `Notes` |
| Pass `search` through | Constructor or query method receives the search string |

**No new routes, no migrations, no new models.** Both fields exist in the `students` table.

---

## 6. Frontend Changes Summary

**New file:**

| File | Purpose |
|------|---------|
| `components/reports/filter-pill-group.tsx` | Reusable labeled pill-chip filter row |

**Modified files:**

| File | Change |
|------|--------|
| `app/(kitchen)/reports/students/page.tsx` | Replace dropdowns → `FilterPillGroup`; add search input with debounce; add `expandedRowId` state; render expandable row panels; pass `search` to API and export calls |
| `lib/api/reports.ts` | Add `notes` and `allergies` to `StudentReportRow`; add `search` to API param type |

---

## 7. What Is Not Changing

- Summary cards (Total, By Grade, By Status) — no change
- Pagination controls — no change
- Export authorization (admin/manager only) — no change
- Column 1–12 in the Excel export — no change
- Any other page in the POS or portal apps
