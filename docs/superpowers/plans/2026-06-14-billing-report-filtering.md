# Billing Report Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `search`, `recorded_by`, `recorded_from`/`recorded_to` filters to the billing report API and default the response to the current school month/year when no period params are provided.

**Architecture:** Extract a private `buildQuery(array $validated): Builder` method to replace the three duplicated query builds across `index()` and `export()`. All filter logic lives in `buildQuery()` once. `index()` and `export()` layer on eager loading, ordering, and pagination. Defaults for `school_month` and `year` are applied before `buildQuery()` is called.

**Tech Stack:** Laravel 13, Eloquent, `SchoolMonth` enum (`App\Enums\SchoolMonth`), PHPUnit 12, Sail.

---

## Files

| File | Change |
|---|---|
| `app/Http/Controllers/Kitchen/BillingReportController.php` | Extract `buildQuery()`, add all new filters, add defaults |
| `tests/Feature/Reports/BillingReportTest.php` | Update existing test URLs to be explicit, add new test cases |

---

## Task 1: Extract `buildQuery()` — pure refactor, no behaviour change

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`

- [ ] **Step 1: Replace the controller with the refactored version**

Replace the entire file content with:

```php
<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Exports\BillingReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BillingReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schoolMonthValues = collect(SchoolMonth::cases())->map->value->toArray();

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string', Rule::in($schoolMonthValues)],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
            'grade_level' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $validated['year'] = $validated['year'] ?? now()->year;
        $perPage = $validated['per_page'] ?? 50;

        $query = $this->buildQuery($validated)
            ->with(['student', 'recorder'])
            ->orderByRaw("CASE WHEN status = 'unpaid' THEN 0 ELSE 1 END")
            ->orderBy(
                Student::select('last_name')->whereColumn('students.id', 'student_monthly_payments.student_id')->limit(1),
            );

        $summaryQuery = $this->buildQuery($validated);

        $totalSubscribers = (clone $summaryQuery)->distinct('student_id')->count('student_id');
        $totalCollected = (float) (clone $summaryQuery)->where('status', 'paid')->sum('amount');
        $totalOutstanding = (float) (clone $summaryQuery)->where('status', 'unpaid')->sum('amount');
        $totalAmount = $totalCollected + $totalOutstanding;
        $collectionRate = $totalAmount > 0 ? round(($totalCollected / $totalAmount) * 100, 2) : 0;

        $payments = $query->paginate($perPage);

        collect($payments->items())->each(function ($payment): void {
            $payment->student?->append('full_name');
            $payment->recorder?->append('full_name');
        });

        return response()->json([
            'data' => $payments->items(),
            'meta' => $this->paginationMeta($payments),
            'summary' => [
                'total_subscribers' => $totalSubscribers,
                'total_collected' => $totalCollected,
                'total_outstanding' => $totalOutstanding,
                'collection_rate' => $collectionRate,
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $schoolMonthValues = collect(SchoolMonth::cases())->map->value->toArray();

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string', Rule::in($schoolMonthValues)],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
            'grade_level' => ['nullable', 'string'],
        ]);

        $validated['year'] = $validated['year'] ?? now()->year;
        $branch = app('active_branch');
        $year = $validated['year'];

        $payments = $this->buildQuery($validated)
            ->with(['student', 'recorder'])
            ->orderByRaw("CASE WHEN status = 'unpaid' THEN 0 ELSE 1 END")
            ->get();

        $totalCollected = (float) $payments->where('status', 'paid')->sum('amount');
        $totalOutstanding = (float) $payments->where('status', 'unpaid')->sum('amount');
        $totalAmount = $totalCollected + $totalOutstanding;
        $collectionRate = $totalAmount > 0 ? round(($totalCollected / $totalAmount) * 100, 2) : 0;

        $summary = [
            'total_subscribers' => $payments->unique('student_id')->count(),
            'total_collected' => $totalCollected,
            'total_outstanding' => $totalOutstanding,
            'collection_rate' => $collectionRate,
        ];

        $filename = "billing-report-{$branch->slug}-{$year}.xlsx";

        return Excel::download(new BillingReportExport($payments, $summary), $filename);
    }

    private function buildQuery(array $validated): Builder
    {
        $branchId = app('active_branch')->id;
        $studentIds = Student::where('branch_id', $branchId)->pluck('id');

        return StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where('year', $validated['year'])
            ->when(isset($validated['school_month']), fn ($q) => $q->where('school_month', $validated['school_month']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])));
    }
}
```

- [ ] **Step 2: Run existing tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/BillingReportTest.php
```

Expected: all tests pass (this is a pure refactor).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php
git commit -m "refactor: extract buildQuery() in BillingReportController"
```

---

## Task 2: Default `school_month` and `year` to current school period

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`
- Modify: `tests/Feature/Reports/BillingReportTest.php`

