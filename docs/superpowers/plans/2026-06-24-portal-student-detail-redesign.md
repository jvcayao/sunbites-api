# Portal Student Detail Page — Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the portal student detail page with a Profile tab, photo upload, QR print/download, pill-filtered Order History and Wallet tabs, and remove Recent Orders from the dashboard.

**Architecture:** All portal pages use client-side Zustand auth (token in localStorage) — `page.tsx` stays a `"use client"` component consistent with the rest of the portal. Decomposition is achieved through extracted sub-components under `_components/`. Backend changes add new fields, a photo controller, and filters to two existing controllers.

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit 12, Next.js 16 (React 19), TanStack Query, Tailwind v4, shadcn/ui, MSW 2, Jest 30, `react-qr-code ^2.0.21`

## Global Constraints

- All backend commands must run through `vendor/bin/sail`
- All backend tests use `LazilyRefreshDatabase` + `PermissionSeeder`
- Portal auth guard: `auth:parents` + ability `parent`
- Student authorization uses `$this->authorize('view', $student)` → `ParentStudentPolicy::view()` (checks `parent_student` pivot)
- Student photos stored on the **private** disk at `photos/students/`
- Frontend API base in tests: `http://localhost:8000` (set in `jest.setup.ts`)
- Frontend tests import `render`/`screen` from `@/__tests__/test-utils` (not `@testing-library/react`)
- Run `vendor/bin/sail bin pint --dirty --format agent` after any PHP file change
- Named exports only for React components; no default exports from component files (exception: Next.js page files require default export)

---

## Task 1: Extend portal StudentController response

**Files:**
- Modify: `app/Http/Controllers/Portal/StudentController.php`
- Create: `tests/Feature/Portal/StudentListFieldsTest.php`

**Interfaces:**
- Produces: `StudentSummary` API response now includes `photo_url: string|null`, `birthday: string|null` (YYYY-MM-DD), `notes: string|null`, `qr_code: string|null`
- `photo_url` is `null` when `photo_path` is null; otherwise `url("/api/v1/portal/students/{id}/photo")`

- [ ] **Step 1: Create the test file**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Portal/StudentListFieldsTest.php
```

- [ ] **Step 2: Write the failing tests**

Replace the generated file contents with:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentListFieldsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;
    private Branch $branch;
    private Student $student;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'birthday' => '2011-05-15',
            'notes' => 'Lactose intolerant',
        ]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_students_list_includes_birthday_notes_qr_code(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.birthday', '2011-05-15')
            ->assertJsonPath('data.0.notes', 'Lactose intolerant')
            ->assertJsonPath('data.0.qr_code', $this->student->qr_code);
    }

    public function test_photo_url_is_null_when_no_photo(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.photo_url', null);
    }

    public function test_photo_url_points_to_serve_endpoint_when_photo_exists(): void
    {
        $this->student->update(['photo_path' => 'photos/students/test.jpg']);

        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.photo_url', url("/api/v1/portal/students/{$this->student->id}/photo"));
    }

    public function test_response_does_not_expose_photo_path(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonMissingPath('data.0.photo_path');
    }
}
```

- [ ] **Step 3: Run to confirm failures**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentListFieldsTest.php
```

Expected: 4 failures (fields not in response yet).

- [ ] **Step 4: Modify StudentController to add the new fields**

Open `app/Http/Controllers/Portal/StudentController.php`. Replace the map closure:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $students = $request->user()
            ->students()
            ->with(['branch:id,name,slug', 'wallet'])
            ->get()
            ->map(fn ($student) => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'grade_level' => $student->grade_level,
                'section' => $student->section,
                'birthday' => $student->birthday?->format('Y-m-d'),
                'notes' => $student->notes,
                'qr_code' => $student->qr_code,
                'photo_url' => $student->photo_path
                    ? url("/api/v1/portal/students/{$student->id}/photo")
                    : null,
                'student_type' => $student->student_type->value,
                'enrollment_status' => $student->enrollment_status->value,
                'allergies' => $student->allergies,
                'branch' => [
                    'id' => $student->branch->id,
                    'name' => $student->branch->name,
                ],
                'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
                'wallet_alert_threshold' => (float) $student->pivot->wallet_alert_threshold,
                'linked_at' => $student->pivot->linked_at,
                'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
            ]);

        return response()->json(['data' => $students]);
    }
}
```

- [ ] **Step 5: Run tests — confirm they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentListFieldsTest.php
```

Expected: 4 passed.

- [ ] **Step 6: Format**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Portal/StudentController.php tests/Feature/Portal/StudentListFieldsTest.php
git commit -m "$(cat <<'EOF'
feat(portal): add birthday, notes, qr_code, photo_url to student list response

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: StudentPhotoController — upload + serve + routes

**Files:**
- Create: `app/Http/Controllers/Portal/StudentPhotoController.php`
- Modify: `routes/portal-api.php`
- Create: `tests/Feature/Portal/StudentPhotoTest.php`

**Interfaces:**
- `GET /api/v1/portal/students/{student}/photo` → streams private photo; 404 if no photo; 403 if not linked
- `POST /api/v1/portal/students/{student}/photo` → accepts `photo` (mimes: jpeg,png,webp, max 5120 KB); stores on private disk at `photos/students/`; returns `{ photo_url: string }`

- [ ] **Step 1: Create the test file**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Portal/StudentPhotoTest.php
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentPhotoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;
    private Branch $branch;
    private Student $student;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    // --- store ---

    public function test_parent_can_upload_photo_for_linked_student(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        );

        $response->assertOk()
            ->assertJsonStructure(['photo_url'])
            ->assertJsonPath('photo_url', url("/api/v1/portal/students/{$this->student->id}/photo"));

        $this->student->refresh();
        $this->assertNotNull($this->student->photo_path);
        Storage::disk('private')->assertExists($this->student->photo_path);
    }

    public function test_upload_deletes_old_photo_from_private_disk(): void
    {
        Storage::fake('private');

        Storage::disk('private')->put('photos/students/old.jpg', 'old data');
        $this->student->update(['photo_path' => 'photos/students/old.jpg']);

        $file = UploadedFile::fake()->image('new.jpg', 200, 200);

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertOk();

        Storage::disk('private')->assertMissing('photos/students/old.jpg');
    }

    public function test_upload_rejects_invalid_mime(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_upload_rejects_files_over_5mb(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_upload_forbidden_for_non_linked_student(): void
    {
        Storage::fake('private');

        $other = Student::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$other->id}/photo",
            ['photo' => $file],
        )->assertForbidden();
    }

    // --- show ---

    public function test_parent_can_view_photo_of_linked_student(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('photos/students/student.jpg', 'img');
        $this->student->update(['photo_path' => 'photos/students/student.jpg']);

        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$this->student->id}/photo");

        $response->assertOk();
    }

    public function test_show_returns_404_when_no_photo(): void
    {
        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$this->student->id}/photo");

        $response->assertNotFound();
    }

    public function test_show_forbidden_for_non_linked_student(): void
    {
        $other = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$other->id}/photo");

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_upload(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnauthorized();
    }
}
```

- [ ] **Step 3: Run to confirm failures**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentPhotoTest.php
```

Expected: all fail (routes don't exist yet).

- [ ] **Step 4: Create the controller**

```bash
vendor/bin/sail artisan make:controller Portal/StudentPhotoController --no-interaction
```

Replace the generated file with:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class StudentPhotoController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, Student $student): Response
    {
        $this->authorize('view', $student);

        abort_if(! $student->photo_path, 404);

        return Storage::disk('private')->response($student->photo_path);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $oldPath = $student->photo_path;

        $path = $request->file('photo')->store('photos/students', 'private');

        $student->update(['photo_path' => $path]);

        if ($oldPath) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json([
            'photo_url' => url("/api/v1/portal/students/{$student->id}/photo"),
        ]);
    }
}
```

- [ ] **Step 5: Register routes in portal-api.php**

