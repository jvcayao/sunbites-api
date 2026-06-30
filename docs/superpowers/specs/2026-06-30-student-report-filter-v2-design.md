# Student Report — Filter Enhancements v2

**Date:** 2026-06-30
**Status:** Approved
**Projects affected:** `sunbites-api` (backend), `sunbites-pos` (frontend)
**Builds on:** `2026-06-30-student-report-ui-enhancements-design.md`

---

## Overview

Four enhancements to the student report page (`/reports/students`):

1. **Payment status sub-filter** — when Subscription type is active, show Paid/Unpaid pills with a school-month range selector
2. **Adaptive summary cards** — all three summary cards reflect the active filters instead of always showing branch-wide counts
3. **Payment history in expanded row** — subscription students' expanded panel shows a 10-month payment timeline
4. **Clear all filters button** — one click resets every filter at once

---

## 1. Payment Status Sub-Filter

### UI behaviour

When `Type = Subscription` is selected:
- A new `FilterPillGroup` row appears below the Type row:
  ```
  Payment  [All]  [Paid]  [Unpaid]  [Void]
  ```
- When `Paid`, `Unpaid`, or `Void` is active, two `<Select>` dropdowns appear on the same row:
  ```
  Payment  [All]  [Paid]  [Unpaid]  [Void]
           From [June ▾]  To [March ▾]
  ```
- Dropdowns default to `June` (from) and `March` (to) — the full school year.
- From/To options are the 10 school months in order: June, July, August, September, October, November, December, January, February, March.
- `To` must always be ≥ `From` in school-year order. If the user changes `From` to a month after `To`, reset `To` to match `From`.
- Any change to Payment pills or From/To dropdowns resets `page` to 1.
- When `Type` is changed away from Subscription, reset `paymentStatus` to `''`, `paymentFrom` to `'june'`, `paymentTo` to `'march'`.
- Range dropdowns appear when `Paid`, `Unpaid`, or `Void` is active — NOT when `All` is active.

### Frontend state additions

```typescript
const [paymentStatus, setPaymentStatus] = useState<string>("");   // '' | 'paid' | 'unpaid' | 'voided'
const [paymentFrom, setPaymentFrom] = useState<string>("june");
const [paymentTo, setPaymentTo] = useState<string>("march");
```

Pass to API only when `studentType === 'subscription'` and `paymentStatus !== ''`:
```typescript
payment_status: studentType === 'subscription' && paymentStatus ? paymentStatus : undefined,
payment_from:   studentType === 'subscription' && paymentStatus ? paymentFrom : undefined,
payment_to:     studentType === 'subscription' && paymentStatus ? paymentTo : undefined,
```

### New `StudentReportParams` type additions

```typescript
payment_status?: string;   // 'paid' | 'unpaid' | 'voided'
payment_from?: string;     // school month slug e.g. 'june'
payment_to?: string;       // school month slug e.g. 'march'
```

### Backend — new validated params

Added to both `index()` and `export()`:

```php
'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
'payment_from'   => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
'payment_to'     => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
```

### Backend — payment filter query logic

The school months in their fixed school-year order:

```php
private const SCHOOL_MONTH_ORDER = [
    'june', 'july', 'august', 'september', 'october',
    'november', 'december', 'january', 'february', 'march',
];
```

Deriving months in range:

```php
private function monthsInRange(string $from, string $to): array
{
    $fromIdx = array_search($from, self::SCHOOL_MONTH_ORDER, true);
    $toIdx   = array_search($to,   self::SCHOOL_MONTH_ORDER, true);

    // Guard: if range is inverted or invalid, return just the from-month
    if ($fromIdx === false || $toIdx === false || $toIdx < $fromIdx) {
        return [$from];
    }

    return array_slice(self::SCHOOL_MONTH_ORDER, $fromIdx, $toIdx - $fromIdx + 1);
}
```

Deriving the school year (used to match the `year` column in `student_monthly_payments`):

```php
private function schoolYearStart(): int
{
    $month = (int) now()->format('n');
    return $month >= 6 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;
}
```

Months that belong to year `schoolYearStart + 1` (January, February, March):
```php
private const NEXT_YEAR_MONTHS = ['january', 'february', 'march'];
```

Applying the payment filter:

```php
private function applyPaymentFilter(Builder $query, string $status, string $from, string $to): void
{
    $months        = $this->monthsInRange($from, $to);
    $yearStart     = $this->schoolYearStart();

    // Build (month, year) pairs for the range
    $monthYearPairs = array_map(fn (string $m) => [
        'month' => $m,
        'year'  => in_array($m, self::NEXT_YEAR_MONTHS, true) ? $yearStart + 1 : $yearStart,
    ], $months);

    if ($status === 'paid') {
        // Student must have a paid record for EVERY month in the range
        foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
            $query->whereHas('monthlyPayments', fn ($q) =>
                $q->where('school_month', $m)->where('year', $y)->where('status', 'paid')
            );
        }
    } else {
        // Student has at least one unpaid/voided record in the range
        $targetStatus = $status; // 'unpaid' or 'voided'
        $query->whereHas('monthlyPayments', function ($q) use ($monthYearPairs, $targetStatus) {
            $q->where('status', $targetStatus)
              ->where(function ($inner) use ($monthYearPairs) {
                  foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
                      $inner->orWhere(fn ($c) => $c->where('school_month', $m)->where('year', $y));
                  }
              });
        });
    }
}
```

