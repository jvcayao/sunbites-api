# Student Report Filter Enhancements v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a subscription payment sub-filter (Paid/Unpaid/Void + month range), make summary cards reflect active filters, show payment history in expanded rows, and add a "Clear all filters" button.

**Architecture:** Three sequential backend tasks build on each other (payment filter → adaptive summary → payment history), followed by three frontend tasks (types → payment UI → card/history UI). Backend tasks are independently testable against the real database. Frontend tasks depend on the type definitions from Task 4.

**Tech Stack:** Laravel 13, PHPUnit 12 (backend); Next.js 15, React 19, TanStack Query v5, Tailwind v4, shadcn/ui (frontend). All PHP via `vendor/bin/sail`. Before writing any code, use `mcp__laravel-boost__search-docs` to look up relevant docs.

## Global Constraints

- All PHP commands via `vendor/bin/sail`; run `vendor/bin/sail bin pint --dirty --format agent` after every PHP change
- `LazilyRefreshDatabase` on every Feature test; auth pattern: `Sanctum::actingAs($user, ['staff'])` + `->withHeaders(['X-Branch-Id' => $branch->id])` via existing `asAdmin()` / `asManager()` helpers in `StudentReportTest`
- School year start: `now()->month >= 6 ? now()->year : now()->year - 1` — matches `BillingReportController` exactly
- `SCHOOL_MONTH_ORDER = ['june','july','august','september','october','november','december','january','february','march']`
- `NEXT_YEAR_MONTHS = ['january','february','march']` (these belong to `schoolYearStart + 1`)
- Payment filter only applies when `type === 'subscription'` AND `payment_status` is filled
- `total` in summary always counts enrolled students by default; only overridden if `status` filter is explicitly set
- No `any` in TypeScript; named exports only for React components; `cn()` for all conditional classes
- `Select`, `SelectContent`, `SelectItem`, `SelectTrigger`, `SelectValue` from `@/components/ui/select` (already imported in sales report page — same pattern)

---

## File Map

**Backend — `~/sunbites-api`**

| Action | File |
|--------|------|
| Modify | `app/Http/Controllers/Kitchen/StudentReportController.php` |
| Modify | `tests/Feature/Reports/StudentReportTest.php` |

**Frontend — `~/sunbites-pos`**

| Action | File |
|--------|------|
| Modify | `lib/api/reports.ts` |
| Modify | `app/(kitchen)/reports/students/page.tsx` |

---

### Task 1: Backend — Payment status filter

**Files:**
- Modify: `app/Http/Controllers/Kitchen/StudentReportController.php`
- Modify: `tests/Feature/Reports/StudentReportTest.php`

**Interfaces:**
- Produces: `GET /api/v1/reports/students?type=subscription&payment_status=paid&payment_from=june&payment_to=august` filters rows; same params on `/export`
- Private methods produced: `schoolYearStart(): int`, `monthsInRange(string, string): array`, `applyPaymentFilter(Builder, string, string, string): void`
- Constants produced: `SCHOOL_MONTH_ORDER`, `NEXT_YEAR_MONTHS`

---

- [ ] **Step 1: Write the failing tests**

Append these tests to `tests/Feature/Reports/StudentReportTest.php` (inside the class, before the closing brace):