Open `routes/portal-api.php`. Add these two lines after the existing wallet routes (after line `Route::patch('/students/{student}/wallet/alert', ...)`):

```php
Route::get('/students/{student}/photo', [StudentPhotoController::class, 'show']);
Route::post('/students/{student}/photo', [StudentPhotoController::class, 'store']);
```

Also add the import at the top of the file with the other Portal use statements:

```php
use App\Http\Controllers\Portal\StudentPhotoController;
```

- [ ] **Step 6: Run tests — confirm they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentPhotoTest.php
```

Expected: all passed.

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/StudentPhotoController.php routes/portal-api.php tests/Feature/Portal/StudentPhotoTest.php
git commit -m "$(cat <<'EOF'
feat(portal): add student photo upload and serve endpoints

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: ActivityController — add payment_method filter

**Files:**
- Modify: `app/Http/Controllers/Portal/ActivityController.php`
- Create: `tests/Feature/Portal/StudentActivityFilterTest.php`

**Interfaces:**
- `GET /portal/students/{student}/activity?payment_method=cash` filters orders to cash only
- `payment_method` accepts: `cash`, `wallet` (validated via `in:` rule)
- `spending_total` reflects filtered results only (computed before pagination, after all filters applied)

- [ ] **Step 1: Create the test file**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Portal/StudentActivityFilterTest.php
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\Order;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentActivityFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;
    private Branch $branch;
    private Student $student;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_payment_method_filter_returns_only_cash_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=cash");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'cash');
    }

    public function test_payment_method_filter_returns_only_wallet_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=wallet");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'wallet');
    }

    public function test_spending_total_reflects_filtered_results(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=cash");

        $response->assertOk()
            ->assertJsonPath('spending_total', 50.0);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=gcash");

        $response->assertUnprocessable();
    }

    public function test_no_filter_returns_all_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
```

- [ ] **Step 3: Run to confirm failures**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentActivityFilterTest.php
```

Expected: `test_payment_method_filter_*` and `test_spending_total_*` fail; `test_invalid_payment_method_is_rejected` may fail. `test_no_filter_returns_all_orders` may pass already.

- [ ] **Step 4: Update ActivityController**

Replace `app/Http/Controllers/Portal/ActivityController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:cash,wallet'],
        ]);

        $query = $student->orders()
            ->whereNull('voided_at')
            ->with('items:id,order_id,name,quantity,price,line_total')
            ->latest();

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        if (! empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $totalSpent = (clone $query)->sum('total');
        $orders = $query->paginate($perPage);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'spending_total' => (float) $totalSpent,
            'data' => collect($orders->items())->map(fn ($order) => [
                'id' => $order->id,
                'receipt_number' => $order->receipt_number,
                'total' => (float) $order->total,
                'payment_method' => $order->payment_method->value,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'line_total' => (float) $item->line_total,
                ]),
                'created_at' => $order->created_at,
            ]),
            'meta' => $this->paginationMeta($orders),
        ]);
    }
}
```

- [ ] **Step 5: Run tests — confirm they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentActivityFilterTest.php
```

Expected: all 5 passed.

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/ActivityController.php tests/Feature/Portal/StudentActivityFilterTest.php
git commit -m "$(cat <<'EOF'
feat(portal): add payment_method filter to student activity endpoint

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: WalletController — add type, from/to, page filters

**Files:**
- Modify: `app/Http/Controllers/Portal/WalletController.php`
- Create: `tests/Feature/Portal/StudentWalletFilterTest.php`

**Interfaces:**
- `GET /portal/students/{student}/wallet?type=deposit` returns only deposit transactions
- `GET /portal/students/{student}/wallet?type=withdraw` returns only withdraw transactions
- `GET /portal/students/{student}/wallet?from=2026-06-01&to=2026-06-30` filters by date range
- `page` query param controls pagination page (Laravel paginator reads it automatically)

