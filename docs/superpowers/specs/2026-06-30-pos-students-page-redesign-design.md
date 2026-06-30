# POS Students Page Redesign

**Date:** 2026-06-30
**Project:** `~/sunbites-pos`
**Scope:** `app/(kitchen)/students/page.tsx` — main list page only. Student detail page (`students/[id]/page.tsx`) is unchanged.

---

## Problem Statement

The current students page (`page.tsx`, 1 721 lines) has three compounding issues:

1. **No pagination UI.** The API returns `PaginatedStudents` with full `meta` but the frontend never wires up page controls — all students are fetched and rendered at once. Staff cannot reach students past the first batch.
2. **Visual ambiguity.** Subscription and non-subscription students are distinguished only by a thin left-border colour on the card. They are easy to confuse at a glance.
3. **Filtering bug.** Subscription-specific filters (`month`, `payment_status`) are rendered regardless of which tab is active, and the `type` query parameter is not properly scoped per tab — meaning filters can be applied to the wrong student type.
4. **QR batch print bugs (two).** See section below.

---

## Approved Design

### Layout: Row List, Stacked Independent Sections

Three tabs: **All Students · Subscription · Non-Subscription**

**All tab** — two stacked section boxes, vertically:
- Subscription Students box (amber `#F59E0B` header, amber left strip on each row)
- Non-Subscription Students box (violet `#8B5CF6` header, violet left strip on each row)

Each section box makes its own independent API call and has its own pagination controls. Paging one section never affects the other.

**Subscription tab** — single full-width section box, subscription students only, with subscription-specific filters.

**Non-subscription tab** — single full-width section box, non-subscription students only.

### Row Card Layout

Each student is a horizontal row card (not the existing card-grid style). Fields shown per row:

```
[strip] [checkbox] [avatar] [name / grade · section]   [month badges*]   [₱ balance]
```

`*` Month badges only appear for subscription students. Non-subscription rows have no month column.

On mobile (< 560px): month badges wrap to a second line, indented to align with the student name. Tab bar scrolls horizontally without clipping.

### Filter Bars (swap per tab)

| Tab | Filters shown |
|---|---|
| All | Search, Grade, Status |
| Subscription | Search, Grade, Status, Month, Payment Status |
| Non-subscription | Search, Grade, Status |

Month and payment status filters never appear outside the Subscription tab.

### Pagination

- All tab: 8 rows per section (overview density)
- Subscription tab: 20 rows per page
- Non-subscription tab: 20 rows per page

Pagination controls: `← prev · 1 · 2 · 3 · … · N · next →` with "Showing X–Y of Z" label. Standard numbered controls, same style as other list pages.

---

## QR Batch Print — Two Bug Fixes

### Bug 1: Cross-tab selection loss

**Current behaviour:** `selectedIds` is a `Set<number>`. When switching tabs, the query cache for the previously active tab may be stale or unmounted, leaving the print modal unable to retrieve the full student objects for selected IDs from a different tab.

**Fix:** Replace `selectedIds: Set<number>` with `selectedStudents: Map<number, Student>`. Store the complete student object at checkbox-click time, not at print time. This means:
- Tab switches never lose selection state
- The print modal reads directly from the Map — no query cache lookup needed
- The floating action bar breakdown (`1 subscription · 1 non-subscription`) is derived by counting `student_type` across Map values

### Bug 2: Wrong print card colour in mixed batches

**Current behaviour:** `PrintCard` derives its header colour from a shared prop or defaults to subscription (red) regardless of the individual student's type. A non-subscription student in a mixed batch prints with the wrong colour.

**Fix:** `PrintCard` receives `student: Student` and derives its own header colour from `student.student_type` independently. No global/shared colour prop. Colour mapping:
- `subscription` → amber `#F59E0B` header
- `non_subscription` → violet `#8B5CF6` header

The existing print dimensions (`53.98mm × 85.6mm`), QR code rendering, column-count config, and `createPortal` + `window.print()` mechanism are **unchanged**.

### Floating Action Bar

The bar remains fixed at the bottom of the viewport whenever `selectedStudents.size >= 1`, across all tabs. It displays:
- Total count: "N selected"
- Type breakdown: "X subscription · Y non-subscription" (derived from Map values)
- "Print QR Codes" button → opens `BatchQrModal`
- "✕ Clear" button → clears the Map

---

## Component Architecture

The existing 1 721-line single-file component is not broken into separate files as part of this task. The scope is layout, pagination, and bug fixes only. Internal sub-components (`BatchQrModal`, `WalletTopUpModal`, `StatusPickerDialog`, `MonthBadges`, `RemoveStudentDialog`) remain co-located.

What changes inside the file:

| Area | Change |
|---|---|
| `selectedIds: Set<number>` | → `selectedStudents: Map<number, Student>` |
| `StudentCard` component | → new `StudentRow` component (horizontal row layout) |
| `activeTab` state | stays; drives filter bar visibility and API `type` param |
| `useQuery` call | split into two calls: one per type, each with its own `page` state |
| Pagination UI | new `<Pagination>` component rendered inside each section box footer |
| `PrintCard` | receives `student: Student`, derives colour from `student.student_type` |
| Filter visibility | conditional on `activeTab` — subscription filters hidden when not on `sub` tab |

---

## API Contract

No backend changes. The existing `studentApi.list(params)` endpoint already accepts `type`, `page`, and all filter params. The frontend makes two separate calls:

```typescript
// Subscription section
studentApi.list({ type: 'subscription', page: subPage, ...sharedFilters })

// Non-subscription section
studentApi.list({ type: 'non_subscription', page: nonSubPage, ...sharedFilters })
```

On the Subscription tab, subscription-specific filters (`month`, `payment_status`, `year`) are added to the subscription call only. On the All tab, neither section call includes `month`, `payment_status`, or `year` — those params are omitted entirely since the filter controls are hidden on that tab.

---

## Testing

Existing test file: `app/(kitchen)/students/student-list.test.tsx`

Tests to **update** (layout changed, behaviour same):
- Section headings in "All" tab — now section box headers, not inline dividers
- Tab filtering — `type` param must be correctly scoped per tab
- Multi-select floating bar — now shows type breakdown

Tests to **add**:
- Subscription section has independent pagination from non-subscription section
- `PrintCard` renders amber header for `subscription` student
- `PrintCard` renders violet header for `non_subscription` student
- Mixed batch print modal contains both amber and violet cards
- Selecting from Subscription tab, switching to Non-subscription tab, then opening print modal includes all previously selected students
- Month/payment filters absent from DOM when on Non-subscription tab

Tests to **preserve unchanged**:
- QR print column config (1–3 per row)
- Wallet top-up modal
- Status picker dialog
- Remove/restore student dialogs
- Monthly payment badge toggling (admin/manager only)