Usage in the query chain:

```php
->when(
    filled($validated['payment_status'] ?? null)
        && ($validated['type'] ?? null) === 'subscription',
    fn ($q) => $this->applyPaymentFilter(
        $q,
        $validated['payment_status'],
        $validated['payment_from'] ?? 'june',
        $validated['payment_to']   ?? 'march',
    )
)
```

---

## 2. Adaptive Summary Cards

### Behaviour change

Previously the three summary queries ran independently of all filters (intentionally branch-wide). Now they apply the same filter conditions as the table query.

| Filter active | Previous behaviour | New behaviour |
|---|---|---|
| None | Branch-wide enrolled count, all grades, all statuses | Same (no change) |
| Grade/Type/Search/Payment filter active | Always branch-wide | Reflects active filters |
| Status filter active | Always branch-wide | Shows that status + other filters |

The `total` field **always counts enrolled students** — with one exception: when a status filter is explicitly set, it counts students with that status instead. This preserves the "Total Enrolled" meaning for the most common case (no status filter) while still adapting when staff deliberately filter by a specific status.

`byGrade` and `byStatus` apply all active filters with no enrollment default — they show the distribution of ALL students matching the filter set, giving staff the full picture.

### Backend — summary query refactor

Build a shared base query with all active filters applied (no enrollment default on the base):

```php
$summaryBase = Student::where('branch_id', $branchId)
    ->when(filled($validated['status']  ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
    ->when(filled($validated['grade']   ?? null), fn ($q) => $q->where('grade_level',        $validated['grade']))
    ->when(filled($validated['type']    ?? null), fn ($q) => $q->where('student_type',       $validated['type']))
    ->when(filled($validated['search']  ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
    ->when(
        filled($validated['payment_status'] ?? null) && ($validated['type'] ?? null) === 'subscription',
        fn ($q) => $this->applyPaymentFilter($q, $validated['payment_status'], $validated['payment_from'] ?? 'june', $validated['payment_to'] ?? 'march')
    );

// total: always enrolled-only, unless a status filter explicitly overrides it
$total = (clone $summaryBase)
    ->when(
        ! filled($validated['status'] ?? null),
        fn ($q) => $q->where('enrollment_status', 'enrolled')
    )
    ->count();

$byGrade  = (clone $summaryBase)->selectRaw('grade_level, COUNT(*) as count')->groupBy('grade_level')->pluck('count', 'grade_level');
$byStatus = (clone $summaryBase)->selectRaw('enrollment_status, COUNT(*) as count')->groupBy('enrollment_status')->pluck('count', 'enrollment_status');
```

The existing `$query` (for the paginated rows) keeps its own `->with(['wallet'])` and ordering. It applies the same filters — no shared reference, built separately.

### Frontend — label update

The frontend computes whether any filter is active from its own state — no need for the server to echo this back:

```typescript
const hasActiveFilters =
  search !== "" ||
  enrollmentStatus !== "" ||
  gradeLevel !== "" ||
  studentType !== "" ||
  paymentStatus !== "";
```

Card label:
```tsx
<p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
  {hasActiveFilters ? "Matching Students" : "Total Enrolled"}
</p>
```

### Test update

The existing test `test_summary_is_not_affected_by_search` tested the OLD intentional behaviour. It must be **replaced** with a test that asserts the new behaviour:

```php
public function test_summary_total_reflects_active_search_filter(): void
{
    // 3 enrolled students named Juan, 1 enrolled student named Maria
    Student::factory()->count(3)->create([
        'branch_id'         => $this->branch->id,
        'first_name'        => 'Juan',
        'enrollment_status' => 'enrolled',
    ]);
    Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'first_name'        => 'Maria',
        'enrollment_status' => 'enrolled',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan');

    $response->assertOk();
    $this->assertCount(3, $response->json('data'));
    // summary.total = enrolled students matching search (Juan only) = 3
    $this->assertSame(3, $response->json('summary.total'));
}

public function test_summary_total_still_defaults_to_enrolled_when_no_status_filter(): void
{
    Student::factory()->count(3)->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'paused',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk();
    // total = enrolled only (3), not all students (4)
    $this->assertSame(3, $response->json('summary.total'));
}
```

---

## 3. Payment History in Expanded Row

### API response addition

Each row in `data[]` gains a `payment_history` field:

```typescript
// In StudentReportRow:
payment_history: Array<{
  month: string;         // 'june' | 'july' | ... | 'march'
  month_label: string;   // 'June' | 'July' | ... | 'March'
  year: number;
  status: string;        // 'paid' | 'unpaid' | 'voided' | 'no_record'
}> | null;               // null for non-subscription students
```

`'no_record'` is used when no `StudentMonthlyPayment` row exists for that month (e.g., student enrolled after that month had passed).