- [ ] **Step 1: Create the test file**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Portal/StudentWalletFilterTest.php
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentWalletFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;
    private Branch $branch;
    private Student $student;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_type_deposit_returns_only_deposit_transactions(): void
    {
        $this->student->deposit(5000); // ₱50 — type: deposit
        $this->student->withdraw(1000); // ₱10 — type: withdraw

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=deposit");

        $response->assertOk();

        $types = collect($response->json('data'))->pluck('type')->unique()->values()->all();
        $this->assertSame(['deposit'], $types);
    }

    public function test_type_withdraw_returns_only_withdraw_transactions(): void
    {
        $this->student->deposit(5000);
        $this->student->withdraw(1000);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=withdraw");

        $response->assertOk();

        $types = collect($response->json('data'))->pluck('type')->unique()->values()->all();
        $this->assertSame(['withdraw'], $types);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=transfer");

        $response->assertUnprocessable();
    }

    public function test_no_type_filter_returns_all_transactions(): void
    {
        $this->student->deposit(5000);
        $this->student->withdraw(1000);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
```

- [ ] **Step 3: Run to confirm failures**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentWalletFilterTest.php
```

Expected: type filter tests fail; `test_no_type_filter_returns_all_transactions` passes.

- [ ] **Step 4: Update WalletController**

Replace `app/Http/Controllers/Portal/WalletController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'type'     => ['nullable', 'string', 'in:deposit,withdraw'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $query = Transaction::where('payable_type', Student::class)
            ->where('payable_id', $student->id)
            ->latest();

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $transactions = $query->paginate($perPage);

        $pivot = $request->user()->students()->wherePivot('student_id', $student->id)->first()?->pivot;

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'wallet_alert_threshold' => $pivot ? (float) $pivot->wallet_alert_threshold : 0.0,
            'data' => collect($transactions->items())->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amountFloat,
                'meta' => $tx->meta,
                'created_at' => $tx->created_at,
            ]),
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    public function setAlert(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'threshold' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $request->user()->students()->updateExistingPivot($student->id, [
            'wallet_alert_threshold' => $validated['threshold'],
        ]);

        return response()->json(['message' => 'Alert threshold updated.', 'threshold' => (float) $validated['threshold']]);
    }
}
```

- [ ] **Step 5: Run tests — confirm they pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/StudentWalletFilterTest.php
```

Expected: all 4 passed.

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/WalletController.php tests/Feature/Portal/StudentWalletFilterTest.php
git commit -m "$(cat <<'EOF'
feat(portal): add type, date, and page filters to wallet endpoint

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Frontend groundwork — package, types, API service, utilities

**Files:**
- Modify: `sunbites-portal/types/portal.ts`
- Modify: `sunbites-portal/lib/api/portal.ts`
- Modify: `sunbites-portal/lib/format.ts`
- Create: `sunbites-portal/lib/date-range.ts`

> All remaining tasks are in `~/sunbites-portal` unless otherwise noted.

**Interfaces:**
- `StudentSummary` gains: `photo_url: string | null`, `birthday: string | null`, `notes: string | null`, `qr_code: string | null`; `photo_path` field is **removed**
- `studentsApi.fetchPhoto(id)` → `Promise<string | null>` (blob URL or null)
- `studentsApi.uploadPhoto(id, file)` → `Promise<{ photo_url: string }>`
- `studentsApi.activity(id, params)` — params gains `payment_method?: "cash" | "wallet"`, `from?: string`, `to?: string`
- `studentsApi.wallet(id, params?)` — params gains `type?: "deposit" | "withdraw"`, `from?: string`, `to?: string`, `page?: number`
- `formatBirthday(dateStr)` → `string` e.g. `"May 15, 2011"` (timezone-safe)
- `getDateRange(filter)` → `{ from: string; to: string }` for `"today" | "this-week" | "this-month"`

- [ ] **Step 1: Install react-qr-code**

```bash
cd ~/sunbites-portal && npm install react-qr-code@^2.0.21
```

- [ ] **Step 2: Update `types/portal.ts` — add new fields to StudentSummary**

Find the `StudentSummary` interface and replace it:

```typescript
export interface StudentSummary {
  id: number;
  student_number: string;
  full_name: string;
  first_name: string;
  last_name: string;
  grade_level: string;
  section: string | null;
  birthday: string | null;
  notes: string | null;
  qr_code: string | null;
  photo_url: string | null;
  branch_name: string;
  allergies: string | null;
  wallet_balance: number;
  wallet_alert_threshold: number;
  enrollment_status: "enrolled" | "paused" | "graduated" | "banned";
  student_type: "subscription" | "non_subscription";
  subscription_monthly_status: SubscriptionMonthlyStatus | null;
}

export type StudentDetail = StudentSummary;
```

- [ ] **Step 3: Update `lib/api/portal.ts` — add fetchPhoto, uploadPhoto; extend activity and wallet signatures**

Find the `studentsApi` block and replace it entirely:

```typescript
export const studentsApi = {
  list: () => apiClient.get<{ data: StudentDetail[] }>("/portal/students"),

  fetchPhoto: async (id: number): Promise<string | null> => {
    const { useAuthStore } = await import("@/lib/store/auth");
    const token = useAuthStore.getState().token;
    const url = `${process.env.NEXT_PUBLIC_API_URL}/portal/students/${id}/photo`;
    const headers: Record<string, string> = { Accept: "image/*" };
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const response = await fetch(url, { headers });
    if (response.status === 404) return null;
    if (!response.ok) return null;
    const blob = await response.blob();
    return URL.createObjectURL(blob);
  },

  uploadPhoto: async (id: number, file: File): Promise<{ photo_url: string }> => {
    const { useAuthStore } = await import("@/lib/store/auth");
    const token = useAuthStore.getState().token;
    const formData = new FormData();
    formData.append("photo", file);
    const url = `${process.env.NEXT_PUBLIC_API_URL}/portal/students/${id}/photo`;
    const headers: Record<string, string> = { Accept: "application/json" };
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const response = await fetch(url, { method: "POST", headers, body: formData });
    if (!response.ok) {
      const err = await response.json().catch(() => ({ message: "Upload failed." }));
      throw err;
    }
    return response.json();
  },

  activity: (
    id: number,
    params: {
      page?: number;
      per_page?: number;
      payment_method?: "cash" | "wallet";
      from?: string;
      to?: string;
    },
  ) =>
    apiClient.get<ActivityResponse>(`/portal/students/${id}/activity`, {
      params: {
        page: params.page,
        per_page: params.per_page,
        payment_method: params.payment_method,
        from: params.from,
        to: params.to,
      },
    }),

  wallet: (
    id: number,
    params?: {
      page?: number;
      type?: "deposit" | "withdraw";
      from?: string;
      to?: string;
    },
  ) =>
    apiClient.get<WalletData>(`/portal/students/${id}/wallet`, {
      params: {
        page: params?.page,
        type: params?.type,
        from: params?.from,
        to: params?.to,
      },
    }),

  setAlert: (id: number, threshold: number) =>
    apiClient.patch<{ message: string }>(
      `/portal/students/${id}/wallet/alert`,
      { threshold },
    ),

  paymentHistory: (id: number) =>
    apiClient.get<{ data: PaymentHistoryEntry[] }>(
      `/portal/students/${id}/payment-history`,
    ),
};
```

- [ ] **Step 4: Add `formatBirthday` to `lib/format.ts`**

Append to the end of `lib/format.ts`:

```typescript
/**
 * Format a YYYY-MM-DD date string as a human-readable birthday.
 * Uses local date construction to avoid UTC midnight timezone offset issues.
 * Example: formatBirthday("2011-05-15") → "May 15, 2011"
 */
export function formatBirthday(dateStr: string): string {
  const [year, month, day] = dateStr.split("-").map(Number);
  return new Date(year, month - 1, day).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}
```

- [ ] **Step 5: Create `lib/date-range.ts`**

```typescript
function toISODate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export type DateRangeFilter = "today" | "this-week" | "this-month";

export function getDateRange(filter: DateRangeFilter): { from: string; to: string } {
  const today = new Date();

  if (filter === "today") {
    const d = toISODate(today);
    return { from: d, to: d };
  }

  if (filter === "this-week") {
    const monday = new Date(today);
    const day = monday.getDay(); // 0 = Sunday
    const diff = day === 0 ? -6 : 1 - day;
    monday.setDate(monday.getDate() + diff);
    const sunday = new Date(monday);
    sunday.setDate(monday.getDate() + 6);
    return { from: toISODate(monday), to: toISODate(sunday) };
  }

  // this-month
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
  const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
  return { from: toISODate(firstDay), to: toISODate(lastDay) };
}
```

- [ ] **Step 6: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors (or only pre-existing unrelated errors).

- [ ] **Step 7: Commit**

```bash
cd ~/sunbites-portal
git add types/portal.ts lib/api/portal.ts lib/format.ts lib/date-range.ts package.json package-lock.json
git commit -m "$(cat <<'EOF'
feat(portal): add react-qr-code, extend StudentSummary type, update API service and date utilities

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Remove Recent Orders from dashboard

**Files:**
- Modify: `app/(portal)/dashboard/page.tsx`

**Interfaces:**
- `DashboardData` no longer needs `recent_orders` consumed on the frontend (backend still sends it, frontend ignores it)
- `RecentOrder` type import is removed from the dashboard page

- [ ] **Step 1: Edit dashboard/page.tsx**

Make the following changes to `app/(portal)/dashboard/page.tsx`:

1. Remove from the import line: `import type { RecentOrder, StudentSummary } from "@/types/portal";` → change to `import type { StudentSummary } from "@/types/portal";`

2. Remove the `ShoppingBag` import from lucide (if no longer used): change `import { ArrowRight, ShoppingBag, Wallet } from "lucide-react";` → `import { ArrowRight, Wallet } from "lucide-react";`

3. Remove the `Badge` import if only used in RecentOrderRow: check if Badge is used elsewhere — if not, remove it from `import { Badge } from "@/components/ui/badge";`

4. Remove these entire blocks (lines ~84 to ~307):
   - `type MethodFilter`
   - `type DateFilter`
   - `const DATE_FILTER_LABELS`
   - `function isWithinDateFilter`
   - `function RecentOrderRow`
   - `function RecentOrdersSection`

5. In the `DashboardPage` return JSX, remove the entire Recent Orders section (from the comment `{/* Recent Orders */}` down to and including the closing `null}` and `)`):

```tsx
{/* Remove this entire block: */}
{isLoading ? (
  <section aria-labelledby="orders-heading">
    ...
  </section>
) : data ? (
  <RecentOrdersSection
    orders={data.recent_orders}
    students={data.students}
  />
) : null}
```

The `DashboardPage` return should end after the students section closes:

```tsx
  return (
    <div className="space-y-10">
      <div>
        <h1 className="text-2xl font-bold">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Overview of your linked students and recent activity.
        </p>
      </div>

      {/* Students */}
      <section aria-labelledby="students-heading">
        <h2 id="students-heading" className="mb-4 text-base font-semibold">
          Your Students
        </h2>

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StudentCardSkeleton />
            <StudentCardSkeleton />
          </div>
        ) : error ? (
          <p className="text-sm text-destructive">
            Failed to load dashboard. Please refresh the page.
          </p>
        ) : !data?.students.length ? (
          <div className="rounded-2xl border border-dashed border-border p-10 text-center">
            <p className="text-sm text-muted-foreground">
              No students linked to your account yet.
            </p>
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.students.map((student) => (
              <StudentCard key={student.id} student={student} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -20
```

Expected: no new errors.

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal
git add app/\(portal\)/dashboard/page.tsx
git commit -m "$(cat <<'EOF'
feat(portal): remove Recent Orders section from dashboard

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: FilterPills shared component

**Files:**
- Create: `app/(portal)/students/[id]/_components/filter-pills.tsx`

**Interfaces:**
- `FilterPills` props: `pills: { value: string; label: string }[]`, `active: string`, `onSelect: (value: string) => void`, `className?: string`
- Selected pill: filled `bg-primary text-primary-foreground rounded-full`
- Inactive pill: `bg-muted text-muted-foreground rounded-full hover:bg-muted/80`

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/filter-pills.tsx`:

```tsx
import { cn } from "@/lib/utils";

interface Pill {
  value: string;
  label: string;
}

interface FilterPillsProps {
  pills: Pill[];
  active: string;
  onSelect: (value: string) => void;
  className?: string;
}

export function FilterPills({ pills, active, onSelect, className }: FilterPillsProps) {
  return (
    <div className={cn("flex flex-wrap gap-2", className)} role="group">
      {pills.map((pill) => (
        <button
          key={pill.value}
          type="button"
          onClick={() => onSelect(pill.value)}
          aria-pressed={active === pill.value}
          className={cn(
            "px-3 py-1 text-sm font-medium rounded-full transition-colors",
            active === pill.value
              ? "bg-primary text-primary-foreground"
              : "bg-muted text-muted-foreground hover:bg-muted/80",
          )}
        >
          {pill.label}
        </button>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -10
```

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/filter-pills.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add shared FilterPills component for student detail tabs

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: StudentQrActions component

**Files:**
- Create: `app/(portal)/students/[id]/_components/student-qr-actions.tsx`

**Interfaces:**
- `StudentQrActions` props: `qrCode: string`, `studentName: string`, `className?: string`
- Renders the QR SVG (via `react-qr-code`) in a hidden print-only div and two buttons: "Print QR" and "Download PNG"
- Print: `window.print()`
- Download: SVG → XMLSerializer → canvas → PNG → `<a download>`

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/student-qr-actions.tsx`:

```tsx
"use client";

import { useRef } from "react";
import QRCode from "react-qr-code";
import { Download, Printer } from "lucide-react";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface StudentQrActionsProps {
  qrCode: string;
  studentName: string;
  className?: string;
}

export function StudentQrActions({ qrCode, studentName, className }: StudentQrActionsProps) {
  const svgContainerRef = useRef<HTMLDivElement>(null);

  function handleDownload() {
    const svg = svgContainerRef.current?.querySelector("svg");
    if (!svg) return;

    const serializer = new XMLSerializer();
    const svgStr = serializer.serializeToString(svg);
    const svgDataUri = "data:image/svg+xml;base64," + btoa(unescape(encodeURIComponent(svgStr)));

    const canvas = document.createElement("canvas");
    const size = 300;
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const img = new Image();
    img.onload = () => {
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, size, size);
      ctx.drawImage(img, 0, 0, size, size);
      const pngUrl = canvas.toDataURL("image/png");
      const link = document.createElement("a");
      link.download = `${studentName.replace(/\s+/g, "-").toLowerCase()}-qr.png`;
      link.href = pngUrl;
      link.click();
    };
    img.src = svgDataUri;
  }

  return (
    <>
      {/* Print-only layout — hidden on screen, visible when printing */}
      <div
        className="hidden print:block fixed inset-0 flex flex-col items-center justify-center bg-white p-12"
        aria-hidden="true"
      >
        <p className="text-xl font-bold mb-1">Sunbites</p>
        <p className="text-base font-semibold mb-6">{studentName}</p>
        <QRCode value={qrCode} size={220} />
        <p className="mt-4 text-sm font-mono text-gray-500">{qrCode}</p>
      </div>

      {/* Screen-only buttons */}
      <div className={cn("flex gap-2 print:hidden", className)}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => window.print()}
          aria-label="Print QR code"
        >
          <Printer className="mr-1.5 h-4 w-4" aria-hidden="true" />
          Print QR
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={handleDownload}
          aria-label="Download QR code as PNG"
        >
          <Download className="mr-1.5 h-4 w-4" aria-hidden="true" />
          Download PNG
        </Button>
      </div>

      {/* Off-screen SVG used for download only — react-qr-code doesn't forwardRef, so query the SVG from the wrapper div */}
      <div ref={svgContainerRef} className="sr-only" aria-hidden="true">
        <QRCode value={qrCode} size={300} />
      </div>
    </>
  );
}
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -10
```

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/student-qr-actions.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add StudentQrActions component with print and download

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: StudentHeader component

**Files:**
- Create: `app/(portal)/students/[id]/_components/student-header.tsx`

**Interfaces:**
- `StudentHeader` props: `student: StudentSummary`, `onPhotoUploaded: () => void`
- Fetches photo blob URL on mount via `studentsApi.fetchPhoto(id)`, revokes on unmount
- Camera overlay opens file input; validates max 5 MB; calls `studentsApi.uploadPhoto`, then `onPhotoUploaded()` on success
- Renders: avatar (photo or initials), name, grade, branch, `EnrollmentStatusBadge`, student type badge, wallet balance box, QR ID box, `StudentQrActions`

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/student-header.tsx`:

```tsx
"use client";

import { useEffect, useRef, useState } from "react";
import { Camera } from "lucide-react";
import { toast } from "sonner";

import { EnrollmentStatusBadge } from "@/components/enrollment-status-badge";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { studentsApi } from "@/lib/api/portal";
import { formatPHP } from "@/lib/format";
import { cn } from "@/lib/utils";

import { StudentQrActions } from "./student-qr-actions";

import type { StudentSummary } from "@/types/portal";

interface StudentHeaderProps {
  student: StudentSummary;
  onPhotoUploaded: () => void;
  className?: string;
}

function getInitials(fullName: string): string {
  return fullName
    .split(" ")
    .slice(0, 2)
    .map((n) => n[0]?.toUpperCase() ?? "")
    .join("");
}

export function StudentHeader({ student, onPhotoUploaded, className }: StudentHeaderProps) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let url: string | null = null;

    if (student.photo_url) {
      studentsApi.fetchPhoto(student.id).then((fetched) => {
        url = fetched;
        setBlobUrl(fetched);
      });
    }

    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [student.id, student.photo_url]);

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
      toast.error("Photo must be under 5 MB.");
      return;
    }

    setUploading(true);
    try {
      await studentsApi.uploadPhoto(student.id, file);
      toast.success("Photo updated.");
      onPhotoUploaded();
    } catch {
      toast.error("Photo upload failed. Please try again.");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  const isSubscription = student.student_type === "subscription";

  return (
    <div className={cn("rounded-xl border border-border bg-card p-5", className)}>
      <div className="flex flex-wrap items-start gap-5">
        {/* Avatar with upload overlay */}
        <div className="relative shrink-0">
          <Avatar className="h-20 w-20">
            {blobUrl && <AvatarImage src={blobUrl} alt={student.full_name} />}
            <AvatarFallback className="text-xl font-semibold">
              {getInitials(student.full_name)}
            </AvatarFallback>
          </Avatar>
          <button
            type="button"
            disabled={uploading}
            aria-label="Upload student photo"
            onClick={() => fileInputRef.current?.click()}
            className="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 disabled:opacity-60"
          >
            <Camera className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="sr-only"
            aria-hidden="true"
            onChange={handleFileChange}
          />
        </div>

        {/* Student info */}
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-bold">{student.full_name}</h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {student.grade_level}
            {student.branch_name && ` · ${student.branch_name}`}
          </p>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <EnrollmentStatusBadge status={student.enrollment_status} />
            <Badge
              variant="outline"
              className={cn(
                "border-transparent font-medium",
                isSubscription
                  ? "bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400"
                  : "bg-muted text-muted-foreground",
              )}
            >
              {isSubscription ? "Subscription" : "Non-Subscription"}
            </Badge>
          </div>
        </div>

        {/* Right: wallet + QR boxes + actions */}
        <div className="flex flex-wrap items-start gap-3 shrink-0">
          <div className="rounded-lg border border-border bg-muted/30 px-4 py-2 min-w-[90px]">
            <p className="text-xs text-muted-foreground">Wallet</p>
            <p className="mt-0.5 text-sm font-semibold tabular-nums">
              {formatPHP(student.wallet_balance)}
            </p>
          </div>

          {student.qr_code && (
            <div className="rounded-lg border border-border bg-muted/30 px-4 py-2">
              <p className="text-xs text-muted-foreground">QR ID</p>
              <p className="mt-0.5 text-sm font-mono">{student.qr_code}</p>
            </div>
          )}

          {student.qr_code && (
            <StudentQrActions
              qrCode={student.qr_code}
              studentName={student.full_name}
              className="self-end"
            />
          )}
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -10
```

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/student-header.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add StudentHeader with avatar, badges, wallet, QR ID, and photo upload

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: ProfileTab component

**Files:**
- Create: `app/(portal)/students/[id]/_components/profile-tab.tsx`

**Interfaces:**
- `ProfileTab` props: `student: StudentSummary`
- Renders read-only two-column info grid; birthday formatted via `formatBirthday`; allergies shown in amber pill if non-empty
- Subscription students also get a "Meals This Month" card below the grid

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/profile-tab.tsx`:

```tsx
import { cn } from "@/lib/utils";
import { formatBirthday } from "@/lib/format";

import type { StudentSummary } from "@/types/portal";

interface ProfileTabProps {
  student: StudentSummary;
}

function InfoField({ label, value, className }: { label: string; value: React.ReactNode; className?: string }) {
  return (
    <div className={cn("border-b border-border py-3 last:border-0", className)}>
      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">{label}</p>
      <div className="mt-1 text-sm">{value}</div>
    </div>
  );
}

export function ProfileTab({ student }: ProfileTabProps) {
  const isSubscription = student.student_type === "subscription";

  const allergiesValue = student.allergies ? (
    <span className="inline-block rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
      {student.allergies}
    </span>
  ) : (
    <span className="text-muted-foreground">—</span>
  );

  return (
    <div className="space-y-6">
      {/* Personal Information */}
      <div className="rounded-xl border border-border bg-card p-5">
        <h2 className="mb-4 text-xs font-extrabold uppercase tracking-wider text-muted-foreground">
          Personal Information
        </h2>
        <div className="grid grid-cols-1 gap-0 sm:grid-cols-2 sm:gap-x-8">
          {/* Left column */}
          <div>
            <InfoField label="First Name" value={student.first_name || "—"} />
            <InfoField label="Last Name" value={student.last_name || "—"} />
            <InfoField label="Grade Level" value={student.grade_level || "—"} />
            <InfoField label="Section" value={student.section || "—"} />
            <InfoField
              label="Birthday"
              value={student.birthday ? formatBirthday(student.birthday) : "—"}
            />
          </div>
          {/* Right column */}
          <div>
            <InfoField label="Student Number" value={student.student_number || "—"} />
            <InfoField
              label="Student Type"
              value={isSubscription ? "Subscription" : "Non-Subscription"}
            />
            <InfoField label="Allergies" value={allergiesValue} />
            <InfoField label="Notes" value={student.notes || "—"} />
          </div>
        </div>
      </div>

      {/* Meals This Month — subscription students only */}
      {isSubscription && student.subscription_monthly_status && (
        <div className="rounded-xl border border-border bg-card p-5">
          <h2 className="mb-3 text-xs font-extrabold uppercase tracking-wider text-muted-foreground">
            Meals This Month —{" "}
            {(() => {
              const m = student.subscription_monthly_status.month;
              return m.charAt(0).toUpperCase() + m.slice(1);
            })()}{" "}
            {student.subscription_monthly_status.year}
          </h2>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {Object.entries(student.subscription_monthly_status.categories)
              .filter(([, s]) => s.allocated > 0)
              .map(([cat, s]) => (
                <div
                  key={cat}
                  className="rounded-lg border border-border bg-muted/30 p-3 text-center"
                >
                  <p className="text-xs font-medium text-muted-foreground capitalize mb-1">{cat}</p>
                  <p className="text-lg font-bold tabular-nums">
                    {s.used}
                    <span className="text-sm font-normal text-muted-foreground"> / {s.allocated}</span>
                  </p>
                  <p
                    className={cn(
                      "text-xs",
                      s.remaining === 0
                        ? "font-semibold text-destructive"
                        : s.remaining <= 5
                          ? "font-semibold text-amber-600"
                          : "text-muted-foreground",
                    )}
                  >
                    {s.remaining} remaining
                  </p>
                </div>
              ))}
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Write the test**

Create `app/(portal)/students/[id]/_components/profile-tab.test.tsx`:

```tsx
import { render, screen } from "@/__tests__/test-utils";
import { ProfileTab } from "./profile-tab";
import type { StudentSummary } from "@/types/portal";

const baseStudent: StudentSummary = {
  id: 1,
  student_number: "2024-001",
  full_name: "Juan Dela Cruz",
  first_name: "Juan",
  last_name: "Dela Cruz",
  grade_level: "Grade 9",
  section: "Sampaguita",
  birthday: "2011-05-15",
  notes: "Bring umbrella",
  qr_code: "SB-abc123",
  photo_url: null,
  branch_name: "Antipolo",
  allergies: null,
  wallet_balance: 500,
  wallet_alert_threshold: 0,
  enrollment_status: "enrolled",
  student_type: "non_subscription",
  subscription_monthly_status: null,
};

describe("ProfileTab", () => {
  it("renders all personal information fields", () => {
    render(<ProfileTab student={baseStudent} />);

    expect(screen.getByText("Juan")).toBeInTheDocument();
    expect(screen.getByText("Dela Cruz")).toBeInTheDocument();
    expect(screen.getByText("Grade 9")).toBeInTheDocument();
    expect(screen.getByText("Sampaguita")).toBeInTheDocument();
    expect(screen.getByText("2024-001")).toBeInTheDocument();
    expect(screen.getByText("Non-Subscription")).toBeInTheDocument();
    expect(screen.getByText("Bring umbrella")).toBeInTheDocument();
  });

  it("formats birthday as human-readable date", () => {
    render(<ProfileTab student={baseStudent} />);
    expect(screen.getByText("May 15, 2011")).toBeInTheDocument();
  });

  it("shows amber allergies badge when allergies are present", () => {
    render(<ProfileTab student={{ ...baseStudent, allergies: "Peanuts" }} />);
    expect(screen.getByText("Peanuts")).toHaveClass("bg-amber-100");
  });

  it("shows dash for empty allergies", () => {
    render(<ProfileTab student={baseStudent} />);
    // allergies field shows — when null
    const labels = screen.getAllByText("—");
    expect(labels.length).toBeGreaterThan(0);
  });

  it("does not show Meals This Month for non-subscription student", () => {
    render(<ProfileTab student={baseStudent} />);
    expect(screen.queryByText(/Meals This Month/i)).not.toBeInTheDocument();
  });

  it("shows Meals This Month card for subscription student with status", () => {
    const subscriptionStudent: StudentSummary = {
      ...baseStudent,
      student_type: "subscription",
      subscription_monthly_status: {
        month: "june",
        year: 2026,
        categories: {
          meal: { allocated: 20, used: 5, remaining: 15 },
          snack: { allocated: 20, used: 3, remaining: 17 },
          drink: { allocated: 20, used: 2, remaining: 18 },
          extra: { allocated: 0, used: 0, remaining: 0 },
        },
      },
    };

    render(<ProfileTab student={subscriptionStudent} />);
    expect(screen.getByText(/Meals This Month/i)).toBeInTheDocument();
    expect(screen.getByText("15 remaining")).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run the test**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="profile-tab.test" --no-coverage
```

Expected: all 6 passed.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/profile-tab.tsx" "app/(portal)/students/[id]/_components/profile-tab.test.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add ProfileTab component with personal info grid and meals this month

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: WalletTab component with pill filters

**Files:**
- Create: `app/(portal)/students/[id]/_components/wallet-tab.tsx`

**Interfaces:**
- `WalletTab` props: `studentId: number`
- Queries `studentsApi.wallet(id, { type, from, to, page })` with active filters
- Filter row 1: `All | Top-up | Deductions` (maps to `undefined | "deposit" | "withdraw"`)
- Filter row 2: `All time | Today | This week | This month` (maps to `undefined | getDateRange(...)`)
- Balance card and low-balance alert stay outside filters (always current)
- Pill change resets page to 1

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/wallet-tab.tsx`:

```tsx
"use client";

import { startTransition, useEffect, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { studentsApi } from "@/lib/api/portal";
import { formatDate, formatPHP } from "@/lib/format";
import { getDateRange, type DateRangeFilter } from "@/lib/date-range";
import { cn } from "@/lib/utils";
import { FilterPills } from "./filter-pills";

import type { ApiError } from "@/types/auth";
import type { Transaction } from "@/types/portal";

const TYPE_PILLS = [
  { value: "all", label: "All" },
  { value: "deposit", label: "Top-up" },
  { value: "withdraw", label: "Deductions" },
];

const TIME_PILLS = [
  { value: "all", label: "All time" },
  { value: "today", label: "Today" },
  { value: "this-week", label: "This week" },
  { value: "this-month", label: "This month" },
];

interface WalletTabProps {
  studentId: number;
}

function TransactionRow({ tx }: { tx: Transaction }) {
  const isCredit = tx.amount >= 0;
  return (
    <tr className="border-b border-border last:border-0">
      <td className="px-4 py-3 text-sm text-muted-foreground">{formatDate(tx.created_at)}</td>
      <td className="px-4 py-3 text-sm capitalize">{tx.type}</td>
      <td
        className={cn(
          "px-4 py-3 text-right text-sm font-semibold tabular-nums",
          isCredit ? "text-green-600 dark:text-green-400" : "text-destructive",
        )}
      >
        {isCredit ? "+" : ""}
        {formatPHP(tx.amount)}
      </td>
    </tr>
  );
}

export function WalletTab({ studentId }: WalletTabProps) {
  const queryClient = useQueryClient();
  const [alertInput, setAlertInput] = useState<string>("");
  const [alertEditing, setAlertEditing] = useState(false);
  const [typeFilter, setTypeFilter] = useState("all");
  const [timeFilter, setTimeFilter] = useState("all");
  const [page, setPage] = useState(1);

  const typeParam = typeFilter === "all" ? undefined : (typeFilter as "deposit" | "withdraw");
  const dateRange = timeFilter === "all" ? undefined : getDateRange(timeFilter as DateRangeFilter);

  const { data, isLoading, error } = useQuery({
    queryKey: ["student-wallet", studentId, typeFilter, timeFilter, page],
    queryFn: () =>
      studentsApi.wallet(studentId, {
        page,
        type: typeParam,
        from: dateRange?.from,
        to: dateRange?.to,
      }),
  });

  useEffect(() => {
    if (data && !alertEditing) {
      startTransition(() => setAlertInput(String(data.wallet_alert_threshold)));
    }
  }, [data, alertEditing]);

  const alertMutation = useMutation({
    mutationFn: (threshold: number) => studentsApi.setAlert(studentId, threshold),
    onSuccess: () => {
      toast.success("Wallet alert threshold updated.");
      setAlertEditing(false);
      queryClient.invalidateQueries({ queryKey: ["student-wallet", studentId] });
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to update threshold.");
    },
  });

  function handleSaveAlert() {
    const threshold = Number(alertInput);
    if (isNaN(threshold) || threshold < 0) {
      toast.error("Enter a valid threshold amount.");
      return;
    }
    alertMutation.mutate(threshold);
  }

  function handleTypeChange(value: string) {
    setTypeFilter(value);
    setPage(1);
  }

  function handleTimeChange(value: string) {
    setTimeFilter(value);
    setPage(1);
  }

  return (
    <div className="space-y-6">
      {/* Balance */}
      <div className="rounded-xl border border-border bg-card p-6">
        <p className="text-sm text-muted-foreground">Current Balance</p>
        <p className="mt-1 text-4xl font-bold tabular-nums">
          {data ? formatPHP(data.balance) : "—"}
        </p>
      </div>

      {/* Low Balance Alert */}
      <div className="rounded-xl border border-border bg-card p-4">
        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="text-sm font-medium">Low Balance Alert</p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Get notified when balance drops below this amount.
            </p>
          </div>
          {!alertEditing ? (
            <div className="flex items-center gap-3">
              <span className="text-sm font-semibold tabular-nums">
                {data ? formatPHP(data.wallet_alert_threshold) : "—"}
              </span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setAlertInput(String(data?.wallet_alert_threshold ?? 0));
                  setAlertEditing(true);
                }}
              >
                Edit
              </Button>
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <Input
                type="number"
                min="0"
                step="1"
                value={alertInput}
                onChange={(e) => setAlertInput(e.target.value)}
                className="w-28"
                aria-label="Alert threshold amount"
              />
              <Button size="sm" onClick={handleSaveAlert} disabled={alertMutation.isPending}>
                {alertMutation.isPending ? "Saving…" : "Save"}
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setAlertEditing(false)}
                disabled={alertMutation.isPending}
              >
                Cancel
              </Button>
            </div>
          )}
        </div>
      </div>

      {/* Filters */}
      <div className="space-y-2">
        <FilterPills pills={TYPE_PILLS} active={typeFilter} onSelect={handleTypeChange} />
        <FilterPills pills={TIME_PILLS} active={timeFilter} onSelect={handleTimeChange} />
      </div>

      {/* Transactions */}
      {isLoading ? (
        <div className="space-y-2">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : error ? (
        <p className="text-sm text-destructive">Failed to load transactions. Please try again.</p>
      ) : !data?.data.length ? (
        <div className="rounded-xl border border-dashed border-border p-8 text-center">
          <p className="text-sm text-muted-foreground">No transactions match the selected filters.</p>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto rounded-xl border border-border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/50">
                  <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Type</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-muted-foreground">Amount</th>
                </tr>
              </thead>
              <tbody className="bg-card">
                {data.data.map((tx) => (
                  <TransactionRow key={tx.id} tx={tx} />
                ))}
              </tbody>
            </table>
          </div>

          {(data.meta.current_page > 1 || data.meta.current_page < data.meta.last_page) && (
            <div className="flex items-center justify-between">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => p - 1)}
                disabled={data.meta.current_page === 1}
              >
                Previous
              </Button>
              <span className="text-xs text-muted-foreground">
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => p + 1)}
                disabled={data.meta.current_page === data.meta.last_page}
              >
                Next
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Write the test**

Create `app/(portal)/students/[id]/_components/wallet-tab.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { WalletTab } from "./wallet-tab";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const mockWallet = {
  balance: 500,
  wallet_alert_threshold: 50,
  data: [
    { id: 1, type: "deposit", amount: 500, meta: null, created_at: "2026-06-01T10:00:00Z" },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

describe("WalletTab", () => {
  beforeEach(() => {
    server.use(
      http.get(`${API}/portal/students/1/wallet`, () => HttpResponse.json(mockWallet)),
    );
  });

  it("renders current balance", async () => {
    render(<WalletTab studentId={1} />);
    expect(await screen.findByText(/PHP 500\.00/i)).toBeInTheDocument();
  });

  it("selecting Top-up pill calls API with type=deposit", async () => {
    let capturedUrl = "";
    server.use(
      http.get(`${API}/portal/students/1/wallet`, ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json(mockWallet);
      }),
    );

    render(<WalletTab studentId={1} />);
    await screen.findByText(/PHP 500\.00/i);

    await userEvent.click(screen.getByRole("button", { name: "Top-up" }));

    await waitFor(() => {
      expect(capturedUrl).toContain("type=deposit");
    });
  });

  it("selecting Deductions pill calls API with type=withdraw", async () => {
    let capturedUrl = "";
    server.use(
      http.get(`${API}/portal/students/1/wallet`, ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json({ ...mockWallet, data: [] });
      }),
    );

    render(<WalletTab studentId={1} />);
    await screen.findByText(/PHP 500\.00/i);

    await userEvent.click(screen.getByRole("button", { name: "Deductions" }));

    await waitFor(() => {
      expect(capturedUrl).toContain("type=withdraw");
    });
  });
});
```

- [ ] **Step 3: Run the test**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="wallet-tab.test" --no-coverage
```

Expected: all 3 passed.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/wallet-tab.tsx" "app/(portal)/students/[id]/_components/wallet-tab.test.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add WalletTab with pill filters for type and time range

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: OrderHistoryTab component with pill filters

**Files:**
- Create: `app/(portal)/students/[id]/_components/order-history-tab.tsx`

**Interfaces:**
- `OrderHistoryTab` props: `studentId: number`
- Queries `studentsApi.activity(id, { payment_method, from, to, page })`
- Filter row 1: `All | Cash | Wallet` (maps to `undefined | "cash" | "wallet"`)
- Filter row 2: `All time | Today | This week | This month`
- Shows total spent above table (reflects filtered results); pagination; pill change resets page

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/order-history-tab.tsx`:

```tsx
"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { studentsApi } from "@/lib/api/portal";
import { formatDate, formatPHP } from "@/lib/format";
import { getDateRange, type DateRangeFilter } from "@/lib/date-range";
import { FilterPills } from "./filter-pills";

import type { ActivityItem } from "@/types/portal";

const METHOD_PILLS = [
  { value: "all", label: "All" },
  { value: "cash", label: "Cash" },
  { value: "wallet", label: "Wallet" },
];

const TIME_PILLS = [
  { value: "all", label: "All time" },
  { value: "today", label: "Today" },
  { value: "this-week", label: "This week" },
  { value: "this-month", label: "This month" },
];

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: "Cash",
  wallet: "Wallet",
  subscription: "Subscription",
  gcash: "GCash",
};

interface OrderHistoryTabProps {
  studentId: number;
}

function OrderRow({ item }: { item: ActivityItem }) {
  const itemNames = item.items.map((i) => `${i.name} x${i.quantity}`).join(", ");
  return (
    <tr className="border-b border-border last:border-0">
      <td className="px-4 py-3 text-sm text-muted-foreground">{formatDate(item.created_at)}</td>
      <td className="px-4 py-3 text-sm">{itemNames}</td>
      <td className="px-4 py-3 text-sm">{PAYMENT_METHOD_LABELS[item.payment_method] ?? item.payment_method}</td>
      <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums">{formatPHP(item.total)}</td>
    </tr>
  );
}

export function OrderHistoryTab({ studentId }: OrderHistoryTabProps) {
  const [methodFilter, setMethodFilter] = useState("all");
  const [timeFilter, setTimeFilter] = useState("all");
  const [page, setPage] = useState(1);
  const perPage = 15;

  const methodParam = methodFilter === "all" ? undefined : (methodFilter as "cash" | "wallet");
  const dateRange = timeFilter === "all" ? undefined : getDateRange(timeFilter as DateRangeFilter);

  const { data, isLoading, error } = useQuery({
    queryKey: ["student-activity", studentId, methodFilter, timeFilter, page],
    queryFn: () =>
      studentsApi.activity(studentId, {
        page,
        per_page: perPage,
        payment_method: methodParam,
        from: dateRange?.from,
        to: dateRange?.to,
      }),
  });

  function handleMethodChange(value: string) {
    setMethodFilter(value);
    setPage(1);
  }

  function handleTimeChange(value: string) {
    setTimeFilter(value);
    setPage(1);
  }

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="space-y-2">
        <FilterPills pills={METHOD_PILLS} active={methodFilter} onSelect={handleMethodChange} />
        <FilterPills pills={TIME_PILLS} active={timeFilter} onSelect={handleTimeChange} />
      </div>

      {/* Summary */}
      {data && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Total spent:{" "}
            <span className="font-semibold text-foreground tabular-nums">
              {formatPHP(data.spending_total)}
            </span>
          </p>
          <p className="text-xs text-muted-foreground">
            {data.meta.total} order{data.meta.total !== 1 ? "s" : ""}
          </p>
        </div>
      )}

      {isLoading ? (
        <div className="space-y-2">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : error ? (
        <p className="text-sm text-destructive">Failed to load order history. Please try again.</p>
      ) : !data?.data.length ? (
        <div className="rounded-xl border border-dashed border-border p-10 text-center">
          <p className="text-sm text-muted-foreground">No orders match the selected filters.</p>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto rounded-xl border border-border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/50">
                  <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Items</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Method</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-muted-foreground">Total</th>
                </tr>
              </thead>
              <tbody className="bg-card">
                {data.data.map((item) => (
                  <OrderRow key={item.id} item={item} />
                ))}
              </tbody>
            </table>
          </div>

          {(data.meta.current_page > 1 || data.meta.current_page < data.meta.last_page) && (
            <div className="flex items-center justify-between">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => p - 1)}
                disabled={data.meta.current_page === 1}
              >
                Previous
              </Button>
              <span className="text-xs text-muted-foreground">
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => p + 1)}
                disabled={data.meta.current_page === data.meta.last_page}
              >
                Next
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Write the test**

Create `app/(portal)/students/[id]/_components/order-history-tab.test.tsx`:

```tsx
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { OrderHistoryTab } from "./order-history-tab";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const mockActivity = {
  spending_total: 150,
  data: [
    {
      id: 1,
      items: [{ name: "Rice Meal", quantity: 1, price: 80, line_total: 80 }],
      total: 80,
      payment_method: "cash",
      created_at: "2026-06-01T10:00:00Z",
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
};

describe("OrderHistoryTab", () => {
  beforeEach(() => {
    server.use(
      http.get(`${API}/portal/students/1/activity`, () => HttpResponse.json(mockActivity)),
    );
  });

  it("renders orders and total spent", async () => {
    render(<OrderHistoryTab studentId={1} />);
    expect(await screen.findByText("Rice Meal x1")).toBeInTheDocument();
    expect(screen.getByText(/PHP 150\.00/i)).toBeInTheDocument();
  });

  it("selecting Cash pill calls API with payment_method=cash", async () => {
    let capturedUrl = "";
    server.use(
      http.get(`${API}/portal/students/1/activity`, ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json(mockActivity);
      }),
    );

    render(<OrderHistoryTab studentId={1} />);
    await screen.findByText("Rice Meal x1");

    await userEvent.click(screen.getByRole("button", { name: "Cash" }));

    await waitFor(() => {
      expect(capturedUrl).toContain("payment_method=cash");
    });
  });

  it("selecting Today pill calls API with from and to date params", async () => {
    let capturedUrl = "";
    server.use(
      http.get(`${API}/portal/students/1/activity`, ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json(mockActivity);
      }),
    );

    render(<OrderHistoryTab studentId={1} />);
    await screen.findByText("Rice Meal x1");

    await userEvent.click(screen.getByRole("button", { name: "Today" }));

    await waitFor(() => {
      expect(capturedUrl).toContain("from=");
      expect(capturedUrl).toContain("to=");
    });
  });

  it("shows empty state when no orders match", async () => {
    server.use(
      http.get(`${API}/portal/students/1/activity`, () =>
        HttpResponse.json({ ...mockActivity, data: [], meta: { ...mockActivity.meta, total: 0 } }),
      ),
    );

    render(<OrderHistoryTab studentId={1} />);

    expect(await screen.findByText(/No orders match/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run the test**

```bash
cd ~/sunbites-portal && npx jest --testPathPattern="order-history-tab.test" --no-coverage
```

Expected: all 4 passed.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/order-history-tab.tsx" "app/(portal)/students/[id]/_components/order-history-tab.test.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): add OrderHistoryTab with payment method and time range pill filters

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: PaymentHistoryTab component

**Files:**
- Create: `app/(portal)/students/[id]/_components/payment-history-tab.tsx`

**Interfaces:**
- `PaymentHistoryTab` props: `studentId: number`
- Lifted directly from the existing `PaymentHistoryTab` function in the old `page.tsx`; no logic changes

- [ ] **Step 1: Create the component**

Create `app/(portal)/students/[id]/_components/payment-history-tab.tsx`:

```tsx
"use client";

import { useQuery } from "@tanstack/react-query";

import { Skeleton } from "@/components/ui/skeleton";
import { studentsApi } from "@/lib/api/portal";
import { cn } from "@/lib/utils";

import type { PaymentHistoryEntry } from "@/types/notification";

interface PaymentHistoryTabProps {
  studentId: number;
}

export function PaymentHistoryTab({ studentId }: PaymentHistoryTabProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["payment-history", studentId],
    queryFn: () => studentsApi.paymentHistory(studentId),
  });

  if (isLoading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (isError) {
    return (
      <p className="text-sm text-muted-foreground">Failed to load payment history.</p>
    );
  }

  const payments = data?.data ?? [];

  if (payments.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-border p-8 text-center">
        <p className="text-sm text-muted-foreground">No payment records found.</p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-border">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border bg-muted/50">
            <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Month</th>
            <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Amount</th>
            <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Status</th>
            <th className="px-4 py-3 text-left text-xs font-medium text-muted-foreground">Paid Date</th>
          </tr>
        </thead>
        <tbody className="bg-card">
          {payments.map((p: PaymentHistoryEntry) => (
            <tr key={p.id} className="border-b border-border last:border-0">
              <td className="px-4 py-3 capitalize">{p.school_month} {p.year}</td>
              <td className="px-4 py-3">
                {new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(p.amount)}
              </td>
              <td className="px-4 py-3">
                <span
                  className={cn(
                    "text-[11px] font-bold px-2 py-0.5 rounded-full border",
                    p.status === "paid"
                      ? "bg-green-100 text-green-700 border-green-300"
                      : "bg-muted text-muted-foreground border-border",
                  )}
                >
                  {p.status === "paid" ? "Paid" : "Unpaid"}
                </span>
              </td>
              <td className="px-4 py-3 text-muted-foreground">
                {p.paid_at ? new Date(p.paid_at).toLocaleDateString("en-PH") : "—"}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -10
```

- [ ] **Step 3: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/_components/payment-history-tab.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): extract PaymentHistoryTab into standalone component

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Rebuild page.tsx — wire everything together

**Files:**
- Replace: `app/(portal)/students/[id]/page.tsx`
- Create: `app/(portal)/students/[id]/_components/student-detail-shell.tsx`

**Interfaces:**
- `page.tsx`: thin default export that reads `params.id` and renders `<StudentDetailShell studentId={id} />`
- `StudentDetailShell`: owns `useQuery` for students list, tab state, and `onPhotoUploaded` callback
- Tabs: `profile | wallet | order-history | payment` (payment shown only for subscription)
- Default tab: `profile`. `?tab=` search param pre-sets initial tab

- [ ] **Step 1: Create StudentDetailShell**

Create `app/(portal)/students/[id]/_components/student-detail-shell.tsx`:

```tsx
"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { studentsApi } from "@/lib/api/portal";
import { useAuthStore } from "@/lib/store/auth";
import { cn } from "@/lib/utils";

import { StudentHeader } from "./student-header";
import { ProfileTab } from "./profile-tab";
import { WalletTab } from "./wallet-tab";
import { OrderHistoryTab } from "./order-history-tab";
import { PaymentHistoryTab } from "./payment-history-tab";

type TabId = "profile" | "wallet" | "order-history" | "payment";

const BASE_TABS: { id: TabId; label: string }[] = [
  { id: "profile", label: "Profile" },
  { id: "wallet", label: "Wallet" },
  { id: "order-history", label: "Order History" },
];

interface StudentDetailShellProps {
  studentId: number;
}

function HeaderSkeleton() {
  return (
    <div className="rounded-xl border border-border bg-card p-5 space-y-3">
      <div className="flex items-start gap-5">
        <Skeleton className="h-20 w-20 rounded-full shrink-0" />
        <div className="flex-1 space-y-2">
          <Skeleton className="h-7 w-48" />
          <Skeleton className="h-4 w-32" />
          <div className="flex gap-2">
            <Skeleton className="h-5 w-20 rounded-full" />
            <Skeleton className="h-5 w-28 rounded-full" />
          </div>
        </div>
      </div>
    </div>
  );
}

export function StudentDetailShell({ studentId }: StudentDetailShellProps) {
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const initialTab = (searchParams.get("tab") as TabId | null) ?? "profile";
  const [activeTab, setActiveTab] = useState<TabId>(initialTab);

  const { data: studentsData, isLoading } = useQuery({
    queryKey: ["students"],
    queryFn: studentsApi.list,
  });

  const updateParent = useAuthStore((s) => s.updateParent);
  const parent = useAuthStore((s) => s.parent);

  useEffect(() => {
    const students = studentsData?.data;
    if (!students || !parent) return;
    const hasSubscription = students.some((s) => s.student_type === "subscription");
    if (parent.has_subscription_student !== hasSubscription) {
      updateParent({ ...parent, has_subscription_student: hasSubscription });
    }
  }, [studentsData, parent, updateParent]);

  const student = studentsData?.data.find((s) => s.id === studentId);
  const isSubscription = student?.student_type === "subscription";

  const tabs = isSubscription
    ? [...BASE_TABS, { id: "payment" as TabId, label: "Payment" }]
    : BASE_TABS;

  function handlePhotoUploaded() {
    queryClient.invalidateQueries({ queryKey: ["students"] });
  }

  return (
    <div className="space-y-6">
      {/* Back link */}
      <Link
        href="/students"
        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ChevronLeft className="h-4 w-4" aria-hidden="true" />
        Students
      </Link>

      {/* Student header */}
      {isLoading ? (
        <HeaderSkeleton />
      ) : student ? (
        <StudentHeader student={student} onPhotoUploaded={handlePhotoUploaded} />
      ) : (
        <p className="text-sm text-muted-foreground">Student not found.</p>
      )}

      {/* Tab bar */}
      {student && (
        <>
          <div className="border-b border-border">
            <nav className="-mb-px flex gap-4" aria-label="Student tabs">
              {tabs.map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setActiveTab(tab.id)}
                  aria-current={activeTab === tab.id ? "page" : undefined}
                  className={cn(
                    "border-b-2 pb-3 text-sm font-medium transition-colors",
                    activeTab === tab.id
                      ? "border-primary text-primary"
                      : "border-transparent text-muted-foreground hover:text-foreground",
                  )}
                >
                  {tab.label}
                </button>
              ))}
            </nav>
          </div>

          {/* Tab content */}
          {activeTab === "profile" && <ProfileTab student={student} />}
          {activeTab === "wallet" && <WalletTab studentId={studentId} />}
          {activeTab === "order-history" && <OrderHistoryTab studentId={studentId} />}
          {activeTab === "payment" && isSubscription && <PaymentHistoryTab studentId={studentId} />}
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Replace page.tsx**

Overwrite `app/(portal)/students/[id]/page.tsx` completely:

```tsx
"use client";

import { use } from "react";
import { StudentDetailShell } from "./_components/student-detail-shell";

export default function StudentDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  return <StudentDetailShell studentId={Number(id)} />;
}
```

- [ ] **Step 3: Verify TypeScript**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors.

- [ ] **Step 4: Run the full frontend test suite**

```bash
cd ~/sunbites-portal && npx jest --no-coverage 2>&1 | tail -20
```

Expected: all existing tests still pass.

- [ ] **Step 5: Run the full backend test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-portal
git add "app/(portal)/students/[id]/page.tsx" "app/(portal)/students/[id]/_components/student-detail-shell.tsx"
git commit -m "$(cat <<'EOF'
feat(portal): rebuild student detail page with Profile, Wallet, Order History, and Payment tabs

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```
