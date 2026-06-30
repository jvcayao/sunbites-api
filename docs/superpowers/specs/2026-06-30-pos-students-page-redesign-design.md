# POS Students Page Redesign

**Date:** 2026-06-30
**Project:** `~/sunbites-pos`
**Scope:** `app/(kitchen)/students/page.tsx` — main list page only. Student detail page (`students/[id]/page.tsx`) is unchanged.

---

## Problem Statement

The current students page (`page.tsx`, 1 721 lines) has three compounding issues:

1. **No pagination UI.** The API returns `PaginatedStudents` with full `meta` but the frontend never passes a `page` param — all students are fetched and rendered at once. Staff cannot reach students past the first page.
2. **Visual ambiguity.** Subscription and non-subscription students are distinguished only by a thin left-border colour on the card. They are easy to confuse at a glance.
3. **Filtering bug.** Subscription-specific filters (`month`, `payment_status`) are rendered regardless of which tab is active. The `type` query parameter works correctly on the single-tab view but the underlying architecture (one query, client-side split) has no per-section page state.
4. **QR batch print bugs (two).** See section below.

---

## Current Architecture (Verified)

One `useQuery` call fetches all students. On the "All" tab, the result is split **client-side**:

```typescript
const allStudents = data?.data ?? [];
const subscriptionStudents = allStudents.filter(s => s.student_type === "subscription");
const nonSubStudents = allStudents.filter(s => s.student_type === "non_subscription");

// This is the print bug root cause:
const selectedStudents = allStudents.filter(s => selectedIds.has(s.id));
```

The "All" tab already renders two sections with headings ("Subscription Students (N)" / "Non-Subscription Students (N)"). The **structure** exists; it just has no pagination and no independent data per section.

---

## Approved Design

### Layout: Row List, Stacked Independent Sections

Three tabs: **All Students · Subscription · Non-Subscription**

**All tab** — two stacked section boxes:
- Subscription Students box (amber `#F59E0B` accent colour, amber left strip on each row)
- Non-Subscription Students box (violet `#8B5CF6` accent colour, violet left strip on each row)

Each section box makes its own independent API call and has its own pagination. Paging one section never affects the other.

**Subscription tab** — single section box, subscription students only, with subscription-specific filters.

**Non-subscription tab** — single section box, non-subscription students only, with basic filters.

### Row Card Layout

Each student is a horizontal row card (replacing the existing card-grid `StudentCard`). Fields per row:

```
[strip] [checkbox] [avatar] [name / grade · section]   [month badges*]   [₱ balance]
```

`*` Month badges only appear for subscription students. Non-subscription rows have no month column.

On mobile (< 560px): month badges wrap to a second line, indented to align under the name. Tab bar scrolls horizontally.

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

Pagination controls: `← · 1 · 2 · 3 · … · N · →` with "Showing X–Y of Z" label per section.

---

## QR Batch Print — Two Bug Fixes

### Bug 1: Cross-tab selection loss (root cause verified)

**Current code (line 1346):**
```typescript
const selectedStudents = allStudents.filter((s) => selectedIds.has(s.id));
```

`allStudents` is the current tab's query result. When a user selects students on the Subscription tab and then switches to the Non-subscription tab, `allStudents` is replaced with non-sub data only. The subscription student IDs remain in `selectedIds` but their full objects are gone — `selectedStudents` becomes empty for those IDs.

**Fix:** Replace `selectedIds: Set<number>` with `selectedStudents: Map<number, Student>`. Store the complete student object at checkbox-click time:

```typescript
// Before
const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
const selectedStudents = allStudents.filter(s => selectedIds.has(s.id)); // lost on tab switch

// After
const [selectedStudents, setSelectedStudents] = useState<Map<number, Student>>(new Map());
// Full object stored on select, persists through tab switches
```

The floating bar derives its count from `selectedStudents.size`. The type breakdown ("X subscription · Y non-subscription") is derived by counting `student_type` across `selectedStudents.values()`.

`BatchQrModal` receives `Array.from(selectedStudents.values())` — no query cache lookup needed.

### Bug 2: Wrong print card colour in mixed batches (root cause verified)

**Current `PrintCard` (line 443):**
```typescript
const colors = getCardAccentColors(student.student_type);
```

`PrintCard` **already** reads colour per-student. The bug is NOT inside `PrintCard`. The wrong colour appears because Bug 1 causes the wrong student objects to be passed to `BatchQrModal` — some missing, some substituted from the current tab's data.

**Fix:** Once Bug 1 is resolved (Map stores full objects), `PrintCard` automatically receives the correct `student.student_type` for every student, rendering the right colour with no change to `PrintCard` or `getCardAccentColors`.

**Print card colours are unchanged:**
- `subscription` → red `#e5322a` (via `getCardAccentColors` — existing, tested)
- `non_subscription` → yellow `#f4b400` (via `getCardAccentColors` — existing, tested)

The amber/violet colours shown in the visual mockup apply only to the **list UI** (section headers, row strips). Print card colours stay as defined in `getCardAccentColors`.

---

## Component Architecture

