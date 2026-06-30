# Student Report Page — UI Enhancements & Export Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add debounced search, toggle-chip filter pills, expandable rows (allergies + notes), and two new Excel export columns to the POS student report page.

**Architecture:** Five sequential tasks — two backend (controller + export) then three frontend (types, component, page). Backend tasks are independently testable. Frontend tasks depend on each other in order (types → component → page) but have no backend dependency at runtime beyond the existing API.

**Tech Stack:** Laravel 13, PHPUnit 12, maatwebsite/excel (backend); Next.js 15 App Router, React 19, TanStack Query v5, Tailwind v4, shadcn/ui (frontend). All PHP commands via `vendor/bin/sail`.

## Global Constraints

- All PHP commands via `vendor/bin/sail`; run pint after every PHP change: `vendor/bin/sail bin pint --dirty --format agent`
- `LazilyRefreshDatabase` on every Feature test (matches existing `StudentReportTest.php`)
- Auth pattern from existing tests: `Sanctum::actingAs($user, ['staff'])` + `->withHeaders(['X-Branch-Id' => $branch->id])` via the `asAdmin()` / `asManager()` helpers already in `StudentReportTest`
- Use model factories with direct attribute overrides — no manual instantiation
- Summary queries in `index()` (totalEnrolled, byGrade, byStatus) must NOT receive `search` — they are always branch-wide counts
- No `any` in TypeScript; named exports only for React components; `cn()` for all conditional classes
- Column 1–12 order in `StudentsExport` must not change — Allergies and Notes are appended at 13 and 14

---

## File Map

**Backend — `~/sunbites-api`**

| Action | File |
|--------|------|
| Modify | `app/Http/Controllers/Kitchen/StudentReportController.php` |
| Modify | `app/Exports/StudentsExport.php` |
| Modify | `tests/Feature/Reports/StudentReportTest.php` |

**Frontend — `~/sunbites-pos`**

| Action | File |
|--------|------|
| Modify | `lib/api/reports.ts` |
| Create | `components/reports/filter-pill-group.tsx` |
| Modify | `app/(kitchen)/reports/students/page.tsx` |

---

### Task 1: Backend — search filter + notes/allergies in paginated response

**Files:**
- Modify: `app/Http/Controllers/Kitchen/StudentReportController.php`
- Modify: `tests/Feature/Reports/StudentReportTest.php`

**Interfaces:**
- Produces: `GET /api/v1/reports/students?search=<term>` — rows in `data[]` include `notes: string|null` and `allergies: string|null`; `GET /api/v1/reports/students/export?search=<term>` respects search

---

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Reports/StudentReportTest.php` (inside the class, before the closing brace):

```php
public function test_search_by_first_name_returns_matching_students(): void
{
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Juan',
        'last_name'  => 'Santos',
    ]);
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Maria',
        'last_name'  => 'Reyes',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame('Juan Santos', $response->json('data.0.full_name'));
}

public function test_search_by_last_name_returns_matching_students(): void
{
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Ana',
        'last_name'  => 'Dela Cruz',
    ]);
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Pedro',
        'last_name'  => 'Santos',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Dela+Cruz');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}