- [ ] **Step 1: Write the failing test**

Add this test to `BillingReportTest` (after `test_billing_report_includes_student_full_name`):

```php
public function test_default_school_month_and_year_filters_to_current_school_period(): void
{
    $student = Student::factory()->create(['branch_id' => $this->branch->id]);

    // Current period payment — should appear
    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => strtolower(now()->format('F')),
        'year' => now()->month >= 6 ? now()->year : now()->year - 1,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);

    // Different month payment — must NOT appear
    $otherMonth = now()->month === 6 ? 'july' : 'june';
    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => $otherMonth,
        'year' => now()->month >= 6 ? now()->year : now()->year - 1,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/billing');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}
```

- [ ] **Step 2: Run the test — expect it to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_default_school_month_and_year_filters_to_current_school_period
```

Expected: FAIL — without defaults, both months are returned (count is 2).

- [ ] **Step 3: Add defaults to `index()` and `export()`, and update existing test URLs**

In `index()`, replace:
```php
$validated['year'] = $validated['year'] ?? now()->year;
```
with:
```php
$validated['school_month'] = $validated['school_month'] ?? SchoolMonth::fromMonthNumber(now()->month)?->value;
$validated['year'] = $validated['year'] ?? (now()->month >= 6 ? now()->year : now()->year - 1);
```

In `export()`, replace:
```php
$validated['year'] = $validated['year'] ?? now()->year;
```
with:
```php
$validated['school_month'] = $validated['school_month'] ?? SchoolMonth::fromMonthNumber(now()->month)?->value;
$validated['year'] = $validated['year'] ?? (now()->month >= 6 ? now()->year : now()->year - 1);
```

Now update the existing test URLs so they are explicit and do not rely on the current month. In `BillingReportTest`, update these methods:

```php
// test_billing_report_returns_paginated_payments
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

// test_billing_report_includes_student_full_name
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

// test_unpaid_records_appear_first
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

// test_status_filter_returns_only_paid
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&status=paid");

// test_summary_collection_rate_is_calculated
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

// test_grade_level_filter_works
$year = now()->year;
$response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&grade_level=Grade+1");
```

- [ ] **Step 4: Run all billing tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/BillingReportTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php tests/Feature/Reports/BillingReportTest.php
git commit -m "feat: default billing report to current school month and year"
```

---

## Task 3: Add `search` filter (student name / number)

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`
- Modify: `tests/Feature/Reports/BillingReportTest.php`

- [ ] **Step 1: Write three failing tests**

Add to `BillingReportTest`:

```php
public function test_search_by_first_name_returns_matching_students(): void
{
    $year = now()->year;

    $maria = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);
    $pedro = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'first_name' => 'Pedro',
        'last_name' => 'Reyes',
    ]);

    StudentMonthlyPayment::create([
        'student_id' => $maria->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);
    StudentMonthlyPayment::create([
        'student_id' => $pedro->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);

    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=maria");

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame('Maria Santos', $response->json('data.0.student.full_name'));
}

public function test_search_by_student_number_returns_matching_student(): void
{
    $year = now()->year;

    $target = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'student_number' => 'STU-2026-001',
    ]);
    $other = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'student_number' => 'STU-2026-002',
    ]);

    StudentMonthlyPayment::create([
        'student_id' => $target->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);
    StudentMonthlyPayment::create([
        'student_id' => $other->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);

    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=STU-2026-001");

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}

public function test_search_with_no_match_returns_empty_data(): void
{
    $year = now()->year;
    $student = Student::factory()->create(['branch_id' => $this->branch->id]);
    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'unpaid',
        'amount' => 800.00,
    ]);

    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=doesnotexist");

    $response->assertOk();
    $this->assertCount(0, $response->json('data'));
}
```

- [ ] **Step 2: Run the tests — expect them to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_search
```

Expected: all three fail — search param is unknown and is ignored.

- [ ] **Step 3: Add `search` to validation and `buildQuery()`**

In `index()`, add to the `$request->validate([...])` array:
```php
'search' => ['nullable', 'string', 'max:100'],
```

In `export()`, add the same line to its validate array.

In `buildQuery()`, add after the `grade_level` `when()`:
```php
->when(isset($validated['search']), function ($q) use ($validated) {
    $like = '%'.mb_strtolower($validated['search']).'%';
    $q->whereHas('student', fn ($sq) => $sq
        ->whereRaw('lower(first_name) like ?', [$like])
        ->orWhereRaw('lower(last_name) like ?', [$like])
        ->orWhereRaw('lower(student_number) like ?', [$like])
    );
})
```

- [ ] **Step 4: Run the tests — expect them to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/BillingReportTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php tests/Feature/Reports/BillingReportTest.php
git commit -m "feat: add search filter to billing report"
```

---

## Task 4: Add `recorded_by` filter

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`
- Modify: `tests/Feature/Reports/BillingReportTest.php`