### Backend — eager load and map

In `index()`, add `monthlyPayments` to the eager load scoped to the current school year only — loading all years would pull in unnecessary records for long-enrolled students:

```php
$yearStart = $this->schoolYearStart();

$query = Student::where('branch_id', $branchId)
    ->with([
        'wallet',
        'monthlyPayments' => fn ($q) => $q->whereIn('year', [$yearStart, $yearStart + 1]),
    ])
    // ... rest of query
```

In the `through()` map:

```php
'payment_history' => $student->student_type?->value === 'subscription'
    ? $this->buildPaymentHistory($student)
    : null,
```

```php
private function buildPaymentHistory(Student $student): array
{
    $yearStart = $this->schoolYearStart();
    $payments  = $student->monthlyPayments
        ->filter(fn ($p) => $p->year === $yearStart || $p->year === $yearStart + 1)
        ->keyBy(fn ($p) => $p->school_month->value . '-' . $p->year);

    return array_map(function (string $month) use ($yearStart, $payments) {
        $year    = in_array($month, self::NEXT_YEAR_MONTHS, true) ? $yearStart + 1 : $yearStart;
        $key     = $month . '-' . $year;
        $payment = $payments->get($key);

        return [
            'month'       => $month,
            'month_label' => ucfirst($month),
            'year'        => $year,
            'status'      => $payment?->status ?? 'no_record',
        ];
    }, self::SCHOOL_MONTH_ORDER);
}
```

### Frontend — expanded row update

In the expanded panel, after the Allergies + Notes grid, add a payment history section **only when `row.payment_history !== null`**:

```
┌──────────────────────────────────────────────────────────┐
│  Allergies          │  Notes                             │
│  Peanuts, shellfish │  Packed lunch on Fridays.          │
├──────────────────────────────────────────────────────────┤
│  Payment History                                         │
│  Jun ✓  Jul ✓  Aug ✗  Sep ✗  Oct –  Nov –  Dec –  ...  │
└──────────────────────────────────────────────────────────┘
```

Each month is a small cell with the 3-letter month abbreviation and a status indicator:
- `paid` → `bg-green-100 text-green-700` with ✓
- `unpaid` → `bg-red-100 text-red-700` with ✗
- `voided` → `bg-muted text-muted-foreground` with strikethrough
- `no_record` → `bg-muted/40 text-muted-foreground` with `—`

Render as a single-row 10-column flex/grid, wrapping naturally on small screens.

---

## 4. Clear All Filters Button

### Behaviour

A `× Clear filters` button appears in the filter toolbar **whenever any filter is active** (search, status, grade, type, payment). Clicking it resets all state to defaults:

```typescript
function clearAllFilters() {
  setSearchInput("");
  setSearch("");
  setEnrollmentStatus("");
  setGradeLevel("");
  setStudentType("");
  setPaymentStatus("");
  setPaymentFrom("june");
  setPaymentTo("march");
  setPage(1);
  setExpandedRowId(null);
}
```

### Position

Right-aligned in the toolbar, on the same row as the search input:

```
[ 🔍 Search input...                    ]  [× Clear filters]
```

The button uses `variant="ghost"` and `size="sm"` with `text-muted-foreground hover:text-foreground`. It does NOT appear when no filter is active.

### Active filter detection (frontend)

```typescript
const hasActiveFilters =
  search !== "" ||
  enrollmentStatus !== "" ||
  gradeLevel !== "" ||
  studentType !== "" ||
  paymentStatus !== "";
```

---

## 5. Files Changed

### Backend — `~/sunbites-api`

| File | Change |
|------|--------|
| `app/Http/Controllers/Kitchen/StudentReportController.php` | Add `SCHOOL_MONTH_ORDER`, `NEXT_YEAR_MONTHS` constants; add `applyPaymentFilter()`, `monthsInRange()`, `schoolYearStart()`, `buildPaymentHistory()` private methods; refactor summary queries to use filtered base with enrolled default on total; add `payment_history` to row map; scope `monthlyPayments` eager load to current school year |
| `tests/Feature/Reports/StudentReportTest.php` | Delete `test_summary_is_not_affected_by_search`; add replacement + new payment filter + payment history tests |

### Frontend — `~/sunbites-pos`

| File | Change |
|------|--------|
| `lib/api/reports.ts` | Add `payment_status`, `payment_from`, `payment_to` to params; add `payment_history` to `StudentReportRow` |
| `app/(kitchen)/reports/students/page.tsx` | Add payment state; payment pill row + range selectors; clear all button; payment history panel in expanded row; adaptive card label |

**No new files.** `FilterPillGroup` is reused for the Payment row unchanged.

---

## 6. What Is Not Changing

- The `export()` endpoint accepts and forwards `payment_status`, `payment_from`, `payment_to` (same as `index()`) but does NOT include `payment_history` in the Excel output — that would be too complex a column structure for the current export format.
- Column 1–14 order in `StudentsExport` — no change.
- The `FilterPillGroup` component itself — no changes needed.
- Portal app — no changes.