```php
public function test_payment_filter_paid_returns_students_with_all_months_paid_in_range(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    // Student A: paid for June, July, August — should be included
    $paidStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    foreach (['june', 'july', 'august'] as $month) {
        $paidStudent->monthlyPayments()->create([
            'school_month' => $month,
            'year'         => $yearStart,
            'status'       => 'paid',
            'amount'       => 2970,
        ]);
    }

    // Student B: paid June and July only, missing August — should be excluded
    $partialStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    foreach (['june', 'july'] as $month) {
        $partialStudent->monthlyPayments()->create([
            'school_month' => $month,
            'year'         => $yearStart,
            'status'       => 'paid',
            'amount'       => 2970,
        ]);
    }

    $response = $this->asAdmin()->getJson(
        '/api/v1/reports/students?type=subscription&payment_status=paid&payment_from=june&payment_to=august'
    );

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame($paidStudent->id, $response->json('data.0.id'));
}

public function test_payment_filter_unpaid_returns_students_with_any_unpaid_month_in_range(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    // Student with one unpaid month — should be included
    $unpaidStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $unpaidStudent->monthlyPayments()->create([
        'school_month' => 'june',
        'year'         => $yearStart,
        'status'       => 'unpaid',
        'amount'       => 2970,
    ]);

    // Student with all paid — should be excluded
    $paidStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $paidStudent->monthlyPayments()->create([
        'school_month' => 'june',
        'year'         => $yearStart,
        'status'       => 'paid',
        'amount'       => 2970,
    ]);

    $response = $this->asAdmin()->getJson(
        '/api/v1/reports/students?type=subscription&payment_status=unpaid&payment_from=june&payment_to=june'
    );

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame($unpaidStudent->id, $response->json('data.0.id'));
}

public function test_payment_filter_voided_returns_students_with_any_voided_month(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    $voidedStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $voidedStudent->monthlyPayments()->create([
        'school_month' => 'july',
        'year'         => $yearStart,
        'status'       => 'voided',
        'amount'       => 2970,
    ]);

    $normalStudent = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $normalStudent->monthlyPayments()->create([
        'school_month' => 'july',
        'year'         => $yearStart,
        'status'       => 'paid',
        'amount'       => 2970,
    ]);

    $response = $this->asAdmin()->getJson(
        '/api/v1/reports/students?type=subscription&payment_status=voided&payment_from=july&payment_to=july'
    );

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame($voidedStudent->id, $response->json('data.0.id'));
}

public function test_payment_filter_is_ignored_when_type_is_not_subscription(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    // Non-subscription student with no payment records — should appear
    $nonSubStudent = Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'student_type'      => 'non_subscription',
        'enrollment_status' => 'enrolled',
    ]);

    // Passing payment_status without type=subscription — filter must be ignored
    $response = $this->asAdmin()->getJson(
        '/api/v1/reports/students?payment_status=paid&payment_from=june&payment_to=june'
    );

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    $this->assertTrue($ids->contains($nonSubStudent->id));
}

public function test_export_accepts_payment_filter_params(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    $student = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $student->monthlyPayments()->create([
        'school_month' => 'june',
        'year'         => $yearStart,
        'status'       => 'paid',
        'amount'       => 2970,
    ]);

    $response = $this->asManager()->getJson(
        '/api/v1/reports/students/export?type=subscription&payment_status=paid&payment_from=june&payment_to=june'
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
```