The existing single-file structure is preserved. No file splits.

### New queries — split into two

Replace the single `useQuery` with two independent queries, each with its own `page` state:

```typescript
const [subPage, setSubPage] = useState(1);
const [nonSubPage, setNonSubPage] = useState(1);

const subQuery = useQuery({
  queryKey: ["students", "subscription", { subPage, search, gradeFilter, statusFilter, monthFilter, yearFilter, paymentStatusFilter }],
  queryFn: () => studentApi.list({ type: "subscription", page: subPage, ... }),
});

const nonSubQuery = useQuery({
  queryKey: ["students", "non_subscription", { nonSubPage, search, gradeFilter, statusFilter }],
  queryFn: () => studentApi.list({ type: "non_subscription", page: nonSubPage, ... }),
});
```

On the All tab, both queries run. On the Subscription tab, only the subscription query's data is rendered (the query is already cached). On the Non-subscription tab, same pattern.

### `invalidateQueries` — all sub-components

`MonthBadges`, `RemoveStudentDialog`, and `DeletedStudentCard` all currently call:
```typescript
queryClient.invalidateQueries({ queryKey: ["students"] })
```

With the split query keys above, this prefix still works — `["students"]` is a prefix of both `["students", "subscription", ...]` and `["students", "non_subscription", ...]`. TanStack Query's prefix matching means **no changes needed** to these sub-components.

### Changes summary inside the file

| Area | Change |
|---|---|
| `selectedIds: Set<number>` | → `selectedStudents: Map<number, Student>` |
| `selectedStudents = allStudents.filter(...)` | removed; Map is the source of truth |
| `toggleSelect(id, checked)` | adds/removes full `Student` object to/from Map |
| `clearSelection()` | clears Map |
| `StudentCard` component | → new `StudentRow` component (horizontal row layout) |
| Single `useQuery` call | → two queries: `subQuery` + `nonSubQuery`, each with own `page` state |
| Pagination UI | new `<Pagination>` component inside each section box footer |
| Filter visibility | controlled by `activeTab` — subscription filters hidden when not on sub tab |
| `BatchQrModal` call | receives `Array.from(selectedStudents.values())` |
| `PrintCard` | no changes — already uses `getCardAccentColors(student.student_type)` |
| `getCardAccentColors` | no changes — colours stay red/yellow |

### What is NOT changing

- `BatchQrModal` internal logic (cols config, `createPortal`, `window.print()`, dimensions)
- `PrintCard` component
- `getCardAccentColors` utility and its colour values
- `WalletTopUpModal`
- `StatusPickerDialog`
- `MonthBadges` (including click-to-toggle for admin/manager)
- `DeletedStudentCard` + show-deleted flow
- `RemoveStudentDialog`
- `queryClient.invalidateQueries` calls in all sub-components (prefix still matches)

---

## API Contract

No backend changes. `studentApi.list(params)` already accepts `type`, `page`, and all filter params.

On the All tab, two separate calls:
```typescript
// Subscription section — no sub-specific filters on All tab
studentApi.list({ type: "subscription", page: subPage, search, grade, status })

// Non-subscription section
studentApi.list({ type: "non_subscription", page: nonSubPage, search, grade, status })
```

On the Subscription tab, `month`, `year`, `payment_status` are added to the subscription call only. On the All tab these params are omitted entirely (filter controls hidden).

---

## Testing

Existing test file: `app/(kitchen)/students/student-list.test.tsx`

**Tests that will PASS unchanged** (behaviour preserved):
- "renders student list heading" — heading text unchanged
- "shows subscription student with name" — Maria Santos still appears
- "shows non-subscription student" — Carlo Mendoza still appears
- "shows enrollment status badge" — status badges still rendered
- "shows type tabs for all, subscription, non-subscription"
- "shows section headings in All tab" — headings already exist, kept
- "shows Enroll Student link" — link unchanged
- "PrintCard renders red header for subscription" — `#e5322a` unchanged
- "PrintCard renders yellow header for non-subscription" — `#f4b400` unchanged

**Tests that need UPDATING** (behaviour same, DOM changed):
- "shows search input" — `aria-label="Search students"` stays, but verify the label survives filter bar restructure
- "selects a student and shows floating bar" — checkbox selector and Print QR button still present; `data-` attribute may need checking
- "filters to subscription tab when clicked" — tab button query may need adjustment if button role changes

**Tests that will FAIL and need REWRITING**:
- "shows month payment badges for subscription student" — `MonthBadges` component is still used; verify it still renders `Jun` etc. in row layout
- "shows wallet-only info box for non-subscription student" — the text "wallet-only" does NOT appear in `StudentRow`; this test must be removed or replaced with an assertion that the non-sub row renders without month badges

**Tests to ADD**:
- Subscription section has its own pagination independent of non-subscription section
- Switching from Subscription tab to Non-subscription tab, then opening print modal, includes students selected from both tabs
- Mixed batch print modal contains both subscription (red) and non-subscription (yellow) cards
- Month / payment filters absent from DOM when on Non-subscription or All tab
- `selectedStudents` Map retains full student objects after tab switch