- [ ] **Step 1: Write the failing test**

Add to `BillingReportTest`:

```php
public function test_recorded_by_filter_returns_only_payments_by_that_staff_member(): void
{
    $year = now()->year;

    $student1 = Student::factory()->create(['branch_id' => $this->branch->id]);
    $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

    StudentMonthlyPayment::create([
        'student_id' => $student1->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'paid',
        'amount' => 800.00,
        'recorded_at' => now(),
        'recorded_by' => $this->admin->id,
    ]);
    StudentMonthlyPayment::create([
        'student_id' => $student2->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'paid',
        'amount' => 800.00,
        'recorded_at' => now(),
        'recorded_by' => $this->manager->id,
    ]);

    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_by={$this->admin->id}");

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}
```

- [ ] **Step 2: Run the test — expect it to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_recorded_by_filter
```

Expected: FAIL — both payments are returned because `recorded_by` param is ignored.

- [ ] **Step 3: Add `recorded_by` to validation and `buildQuery()`**

In `index()`, add to the validate array:
```php
'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
```

In `export()`, add the same line.

In `buildQuery()`, add after the `search` `when()`:
```php
->when(isset($validated['recorded_by']), fn ($q) => $q->where('recorded_by', $validated['recorded_by']))
```

- [ ] **Step 4: Run all billing tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/BillingReportTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php tests/Feature/Reports/BillingReportTest.php
git commit -m "feat: add recorded_by filter to billing report"
```

---

## Task 5: Add `recorded_from` / `recorded_to` filters

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`
- Modify: `tests/Feature/Reports/BillingReportTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `BillingReportTest`:

```php
public function test_recorded_from_excludes_payments_recorded_before_date(): void
{
    $year = now()->year;
    $student = Student::factory()->create(['branch_id' => $this->branch->id]);

    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'paid',
        'amount' => 800.00,
        'recorded_at' => now()->subDays(3),
        'recorded_by' => $this->admin->id,
    ]);

    $from = now()->subDay()->toDateString();
    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_from={$from}");

    $response->assertOk();
    $this->assertCount(0, $response->json('data'));
}

public function test_recorded_to_excludes_payments_recorded_after_date(): void
{
    $year = now()->year;
    $student = Student::factory()->create(['branch_id' => $this->branch->id]);

    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'paid',
        'amount' => 800.00,
        'recorded_at' => now(),
        'recorded_by' => $this->admin->id,
    ]);

    $to = now()->subDay()->toDateString();
    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_to={$to}");

    $response->assertOk();
    $this->assertCount(0, $response->json('data'));
}

public function test_recorded_from_and_to_together_return_payments_within_range(): void
{
    $year = now()->year;
    $student = Student::factory()->create(['branch_id' => $this->branch->id]);

    // Inside range
    StudentMonthlyPayment::create([
        'student_id' => $student->id,
        'school_month' => 'june',
        'year' => $year,
        'status' => 'paid',
        'amount' => 800.00,
        'recorded_at' => now(),
        'recorded_by' => $this->admin->id,
    ]);

    $from = now()->subDay()->toDateString();
    $to = now()->toDateString();
    $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_from={$from}&recorded_to={$to}");

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}
```

- [ ] **Step 2: Run the tests — expect them to fail**

```bash
vendor/bin/sail artisan test --compact --filter=test_recorded_from
vendor/bin/sail artisan test --compact --filter=test_recorded_to
```

Expected: all fail — date params are ignored so the wrong counts are returned.

- [ ] **Step 3: Add `recorded_from`/`recorded_to` to validation and `buildQuery()`**

In `index()`, add to the validate array:
```php
'recorded_from' => ['nullable', 'date'],
'recorded_to' => ['nullable', 'date'],
```

In `export()`, add the same two lines.

In `buildQuery()`, add after the `recorded_by` `when()`:
```php
->when(isset($validated['recorded_from']), fn ($q) => $q->whereDate('recorded_at', '>=', $validated['recorded_from']))
->when(isset($validated['recorded_to']), fn ($q) => $q->whereDate('recorded_at', '<=', $validated['recorded_to']))
```

- [ ] **Step 4: Run all billing tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/BillingReportTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php tests/Feature/Reports/BillingReportTest.php
git commit -m "feat: add recorded_from and recorded_to filters to billing report"
```

---

## Task 6: Final verification

- [ ] **Step 1: Run the full test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: all tests pass with no regressions.

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

Expected: no formatting issues (or auto-fixed).

- [ ] **Step 3: Commit any Pint fixes if needed**

```bash
git add app/Http/Controllers/Kitchen/BillingReportController.php
git commit -m "style: apply pint formatting to billing report controller"
```