- [ ] **Step 2: Run tests — expect all 5 to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_payment_filter
```

Expected: 4 failures with "Validation failed" or "no results". Also run export test:
```bash
vendor/bin/sail artisan test --compact --filter=test_export_accepts_payment_filter
```

- [ ] **Step 3: Add constants, private methods, and new params to controller**

Replace the full content of `app/Http/Controllers/Kitchen/StudentReportController.php`:

```php
<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\StudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentReportController extends Controller
{
    private const SCHOOL_MONTH_ORDER = [
        'june', 'july', 'august', 'september', 'october',
        'november', 'december', 'january', 'february', 'march',
    ];

    private const NEXT_YEAR_MONTHS = ['january', 'february', 'march'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'         => ['nullable', 'string'],
            'grade'          => ['nullable', 'string'],
            'type'           => ['nullable', 'string'],
            'search'         => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'payment_from'   => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
            'payment_to'     => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId  = app('active_branch')->id;
        $yearStart = $this->schoolYearStart();
        $perPage   = $validated['per_page'] ?? 25;

        $applyPayment = filled($validated['payment_status'] ?? null)
            && ($validated['type'] ?? null) === 'subscription';

        $query = Student::where('branch_id', $branchId)
            ->with([
                'wallet',
                'monthlyPayments' => fn ($q) => $q->whereIn('year', [$yearStart, $yearStart + 1]),
            ])
            ->when(filled($validated['status']  ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade']   ?? null), fn ($q) => $q->where('grade_level',        $validated['grade']))
            ->when(filled($validated['type']    ?? null), fn ($q) => $q->where('student_type',       $validated['type']))
            ->when(filled($validated['search']  ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->when($applyPayment, fn ($q) => $this->applyPaymentFilter(
                $q,
                $validated['payment_status'],
                $validated['payment_from'] ?? 'june',
                $validated['payment_to']   ?? 'march',
            ))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $summaryBase = Student::where('branch_id', $branchId)
            ->when(filled($validated['status']  ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade']   ?? null), fn ($q) => $q->where('grade_level',        $validated['grade']))
            ->when(filled($validated['type']    ?? null), fn ($q) => $q->where('student_type',       $validated['type']))
            ->when(filled($validated['search']  ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->when($applyPayment, fn ($q) => $this->applyPaymentFilter(
                $q,
                $validated['payment_status'],
                $validated['payment_from'] ?? 'june',
                $validated['payment_to']   ?? 'march',
            ));

        $total = (clone $summaryBase)
            ->when(
                ! filled($validated['status'] ?? null),
                fn ($q) => $q->where('enrollment_status', 'enrolled')
            )
            ->count();

        $byGrade  = (clone $summaryBase)->selectRaw('grade_level, COUNT(*) as count')->groupBy('grade_level')->pluck('count', 'grade_level');
        $byStatus = (clone $summaryBase)->selectRaw('enrollment_status, COUNT(*) as count')->groupBy('enrollment_status')->pluck('count', 'enrollment_status');

        $paginator = $query->paginate($perPage);

        $rows = $paginator->through(fn (Student $student) => [
            'id'              => $student->id,
            'full_name'       => $student->full_name,
            'student_number'  => $student->student_number,
            'grade_level'     => $student->grade_level,
            'section'         => $student->section,
            'status'          => $student->enrollment_status?->value,
            'wallet_balance'  => (float) ($student->wallet?->balanceFloat ?? 0),
            'total_spent'     => (float) $student->total_spent,
            'notes'           => $student->notes,
            'allergies'       => $student->allergies,
            'payment_history' => $student->student_type?->value === 'subscription'
                ? $this->buildPaymentHistory($student, $yearStart)
                : null,
        ]);

        return response()->json([
            'data'    => $rows->items(),
            'meta'    => $this->paginationMeta($rows),
            'summary' => [
                'total'            => $total,
                'grade_breakdown'  => $byGrade,
                'status_breakdown' => $byStatus,
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'status'         => ['nullable', 'string'],
            'grade'          => ['nullable', 'string'],
            'type'           => ['nullable', 'string'],
            'search'         => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'payment_from'   => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
            'payment_to'     => ['nullable', 'string', 'in:june,july,august,september,october,november,december,january,february,march'],
        ]);

        $branch = app('active_branch');

        $applyPayment = filled($validated['payment_status'] ?? null)
            && ($validated['type'] ?? null) === 'subscription';

        $students = Student::where('branch_id', $branch->id)
            ->with([
                'contacts' => fn ($q) => $q->where('is_primary', true),
                'wallet',
            ])
            ->when(filled($validated['status']  ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade']   ?? null), fn ($q) => $q->where('grade_level',        $validated['grade']))
            ->when(filled($validated['type']    ?? null), fn ($q) => $q->where('student_type',       $validated['type']))
            ->when(filled($validated['search']  ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->when($applyPayment, fn ($q) => $this->applyPaymentFilter(
                $q,
                $validated['payment_status'],
                $validated['payment_from'] ?? 'june',
                $validated['payment_to']   ?? 'march',
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $filename = "students-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StudentsExport($students), $filename);
    }

    private function schoolYearStart(): int
    {
        return now()->month >= 6 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;
    }

    private function monthsInRange(string $from, string $to): array
    {
        $fromIdx = array_search($from, self::SCHOOL_MONTH_ORDER, true);
        $toIdx   = array_search($to,   self::SCHOOL_MONTH_ORDER, true);

        if ($fromIdx === false || $toIdx === false || $toIdx < $fromIdx) {
            return [$from];
        }

        return array_slice(self::SCHOOL_MONTH_ORDER, $fromIdx, $toIdx - $fromIdx + 1);
    }

    private function applyPaymentFilter(Builder $query, string $status, string $from, string $to): void
    {
        $yearStart      = $this->schoolYearStart();
        $months         = $this->monthsInRange($from, $to);
        $monthYearPairs = array_map(fn (string $m) => [
            'month' => $m,
            'year'  => in_array($m, self::NEXT_YEAR_MONTHS, true) ? $yearStart + 1 : $yearStart,
        ], $months);

        if ($status === 'paid') {
            foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
                $query->whereHas('monthlyPayments', fn ($q) =>
                    $q->where('school_month', $m)->where('year', $y)->where('status', 'paid')
                );
            }
        } else {
            $query->whereHas('monthlyPayments', function ($q) use ($monthYearPairs, $status) {
                $q->where('status', $status)
                  ->where(function ($inner) use ($monthYearPairs) {
                      foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
                          $inner->orWhere(fn ($c) => $c->where('school_month', $m)->where('year', $y));
                      }
                  });
            });
        }
    }

    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"])
                ->orWhere('student_number', 'like', "%{$term}%")
                ->orWhere('section', 'like', "%{$term}%");
        });
    }

    private function buildPaymentHistory(Student $student, int $yearStart): array
    {
        $payments = $student->monthlyPayments
            ->keyBy(fn ($p) => $p->school_month->value.'-'.$p->year);

        return array_map(function (string $month) use ($yearStart, $payments) {
            $year    = in_array($month, self::NEXT_YEAR_MONTHS, true) ? $yearStart + 1 : $yearStart;
            $payment = $payments->get($month.'-'.$year);

            return [
                'month'       => $month,
                'month_label' => ucfirst($month),
                'year'        => $year,
                'status'      => $payment?->status ?? 'no_record',
            ];
        }, self::SCHOOL_MONTH_ORDER);
    }
}
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run the payment filter tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_payment_filter
vendor/bin/sail artisan test --compact --filter=test_export_accepts_payment_filter
```

Expected: 5 tests pass.

- [ ] **Step 6: Run full report test suite**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: all tests pass (retry up to 3× if SQLite database-locked error occurs — it is a transient environment issue, not a code bug).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Kitchen/StudentReportController.php tests/Feature/Reports/StudentReportTest.php
git commit -m "feat: add subscription payment status filter to student report"
```

---

### Task 2: Backend — Adaptive summary cards + replace old test

**Files:**
- Modify: `tests/Feature/Reports/StudentReportTest.php`

**Interfaces:**
- Consumes: the updated `StudentReportController` from Task 1 (already has `summaryBase` logic)
- Produces: `summary.total` reflects active filters; defaults to enrolled-only when no status filter

> **Note:** The controller already contains the adaptive summary implementation from Task 1's Step 3. This task only covers the test update.

---

- [ ] **Step 1: Delete the old test and add the replacement tests**

In `tests/Feature/Reports/StudentReportTest.php`, find and **delete** the method `test_summary_is_not_affected_by_search` (approximately lines 293–312 in the original file — it asserts `summary.total = 4` when search narrows to 3 results, which is now the WRONG behavior).

Then append these two tests before the closing brace:

```php
public function test_summary_total_reflects_active_search_filter(): void
{
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
    // summary.total = enrolled students matching search = 3 (not 4)
    $this->assertSame(3, $response->json('summary.total'));
}

public function test_summary_total_defaults_to_enrolled_only_when_no_status_filter(): void
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
    // total = enrolled only (3), NOT all students (4)
    $this->assertSame(3, $response->json('summary.total'));
}
```

- [ ] **Step 2: Run the two new tests — expect both to pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_summary_total
```

Expected: 2 tests pass.

- [ ] **Step 3: Run the full report suite to confirm no regressions**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: all tests pass (retry up to 3× for SQLite locking).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Reports/StudentReportTest.php
git commit -m "test: replace summary-unaffected-by-search test with adaptive summary tests"
```

---

### Task 3: Backend — Payment history in paginated response

**Files:**
- Modify: `tests/Feature/Reports/StudentReportTest.php`

**Interfaces:**
- Consumes: `buildPaymentHistory()` and scoped `monthlyPayments` eager load already present from Task 1
- Produces: each row in `data[]` has `payment_history: array|null`

> **Note:** The controller already includes `payment_history` from Task 1's Step 3. This task covers the tests for that behaviour.

---

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Reports/StudentReportTest.php`:

```php
public function test_subscription_student_row_includes_payment_history(): void
{
    $yearStart = now()->month >= 6 ? now()->year : now()->year - 1;

    $student = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    $student->monthlyPayments()->create([
        'school_month' => 'june',
        'year'         => $yearStart,
        'status'       => 'paid',
        'amount'       => 2970,
    ]);
    $student->monthlyPayments()->create([
        'school_month' => 'july',
        'year'         => $yearStart,
        'status'       => 'unpaid',
        'amount'       => 2970,
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk();
    $history = $response->json('data.0.payment_history');
    $this->assertIsArray($history);
    $this->assertCount(10, $history);

    $june = collect($history)->firstWhere('month', 'june');
    $july = collect($history)->firstWhere('month', 'july');
    $this->assertSame('paid',   $june['status']);
    $this->assertSame('unpaid', $july['status']);
    $this->assertSame('June',   $june['month_label']);
}

public function test_non_subscription_student_row_has_null_payment_history(): void
{
    Student::factory()->create([
        'branch_id'    => $this->branch->id,
        'student_type' => 'non_subscription',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk()
        ->assertJsonPath('data.0.payment_history', null);
}

public function test_payment_history_shows_no_record_for_months_without_payment(): void
{
    $student = Student::factory()->subscription()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
    ]);
    // No monthly payment records created at all

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk();
    $history = $response->json('data.0.payment_history');
    $this->assertIsArray($history);
    $this->assertCount(10, $history);

    foreach ($history as $entry) {
        $this->assertSame('no_record', $entry['status']);
    }
}
```

- [ ] **Step 2: Run tests — expect all 3 to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_subscription_student_row_includes_payment_history
vendor/bin/sail artisan test --compact --filter=test_non_subscription_student_row_has_null
vendor/bin/sail artisan test --compact --filter=test_payment_history_shows_no_record
```

Expected: All fail (payment_history key not found in response, since controller does NOT have it yet — we are verifying the tests would fail before implementation, but actually the controller from Task 1 already includes this. Run the tests — if they pass already, that's fine: the controller was implemented ahead in Task 1's full rewrite).

- [ ] **Step 3: Run full report suite**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Reports/StudentReportTest.php
git commit -m "test: add payment history response tests for student report"
```

---

### Task 4: Frontend — Update types in `lib/api/reports.ts`

**Files:**
- Modify: `lib/api/reports.ts` in `~/sunbites-pos`

**Interfaces:**
- Produces: `StudentReportRow.payment_history`, payment params on `reportApi.students()`

---

- [ ] **Step 1: Update `StudentReportRow` and the students API call**

In `~/sunbites-pos/lib/api/reports.ts`, replace the `StudentReportRow` interface:

```typescript
export interface PaymentHistoryEntry {
  month: string;
  month_label: string;
  year: number;
  status: "paid" | "unpaid" | "voided" | "no_record";
}

export interface StudentReportRow {
  id: number;
  full_name: string;
  student_number: string;
  grade_level: string;
  section: string | null;
  status: string;
  wallet_balance: number;
  total_spent: number;
  notes: string | null;
  allergies: string | null;
  payment_history: PaymentHistoryEntry[] | null;
}
```

No change needed to `reportApi.students()` — it already accepts `Record<string, string | number | undefined>` which covers the new payment params.

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-pos
git add lib/api/reports.ts
git commit -m "feat: add payment_history type to StudentReportRow"
```

---

### Task 5: Frontend — Payment sub-filter UI + Clear all filters button

**Files:**
- Modify: `app/(kitchen)/reports/students/page.tsx` in `~/sunbites-pos`

**Interfaces:**
- Consumes: `FilterPillGroup` (existing), `Select`/`SelectContent`/`SelectItem`/`SelectTrigger`/`SelectValue` from `@/components/ui/select`
- Produces: `paymentStatus`, `paymentFrom`, `paymentTo` state; Payment pill row; From/To selectors; Clear all button; payment params forwarded to API and export

---

- [ ] **Step 1: Add payment state, SCHOOL_MONTH_ORDER constant, and helper to page.tsx**

At the top of `~/sunbites-pos/app/(kitchen)/reports/students/page.tsx`, add to the import block:

```typescript
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { X } from "lucide-react";
```

Add this constant after the existing `TYPE_OPTIONS` constant (around line 50):

```typescript
const SCHOOL_MONTH_ORDER = [
  "june", "july", "august", "september", "october",
  "november", "december", "january", "february", "march",
] as const;

const MONTH_LABELS: Record<string, string> = {
  june: "June", july: "July", august: "August", september: "September",
  october: "October", november: "November", december: "December",
  january: "January", february: "February", march: "March",
};

const PAYMENT_OPTIONS = [
  { label: "Paid",   value: "paid" },
  { label: "Unpaid", value: "unpaid" },
  { label: "Void",   value: "voided" },
];
```

- [ ] **Step 2: Add payment state variables inside `StudentsReportPage`**

Inside the `StudentsReportPage` function, after the existing state declarations, add:

```typescript
const [paymentStatus, setPaymentStatus] = useState<string>("");
const [paymentFrom, setPaymentFrom]     = useState<string>("june");
const [paymentTo, setPaymentTo]         = useState<string>("march");
```

- [ ] **Step 3: Add reset logic when Type changes away from Subscription**

Replace the existing `handleFilterChange` function with:

```typescript
function handleFilterChange(setter: (v: string) => void, isType?: boolean) {
  return (v: string) => {
    setter(v);
    setPage(1);
    if (isType && v !== "subscription") {
      setPaymentStatus("");
      setPaymentFrom("june");
      setPaymentTo("march");
    }
  };
}
```

And update the `FilterPillGroup` for Type to pass `isType`:

```typescript
<FilterPillGroup
  label="Type"
  options={TYPE_OPTIONS}
  value={studentType}
  onChange={handleFilterChange(setStudentType, true)}
/>
```

- [ ] **Step 4: Add Payment pills + From/To selectors + Clear all button to the toolbar**

Add a `hasActiveFilters` const after the existing state:

```typescript
const hasActiveFilters =
  searchInput !== "" ||
  enrollmentStatus !== "" ||
  gradeLevel !== "" ||
  studentType !== "" ||
  paymentStatus !== "";
```

Add a `clearAllFilters` function:

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

function handlePaymentFrom(value: string) {
  setPaymentFrom(value);
  const fromIdx = SCHOOL_MONTH_ORDER.indexOf(value as typeof SCHOOL_MONTH_ORDER[number]);
  const toIdx   = SCHOOL_MONTH_ORDER.indexOf(paymentTo as typeof SCHOOL_MONTH_ORDER[number]);
  if (fromIdx > toIdx) setPaymentTo(value);
  setPage(1);
}
```

Update the search row to include the Clear all button:

```tsx
{/* Search + Filter toolbar */}
<div className="space-y-2.5">
  <div className="flex items-center gap-2">
    <div className="relative flex-1">
      <Search
        className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />
      <Input
        className="h-9 pl-9 text-sm"
        placeholder="Search by name, student number, or section..."
        value={searchInput}
        onChange={(e) => setSearchInput(e.target.value)}
        aria-label="Search students"
      />
    </div>
    {hasActiveFilters && (
      <Button
        variant="ghost"
        size="sm"
        onClick={clearAllFilters}
        className="shrink-0 text-muted-foreground hover:text-foreground"
      >
        <X className="mr-1.5 h-4 w-4" aria-hidden="true" />
        Clear filters
      </Button>
    )}
  </div>

  <FilterPillGroup
    label="Status"
    options={STATUS_OPTIONS}
    value={enrollmentStatus}
    onChange={handleFilterChange(setEnrollmentStatus)}
  />
  <FilterPillGroup
    label="Grade"
    options={GRADE_OPTIONS}
    value={gradeLevel}
    onChange={handleFilterChange(setGradeLevel)}
  />
  <FilterPillGroup
    label="Type"
    options={TYPE_OPTIONS}
    value={studentType}
    onChange={handleFilterChange(setStudentType, true)}
  />

  {studentType === "subscription" && (
    <div className="space-y-1.5">
      <FilterPillGroup
        label="Payment"
        options={PAYMENT_OPTIONS}
        value={paymentStatus}
        onChange={(v) => { setPaymentStatus(v); setPage(1); }}
      />
      {paymentStatus !== "" && (
        <div className="flex items-center gap-2 pl-16">
          <span className="text-xs text-muted-foreground">From</span>
          <Select value={paymentFrom} onValueChange={handlePaymentFrom}>
            <SelectTrigger className="h-8 w-32 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {SCHOOL_MONTH_ORDER.map((m) => (
                <SelectItem key={m} value={m} className="text-xs">
                  {MONTH_LABELS[m]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span className="text-xs text-muted-foreground">To</span>
          <Select value={paymentTo} onValueChange={(v) => { setPaymentTo(v); setPage(1); }}>
            <SelectTrigger className="h-8 w-32 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {SCHOOL_MONTH_ORDER.map((m) => (
                <SelectItem key={m} value={m} className="text-xs">
                  {MONTH_LABELS[m]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </div>
  )}
</div>
```

- [ ] **Step 5: Update `params` and export call to include payment params**

Update the `params` object (around line 121):

```typescript
const params = {
  search:          search || undefined,
  status:          enrollmentStatus || undefined,
  grade:           gradeLevel || undefined,
  type:            studentType || undefined,
  page,
  ...(studentType === "subscription" && paymentStatus
    ? {
        payment_status: paymentStatus,
        payment_from:   paymentFrom,
        payment_to:     paymentTo,
      }
    : {}),
};
```

Update the export button's `onClick`:

```typescript
onClick={() => {
  void exportReport("reports/students", {
    search:  search || undefined,
    status:  enrollmentStatus || undefined,
    grade:   gradeLevel || undefined,
    type:    studentType || undefined,
    ...(studentType === "subscription" && paymentStatus
      ? {
          payment_status: paymentStatus,
          payment_from:   paymentFrom,
          payment_to:     paymentTo,
        }
      : {}),
  });
}}
```

- [ ] **Step 6: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
cd ~/sunbites-pos
git add app/\(kitchen\)/reports/students/page.tsx
git commit -m "feat: add payment sub-filter pills, month range selectors, and clear all button"
```

---

### Task 6: Frontend — Adaptive card label + payment history in expanded row

**Files:**
- Modify: `app/(kitchen)/reports/students/page.tsx` in `~/sunbites-pos`

**Interfaces:**
- Consumes: `PaymentHistoryEntry` from Task 4; `hasActiveFilters` from Task 5; `row.payment_history` from Task 4

---

- [ ] **Step 1: Update the summary card label**

Find the "Total Enrolled" card text in `StudentsReportPage` (inside the `{summary && (...)}` block). Replace the static label:

```tsx
{/* Before: */}
<p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
  Total Enrolled
</p>

{/* After: */}
<p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
  {hasActiveFilters ? "Matching Students" : "Total Enrolled"}
</p>
```

- [ ] **Step 2: Add payment history section to the expanded row panel**

Find the expanded row `<td>` content (inside the `expandedRowId === row.id` section). Currently it renders a 2-column Allergies/Notes grid. Add the payment history section after it:

```tsx
<tr key={`${row.id}-detail`} className="bg-muted/10">
  <td colSpan={8} className="px-6 py-4">
    <div className="space-y-4">
      {/* Allergies + Notes */}
      {row.notes || row.allergies ? (
        <div className="grid grid-cols-2 gap-4">
          <div className="rounded-lg border bg-card p-3">
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Allergies
            </p>
            <p className="text-sm text-foreground">
              {row.allergies ?? "None recorded"}
            </p>
          </div>
          <div className="rounded-lg border bg-card p-3">
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Notes
            </p>
            <p className="text-sm text-foreground">
              {row.notes ?? "None recorded"}
            </p>
          </div>
        </div>
      ) : (
        <p className="text-center text-sm text-muted-foreground">
          No notes or allergies recorded for this student.
        </p>
      )}

      {/* Payment history — subscription students only */}
      {row.payment_history !== null && (
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Payment History
          </p>
          <div className="flex flex-wrap gap-1.5">
            {row.payment_history.map((entry) => (
              <div
                key={`${entry.month}-${entry.year}`}
                className={cn(
                  "flex flex-col items-center rounded-md border px-2.5 py-1.5 text-center",
                  entry.status === "paid"      && "border-green-300 bg-green-50 text-green-700",
                  entry.status === "unpaid"    && "border-red-300 bg-red-50 text-red-700",
                  entry.status === "voided"    && "border-border bg-muted text-muted-foreground line-through",
                  entry.status === "no_record" && "border-border bg-muted/40 text-muted-foreground",
                )}
              >
                <span className="text-[10px] font-semibold">
                  {entry.month_label.slice(0, 3)}
                </span>
                <span className="text-[10px]">
                  {entry.status === "paid"      ? "✓" : ""}
                  {entry.status === "unpaid"    ? "✗" : ""}
                  {entry.status === "voided"    ? "—" : ""}
                  {entry.status === "no_record" ? "–" : ""}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  </td>
</tr>
```

- [ ] **Step 3: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos
git add app/\(kitchen\)/reports/students/page.tsx
git commit -m "feat: adaptive card label and payment history panel in expanded student row"
```

---

## Self-Review Checklist

- [x] Payment filter: Paid/Unpaid/Void pills appear when Subscription type active — Task 5 ✓
- [x] From/To dropdowns appear only when Paid/Unpaid/Void is active (not All) — Task 5 ✓
- [x] From after To resets To to match From — Task 5 `handlePaymentFrom` ✓
- [x] Type reset clears payment state — Task 5 `handleFilterChange(setter, isType=true)` ✓
- [x] Payment filter applied to table query — Task 1 ✓
- [x] Payment filter applied to export — Task 1 ✓
- [x] Payment filter NOT applied when type ≠ subscription — Task 1 (`$applyPayment` condition) ✓
- [x] `paid`: chained `whereHas` per month (all must be paid) — Task 1 ✓
- [x] `unpaid`/`voided`: single `whereHas` with `status` match — Task 1 ✓
- [x] `monthsInRange` guard against inverted range — Task 1 ✓
- [x] School year derivation matches BillingReportController — Task 1 ✓
- [x] NEXT_YEAR_MONTHS (Jan/Feb/Mar) → year = schoolYearStart + 1 — Task 1 ✓
- [x] Summary total: enrolled default when no status filter — Task 1 + Task 2 ✓
- [x] Summary total: respects explicit status filter — Task 1 `summaryBase` ✓
- [x] Old `test_summary_is_not_affected_by_search` deleted — Task 2 ✓
- [x] `payment_history`: 10-entry array for subscription, null for non-subscription — Task 3 ✓
- [x] `monthlyPayments` eager load scoped to current school year — Task 1 ✓
- [x] `no_record` status when no payment row exists for that month — Task 1 `buildPaymentHistory` ✓
- [x] Clear all button: appears when any filter active — Task 5 ✓
- [x] Clear all resets all state including payment — Task 5 ✓
- [x] Card label "Matching Students" / "Total Enrolled" — Task 6 ✓
- [x] Payment history panel: colored status cells, 3-letter abbreviations — Task 6 ✓
- [x] Payment history hidden for non-subscription (null check) — Task 6 ✓
- [x] Export respects payment params — Task 5 ✓