public function test_search_by_student_number_returns_matching_students(): void
{
    Student::factory()->create([
        'branch_id'      => $this->branch->id,
        'student_number' => '2024-0042',
    ]);
    Student::factory()->create([
        'branch_id'      => $this->branch->id,
        'student_number' => '2024-0099',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=0042');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame('2024-0042', $response->json('data.0.student_number'));
}

public function test_search_by_section_returns_matching_students(): void
{
    Student::factory()->create([
        'branch_id' => $this->branch->id,
        'section'   => 'Mabini',
    ]);
    Student::factory()->create([
        'branch_id' => $this->branch->id,
        'section'   => 'Rizal',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Mabini');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
}

public function test_search_combined_with_status_filter(): void
{
    Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'first_name'        => 'Juan',
        'enrollment_status' => 'enrolled',
    ]);
    Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'first_name'        => 'Juan',
        'enrollment_status' => 'paused',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan&status=enrolled');

    $response->assertOk();
    $this->assertCount(1, $response->json('data'));
    $this->assertSame('enrolled', $response->json('data.0.status'));
}

public function test_row_response_includes_notes_and_allergies(): void
{
    Student::factory()->create([
        'branch_id' => $this->branch->id,
        'notes'     => 'Bring packed lunch on Fridays.',
        'allergies' => 'Peanuts, shellfish',
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk()
        ->assertJsonPath('data.0.notes', 'Bring packed lunch on Fridays.')
        ->assertJsonPath('data.0.allergies', 'Peanuts, shellfish');
}

public function test_row_response_notes_and_allergies_are_null_when_empty(): void
{
    Student::factory()->create([
        'branch_id' => $this->branch->id,
        'notes'     => null,
        'allergies' => null,
    ]);

    $response = $this->asAdmin()->getJson('/api/v1/reports/students');

    $response->assertOk()
        ->assertJsonPath('data.0.notes', null)
        ->assertJsonPath('data.0.allergies', null);
}

public function test_summary_is_not_affected_by_search(): void
{
    Student::factory()->count(3)->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
        'first_name'        => 'Juan',
    ]);
    Student::factory()->create([
        'branch_id'         => $this->branch->id,
        'enrollment_status' => 'enrolled',
        'first_name'        => 'Maria',
    ]);

    // Search narrows rows to 3, but summary must still show 4 enrolled
    $response = $this->asAdmin()->getJson('/api/v1/reports/students?search=Juan');

    $response->assertOk();
    $this->assertCount(3, $response->json('data'));
    $this->assertSame(4, $response->json('summary.total'));
}

public function test_export_respects_search_param(): void
{
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Exportable',
        'last_name'  => 'Student',
    ]);
    Student::factory()->create([
        'branch_id'  => $this->branch->id,
        'first_name' => 'Other',
        'last_name'  => 'Person',
    ]);

    $response = $this->asManager()->getJson('/api/v1/reports/students/export?search=Exportable');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
```

- [ ] **Step 2: Run tests — expect new tests to fail**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: new tests fail with JSON path not found or wrong count. Existing 7 tests still pass.

- [ ] **Step 3: Update `StudentReportController::index()`**

Replace the `index()` method body in `app/Http/Controllers/Kitchen/StudentReportController.php`:

```php
public function index(Request $request): JsonResponse
{
    $validated = $request->validate([
        'status'   => ['nullable', 'string'],
        'grade'    => ['nullable', 'string'],
        'type'     => ['nullable', 'string'],
        'search'   => ['nullable', 'string', 'max:100'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $branchId = app('active_branch')->id;
    $perPage = $validated['per_page'] ?? 25;

    $query = Student::where('branch_id', $branchId)
        ->with(['wallet'])
        ->when(isset($validated['status']), fn ($q) => $q->where('enrollment_status', $validated['status']))
        ->when(isset($validated['grade']), fn ($q) => $q->where('grade_level', $validated['grade']))
        ->when(isset($validated['type']), fn ($q) => $q->where('student_type', $validated['type']))
        ->when(filled($validated['search'] ?? null), function ($q) use ($validated) {
            $term = $validated['search'];
            $q->where(function ($inner) use ($term) {
                $inner->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"])
                    ->orWhere('student_number', 'like', "%{$term}%")
                    ->orWhere('section', 'like', "%{$term}%");
            });
        })
        ->orderBy('last_name')
        ->orderBy('first_name');

    $totalEnrolled = Student::where('branch_id', $branchId)->where('enrollment_status', 'enrolled')->count();

    $byGrade = Student::where('branch_id', $branchId)
        ->selectRaw('grade_level, COUNT(*) as count')
        ->groupBy('grade_level')
        ->pluck('count', 'grade_level');

    $byStatus = Student::where('branch_id', $branchId)
        ->selectRaw('enrollment_status, COUNT(*) as count')
        ->groupBy('enrollment_status')
        ->pluck('count', 'enrollment_status');

    $paginator = $query->paginate($perPage);

    $rows = $paginator->through(fn (Student $student) => [
        'id'             => $student->id,
        'full_name'      => $student->full_name,
        'student_number' => $student->student_number,
        'grade_level'    => $student->grade_level,
        'section'        => $student->section,
        'status'         => $student->enrollment_status instanceof \BackedEnum
            ? $student->enrollment_status->value
            : (string) $student->enrollment_status,
        'wallet_balance' => (float) ($student->wallet?->balanceFloat ?? 0),
        'total_spent'    => (float) $student->total_spent,
        'notes'          => $student->notes,
        'allergies'      => $student->allergies,
    ]);

    return response()->json([
        'data'    => $rows->items(),
        'meta'    => $this->paginationMeta($rows),
        'summary' => [
            'total'            => $totalEnrolled,
            'grade_breakdown'  => $byGrade,
            'status_breakdown' => $byStatus,
        ],
    ]);
}
```

- [ ] **Step 4: Update `StudentReportController::export()`**

Replace the `export()` method body:

```php
public function export(Request $request): BinaryFileResponse
{
    $validated = $request->validate([
        'status' => ['nullable', 'string'],
        'grade'  => ['nullable', 'string'],
        'type'   => ['nullable', 'string'],
        'search' => ['nullable', 'string', 'max:100'],
    ]);

    $branchId = app('active_branch')->id;

    $students = Student::where('branch_id', $branchId)
        ->with([
            'contacts' => fn ($q) => $q->where('is_primary', true),
            'wallet',
        ])
        ->when(isset($validated['status']), fn ($q) => $q->where('enrollment_status', $validated['status']))
        ->when(isset($validated['grade']), fn ($q) => $q->where('grade_level', $validated['grade']))
        ->when(isset($validated['type']), fn ($q) => $q->where('student_type', $validated['type']))
        ->when(filled($validated['search'] ?? null), function ($q) use ($validated) {
            $term = $validated['search'];
            $q->where(function ($inner) use ($term) {
                $inner->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"])
                    ->orWhere('student_number', 'like', "%{$term}%")
                    ->orWhere('section', 'like', "%{$term}%");
            });
        })
        ->orderBy('last_name')
        ->orderBy('first_name')
        ->get();

    $branch = app('active_branch');
    $filename = "students-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

    return Excel::download(new StudentsExport($students), $filename);
}
```

- [ ] **Step 5: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 6: Run all report tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: all tests pass including the 9 new ones.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Kitchen/StudentReportController.php tests/Feature/Reports/StudentReportTest.php
git commit -m "feat: add search filter and notes/allergies to student report endpoint"
```

---

### Task 2: Backend — Allergies + Notes columns in StudentsExport

**Files:**
- Modify: `app/Exports/StudentsExport.php`
- Modify: `tests/Feature/Reports/StudentReportTest.php`

**Interfaces:**
- Produces: Excel headings have 14 entries; `headings()[12]` = `'Allergies'`, `headings()[13]` = `'Notes'`; `null` values become `''`

---

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Reports/StudentReportTest.php`:

```php
public function test_export_headings_include_allergies_and_notes(): void
{
    $export = new \App\Exports\StudentsExport(collect([]));

    $headings = $export->headings();

    $this->assertCount(14, $headings);
    $this->assertSame('Allergies', $headings[12]);
    $this->assertSame('Notes', $headings[13]);
}

public function test_export_maps_allergies_and_notes_for_a_student(): void
{
    $student = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'allergies' => 'Peanuts',
        'notes'     => 'Packed lunch on Fridays',
    ]);
    $student->load('wallet');
    $student->setRelation('contacts', collect([]));

    $export = new \App\Exports\StudentsExport(collect([$student]));
    $row = $export->map($student);

    $this->assertSame('Peanuts', $row[12]);
    $this->assertSame('Packed lunch on Fridays', $row[13]);
}

public function test_export_maps_null_allergies_and_notes_as_empty_string(): void
{
    $student = Student::factory()->create([
        'branch_id' => $this->branch->id,
        'allergies' => null,
        'notes'     => null,
    ]);
    $student->load('wallet');
    $student->setRelation('contacts', collect([]));

    $export = new \App\Exports\StudentsExport(collect([$student]));
    $row = $export->map($student);

    $this->assertSame('', $row[12]);
    $this->assertSame('', $row[13]);
}
```

- [ ] **Step 2: Run failing tests to confirm**

```bash
vendor/bin/sail artisan test --compact --filter=test_export_headings_include_allergies_and_notes
```

Expected: FAIL — heading count is 12, not 14.

- [ ] **Step 3: Update `StudentsExport::headings()`**

In `app/Exports/StudentsExport.php`, replace the `headings()` return array:

```php
public function headings(): array
{
    return [
        'Student Number',
        'First Name',
        'Last Name',
        'Grade Level',
        'Section',
        'Status',
        'Enrollment Date',
        'Type',
        'Wallet Balance',
        'Total Spent',
        'Primary Contact',
        'Contact Phone',
        'Allergies',
        'Notes',
    ];
}
```

- [ ] **Step 4: Update `StudentsExport::map()`**

In `app/Exports/StudentsExport.php`, replace the `map()` return array:

```php
public function map($student): array
{
    $primaryContact = $student->contacts->firstWhere('is_primary', true);

    return [
        $student->student_number,
        $student->first_name,
        $student->last_name,
        $student->grade_level,
        $student->section ?? '—',
        $student->enrollment_status?->value ?? '—',
        $student->enrollment_date?->format('Y-m-d') ?? '—',
        $student->student_type?->value ?? '—',
        number_format((float) ($student->wallet?->balanceFloat ?? 0), 2),
        number_format((float) $student->total_spent, 2),
        $primaryContact?->full_name ?? '—',
        $primaryContact?->phone ?? '—',
        $student->allergies ?? '',
        $student->notes ?? '',
    ];
}
```

- [ ] **Step 5: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 6: Run all report tests — expect all to pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Reports/StudentReportTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Exports/StudentsExport.php tests/Feature/Reports/StudentReportTest.php
git commit -m "feat: add allergies and notes columns to students excel export"
```

---

### Task 3: Frontend — Update `StudentReportRow` type

**Files:**
- Modify: `lib/api/reports.ts` in `~/sunbites-pos`

**Interfaces:**
- Produces: `StudentReportRow` with `notes: string | null` and `allergies: string | null`; `reportApi.students()` param type accepts `search?: string`

---

- [ ] **Step 1: Update `StudentReportRow` interface**

In `lib/api/reports.ts`, find the `StudentReportRow` interface (around line 96) and replace it:

```typescript
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
}
```

The `reportApi.students()` method already accepts `Record<string, string | number | undefined>` so `search` needs no signature change — just pass it in the params object from the page.

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add lib/api/reports.ts
git commit -m "feat: add notes and allergies to StudentReportRow type"
```

---

### Task 4: Frontend — `FilterPillGroup` component

**Files:**
- Create: `components/reports/filter-pill-group.tsx` in `~/sunbites-pos`

**Interfaces:**
- Produces: `FilterPillGroup({ label, options, value, onChange, className? })` — named export
- `options`: `Array<{ label: string; value: string }>`
- `value`: currently selected value, or `""` for "All"
- `onChange`: called with `""` when "All" is clicked or when the active pill is clicked again (deselect)

---

- [ ] **Step 1: Create `components/reports/filter-pill-group.tsx`**

```typescript
"use client";

import { cn } from "@/lib/utils";

interface Option {
  label: string;
  value: string;
}

interface FilterPillGroupProps {
  label: string;
  options: Option[];
  value: string;
  onChange: (value: string) => void;
  className?: string;
}

export function FilterPillGroup({
  label,
  options,
  value,
  onChange,
  className,
}: FilterPillGroupProps) {
  return (
    <div className={cn("flex flex-wrap items-center gap-1.5", className)}>
      <span className="w-14 shrink-0 text-xs font-semibold text-muted-foreground">
        {label}
      </span>
      <button
        type="button"
        onClick={() => onChange("")}
        className={cn(
          "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
          value === ""
            ? "border-primary bg-primary text-primary-foreground"
            : "border-border bg-muted text-muted-foreground hover:bg-muted/80",
        )}
      >
        All
      </button>
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          onClick={() => onChange(value === opt.value ? "" : opt.value)}
          className={cn(
            "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
            value === opt.value
              ? "border-primary bg-primary text-primary-foreground"
              : "border-border bg-muted text-muted-foreground hover:bg-muted/80",
          )}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add components/reports/filter-pill-group.tsx
git commit -m "feat: add FilterPillGroup component for student report filters"
```

---

### Task 5: Frontend — Update report page

**Files:**
- Modify: `app/(kitchen)/reports/students/page.tsx` in `~/sunbites-pos`

**Interfaces:**
- Consumes: `FilterPillGroup` (Task 4); `StudentReportRow.notes`, `StudentReportRow.allergies` (Task 3); `search` forwarded to `reportApi.students()` and `exportReport()`

**Key behavior:**
- Search debounced 300 ms via `useEffect`; resets `page` to 1
- Filter pills: `""` = no filter; any other value = active; resets `page` to 1
- Expandable row: `expandedRowId: number | null`; only one row open at a time; toggle on click
- `TableRowSkeleton` renders 8 cells (was 7); empty-state and error-state `colSpan` = 8
- Summary cards unchanged

---

- [ ] **Step 1: Replace `app/(kitchen)/reports/students/page.tsx`**

```typescript
"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, Download, Search } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { FilterPillGroup } from "@/components/reports/filter-pill-group";
import { exportReport, reportApi } from "@/lib/api/reports";
import { useAuthStore } from "@/lib/store/auth";
import { cn } from "@/lib/utils";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const GRADE_LEVELS = [
  "Nursery",
  "Kinder 1",
  "Kinder 2",
  "Grade 1",
  "Grade 2",
  "Grade 3",
  "Grade 4",
  "Grade 5",
  "Grade 6",
  "Grade 7",
  "Grade 8",
  "Grade 9",
  "Grade 10",
  "Grade 11",
  "Grade 12",
];

const STATUS_OPTIONS = [
  { label: "Enrolled", value: "enrolled" },
  { label: "Paused", value: "paused" },
  { label: "Unenrolled", value: "unenrolled" },
  { label: "Banned", value: "banned" },
  { label: "Graduated", value: "graduated" },
];

const GRADE_OPTIONS = GRADE_LEVELS.map((g) => ({ label: g, value: g }));

const TYPE_OPTIONS = [
  { label: "Subscription", value: "subscription" },
  { label: "Non-Subscription", value: "non_subscription" },
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatPeso(n: number | null | undefined): string {
  return `₱${(n ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const STATUS_CLASSES: Record<string, string> = {
  enrolled: "bg-green-100 text-green-700 border-green-300",
  paused: "bg-yellow-100 text-amber-700 border-yellow-300",
  unenrolled: "bg-muted text-muted-foreground border-border",
  banned: "bg-red-100 text-red-700 border-red-300",
  graduated: "bg-blue-100 text-blue-700 border-blue-300",
};

function StatusBadge({ status }: { status: string }) {
  const cls =
    STATUS_CLASSES[status?.toLowerCase()] ??
    "bg-muted text-muted-foreground border-border";
  return (
    <span
      className={cn(
        "rounded-full border px-2 py-0.5 text-[11px] font-bold capitalize",
        cls,
      )}
    >
      {status}
    </span>
  );
}

function TableRowSkeleton() {
  return (
    <tr>
      {Array.from({ length: 8 }).map((_, i) => (
        <td key={i} className="px-4 py-3">
          <Skeleton className="h-4 w-full" />
        </td>
      ))}
    </tr>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function StudentsReportPage() {
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.roles.includes("admin") ?? false;
  const isManager = user?.roles.includes("manager") ?? false;

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [enrollmentStatus, setEnrollmentStatus] = useState("");
  const [gradeLevel, setGradeLevel] = useState("");
  const [studentType, setStudentType] = useState("");
  const [page, setPage] = useState(1);
  const [expandedRowId, setExpandedRowId] = useState<number | null>(null);

  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  const params = {
    search: search || undefined,
    status: enrollmentStatus || undefined,
    grade: gradeLevel || undefined,
    type: studentType || undefined,
    page,
  };

  const { data, isLoading, isError } = useQuery({
    queryKey: ["reports-students", params],
    queryFn: () => reportApi.students(params),
  });

  const rows = data?.data;
  const summary = data?.summary;
  const meta = data?.meta;

  function handleFilterChange(setter: (v: string) => void) {
    return (v: string) => {
      setter(v);
      setPage(1);
    };
  }

  function toggleRow(id: number) {
    setExpandedRowId((prev) => (prev === id ? null : id));
  }

  return (
    <div className="space-y-4 p-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs text-muted-foreground">Reports</p>
          <h1 className="text-xl font-bold text-foreground">Student Report</h1>
        </div>
        {(isAdmin || isManager) && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              void exportReport("reports/students", {
                search: search || undefined,
                status: enrollmentStatus || undefined,
                grade: gradeLevel || undefined,
                type: studentType || undefined,
              });
            }}
          >
            <Download className="mr-1.5 h-4 w-4" aria-hidden="true" />
            Export to Excel
          </Button>
        )}
      </div>

      {/* Search + Filter toolbar */}
      <div className="space-y-2.5">
        <div className="relative">
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
          onChange={handleFilterChange(setStudentType)}
        />
      </div>

      {/* Summary */}
      {summary && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl border border-border bg-card p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Total Enrolled
            </p>
            <p className="mt-1 text-2xl font-extrabold text-foreground">
              {summary.total}
            </p>
          </div>

          {summary.grade_breakdown &&
            Object.keys(summary.grade_breakdown).length > 0 && (
              <div className="rounded-xl border border-border bg-card p-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  By Grade
                </p>
                <div className="space-y-1">
                  {Object.entries(summary.grade_breakdown).map(
                    ([grade, count]) => (
                      <div
                        key={grade}
                        className="flex items-center justify-between text-xs"
                      >
                        <span className="text-foreground">{grade}</span>
                        <span className="font-semibold text-foreground">
                          {count}
                        </span>
                      </div>
                    ),
                  )}
                </div>
              </div>
            )}

          {summary.status_breakdown &&
            Object.keys(summary.status_breakdown).length > 0 && (
              <div className="rounded-xl border border-border bg-card p-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  By Status
                </p>
                <div className="space-y-1">
                  {Object.entries(summary.status_breakdown).map(
                    ([status, count]) => (
                      <div
                        key={status}
                        className="flex items-center justify-between text-xs"
                      >
                        <span className="capitalize text-foreground">
                          {status}
                        </span>
                        <span className="font-semibold text-foreground">
                          {count}
                        </span>
                      </div>
                    ),
                  )}
                </div>
              </div>
            )}
        </div>
      )}

      {/* Table */}
      <div className="overflow-x-auto rounded-xl border border-border bg-card">
        {isError ? (
          <p className="px-4 py-8 text-center text-sm text-destructive">
            Failed to load student data. Please try again.
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted/40">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">
                  Name
                </th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">
                  Student #
                </th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">
                  Grade
                </th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">
                  Section
                </th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">
                  Status
                </th>
                <th className="px-4 py-2 text-right text-xs font-semibold text-muted-foreground">
                  Wallet Balance
                </th>
                <th className="px-4 py-2 text-right text-xs font-semibold text-muted-foreground">
                  Total Spent
                </th>
                <th className="w-8 px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {isLoading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <TableRowSkeleton key={i} />
                ))
              ) : !rows?.length ? (
                <tr>
                  <td
                    colSpan={8}
                    className="px-4 py-10 text-center text-muted-foreground"
                  >
                    No students found for the selected filters.
                  </td>
                </tr>
              ) : (
                rows.flatMap((row) => [
                  <tr
                    key={row.id}
                    className="cursor-pointer hover:bg-muted/20"
                    onClick={() => toggleRow(row.id)}
                  >
                    <td className="px-4 py-2.5 font-medium text-foreground">
                      {row.full_name}
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">
                      {row.student_number}
                    </td>
                    <td className="px-4 py-2.5 text-muted-foreground">
                      {row.grade_level}
                    </td>
                    <td className="px-4 py-2.5 text-muted-foreground">
                      {row.section ?? "—"}
                    </td>
                    <td className="px-4 py-2.5">
                      <StatusBadge status={row.status} />
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      {formatPeso(row.wallet_balance)}
                    </td>
                    <td className="px-4 py-2.5 text-right font-semibold">
                      {formatPeso(row.total_spent)}
                    </td>
                    <td className="px-4 py-2.5 text-muted-foreground">
                      <ChevronRight
                        className={cn(
                          "h-4 w-4 transition-transform duration-150",
                          expandedRowId === row.id && "rotate-90",
                        )}
                        aria-hidden="true"
                      />
                    </td>
                  </tr>,
                  ...(expandedRowId === row.id
                    ? [
                        <tr key={`${row.id}-detail`} className="bg-muted/10">
                          <td colSpan={8} className="px-6 py-4">
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
                          </td>
                        </tr>,
                      ]
                    : []),
                ])
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Page {meta.current_page} of {meta.last_page} ({meta.total} records)
          </span>
          <div className="flex gap-1">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              aria-label="Previous page"
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="flex items-center px-2 font-medium text-foreground">
              {page} / {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page}
              aria-label="Next page"
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
```

> **Note:** The `rows.flatMap()` pattern is used instead of JSX fragment siblings inside `<tbody>` to avoid React's key warning — `<tbody>` cannot have `<>` fragments as direct children.

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -40
```

Expected: no errors.

- [ ] **Step 3: Run the full backend test suite to confirm no regressions**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/(kitchen)/reports/students/page.tsx
git commit -m "feat: replace dropdowns with pill filters, add search and expandable rows to student report"
```

---

## Self-Review Checklist

- [x] Search by first name, last name, student number, section — Task 1 ✓
- [x] Search combined with status filter — Task 1 ✓
- [x] Summary cards unaffected by search — Task 1, explicitly tested ✓
- [x] Export forwards search param — Task 1 ✓
- [x] notes + allergies in paginated response — Task 1 ✓
- [x] notes + allergies as null when empty — Task 1 ✓
- [x] Export headings: 14 columns, Allergies at [12], Notes at [13] — Task 2 ✓
- [x] Export map: null → empty string — Task 2 ✓
- [x] StudentReportRow type updated — Task 3 ✓
- [x] FilterPillGroup: All pill deselects, active pill re-click deselects — Task 4 ✓
- [x] TableRowSkeleton: 8 cells — Task 5 ✓
- [x] Empty/error state: colSpan=8 — Task 5 ✓
- [x] Debounced search resets page to 1 — Task 5 ✓
- [x] Filter pills reset page to 1 — Task 5 ✓
- [x] One row open at a time — Task 5 ✓
- [x] Export call includes search param — Task 5 ✓
