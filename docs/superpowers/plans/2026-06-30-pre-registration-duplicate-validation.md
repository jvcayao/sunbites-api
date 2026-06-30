# Pre-Registration Duplicate Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Before writing any code:** Run `mcp__laravel-boost__search-docs` for each relevant topic. Invoke `/superpowers:tdd` before each implementation step.
>
> **Before marking done:** Invoke `/superpowers:verification-before-completion`, run `laravel-simplifier`, and check off the task in this file.

**Goal:** Add duplicate detection to the portal pre-registration flow and harden the POS approval guard so name + birthday — not student_number — is the primary uniqueness check.

**Architecture:** A shared `StudentDuplicateService` encapsulates the case-insensitive name+birthday queries used by three controllers. Two new public portal endpoints handle real-time checking and form submission. The Kitchen approval method gains a primary name+birthday guard before the existing student_number check.

**Tech Stack:** Laravel 13, PHPUnit 12, MySQL, Sail

## Global Constraints

- All commands run through Sail: `vendor/bin/sail artisan ...`
- All tests use `LazilyRefreshDatabase` — never mock the database
- Every endpoint test calls `actingAs()` with the correct guard, except public endpoints which need no auth
- Public portal endpoints have no `auth:parents` guard — they must use `withoutGlobalScopes()` on all branch-scoped model queries
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP file change
- Student uniqueness criteria: `first_name` + `last_name` + `birthday` — case-insensitive, trimmed — within the same `branch_id`
- `student_number` is nullable and is NOT the primary uniqueness key; it is supplementary at approval only
- Parent checks are soft/informational only — they never block submission
- `pre_registrations` uses `HasBranch` global scope — public endpoint queries must use `withoutGlobalScopes()`

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `database/migrations/2026_06_30_000001_add_duplicate_fields_to_pre_registrations_table.php` | Create | Add 3 new columns |
| `app/Models/PreRegistration.php` | Modify | Add new columns to fillable + casts |
| `database/factories/PreRegistrationFactory.php` | Modify | Add `approved()` factory state |
| `app/Services/StudentDuplicateService.php` | Create | Shared enrolled/pending/parent duplicate checks |
| `app/Http/Controllers/Portal/PreRegistrationCheckController.php` | Create | Real-time check endpoint |
| `app/Http/Controllers/Portal/PreRegistrationController.php` | Create | Portal pre-registration store endpoint |
| `routes/portal-api.php` | Modify | Add two public route groups |
| `app/Http/Controllers/Kitchen/PreRegistrationController.php` | Modify | Add name+birthday primary guard in `approve()` |
| `tests/Feature/Portal/PreRegistrationCheckTest.php` | Create | Tests for check endpoint |
| `tests/Feature/Portal/PreRegistrationStoreTest.php` | Create | Tests for store endpoint |
| `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php` | Create | Tests for hardened approval guard |

---

## Task 1: Migration, Model, and Factory

**Files:**
- Create: `database/migrations/2026_06_30_000001_add_duplicate_fields_to_pre_registrations_table.php`
- Modify: `app/Models/PreRegistration.php`
- Modify: `database/factories/PreRegistrationFactory.php`

**Interfaces:**
- Produces: `PreRegistration` model with `duplicate_check_passed_at`, `parent_email_exists`, `parent_phone_exists` fillable and cast; `PreRegistrationFactory::approved()` state

- [ ] **Step 1: Search docs**

```bash
vendor/bin/sail artisan tinker --execute 'echo "checking schema";'
```

Run in MCP: `mcp__laravel-boost__search-docs` with queries `["migration add columns", "database schema"]`.

- [ ] **Step 2: Write the failing test first**

Create `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php` with just the class shell and one test that verifies the new columns exist on the model:

```php
<?php

namespace Tests\Feature\Kitchen;

use App\Models\PreRegistration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationApprovalDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pre_registration_has_duplicate_check_columns(): void
    {
        $preReg = PreRegistration::factory()->create([
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => true,
            'parent_phone_exists' => false,
        ]);

        $this->assertNotNull($preReg->duplicate_check_passed_at);
        $this->assertTrue($preReg->parent_email_exists);
        $this->assertFalse($preReg->parent_phone_exists);
    }
}
```

- [ ] **Step 3: Run test to confirm it fails**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
```

Expected: FAIL — column does not exist.

- [ ] **Step 4: Generate and write the migration**

```bash
vendor/bin/sail artisan make:migration add_duplicate_fields_to_pre_registrations_table --no-interaction
```

Fill the generated file with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_registrations', function (Blueprint $table) {
            $table->timestamp('duplicate_check_passed_at')->nullable()->after('expires_at');
            $table->boolean('parent_email_exists')->default(false)->after('duplicate_check_passed_at');
            $table->boolean('parent_phone_exists')->default(false)->after('parent_email_exists');
        });
    }

    public function down(): void
    {
        Schema::table('pre_registrations', function (Blueprint $table) {
            $table->dropColumn(['duplicate_check_passed_at', 'parent_email_exists', 'parent_phone_exists']);
        });
    }
};
```

- [ ] **Step 5: Update `PreRegistration` model**

Add to `$fillable` (after `'submitter_ip'`):
```php
'duplicate_check_passed_at',
'parent_email_exists',
'parent_phone_exists',
```

Add to `casts()` return array:
```php
'duplicate_check_passed_at' => 'datetime',
'parent_email_exists' => 'boolean',
'parent_phone_exists' => 'boolean',
```

- [ ] **Step 6: Add `approved()` factory state**

In `database/factories/PreRegistrationFactory.php`, add after the `pending()` method:

```php
public function approved(): static
{
    return $this->state(fn () => ['status' => PreRegistrationStatus::Approved]);
}
```

- [ ] **Step 7: Run migration**

```bash
vendor/bin/sail artisan migrate --no-interaction
```

- [ ] **Step 8: Run the test — expect pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add database/migrations/ app/Models/PreRegistration.php database/factories/PreRegistrationFactory.php tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
git commit -m "feat: add duplicate check columns to pre_registrations and approved factory state"
```

---

## Task 2: StudentDuplicateService

**Files:**
- Create: `app/Services/StudentDuplicateService.php`

**Interfaces:**
- Produces:
  - `StudentDuplicateService::isEnrolledStudent(int $branchId, string $firstName, string $lastName, string $birthday): bool`
  - `StudentDuplicateService::hasPendingPreRegistration(int $branchId, string $firstName, string $lastName, string $birthday): bool`
  - `StudentDuplicateService::parentEmailExists(string $email): bool`
  - `StudentDuplicateService::parentPhoneExists(string $phone): bool`
- Consumed by: Tasks 3, 4, 5

- [ ] **Step 1: Search docs**

Run `mcp__laravel-boost__search-docs` with queries `["eloquent global scope", "without global scopes"]`.

- [ ] **Step 2: Create the service**

```bash
vendor/bin/sail artisan make:class Services/StudentDuplicateService --no-interaction
```

Replace the generated file content with:

```php
<?php

namespace App\Services;

use App\Enums\PreRegistrationStatus;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;

class StudentDuplicateService
{
    public function isEnrolledStudent(int $branchId, string $firstName, string $lastName, string $birthday): bool
    {
        return Student::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereDate('birthday', $birthday)
            ->whereNull('deleted_at')
            ->exists();
    }

    public function hasPendingPreRegistration(int $branchId, string $firstName, string $lastName, string $birthday): bool
    {
        return PreRegistration::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereDate('birthday', $birthday)
            ->where('status', PreRegistrationStatus::Pending)
            ->exists();
    }

    public function parentEmailExists(string $email): bool
    {
        return ParentUser::where('email', $email)->exists();
    }

    public function parentPhoneExists(string $phone): bool
    {
        return ParentUser::where('phone', $phone)->exists();
    }
}
```

- [ ] **Step 3: No dedicated unit test needed** — this service is fully exercised by the feature tests in Tasks 3, 4, and 5 which hit a real database. No mocking.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/StudentDuplicateService.php
git commit -m "feat: add StudentDuplicateService for shared name+birthday duplicate checks"
```

---

## Task 3: Public Real-Time Check Endpoint

**Files:**
- Create: `app/Http/Controllers/Portal/PreRegistrationCheckController.php`
- Modify: `routes/portal-api.php`
- Create: `tests/Feature/Portal/PreRegistrationCheckTest.php`

**Interfaces:**
- Consumes: `StudentDuplicateService` from Task 2
- Produces: `POST /api/v1/portal/pre-registrations/check` — public, rate-limited

- [ ] **Step 1: Search docs**

Run `mcp__laravel-boost__search-docs` with queries `["rate limiting throttle middleware", "route middleware"]`.

- [ ] **Step 2: Write the failing tests**

```bash
vendor/bin/sail artisan make:test --phpunit Portal/PreRegistrationCheckTest --no-interaction
```

Replace generated file with:

```php
<?php

namespace Tests\Feature\Portal;

use App\Enums\PreRegistrationStatus;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ], $overrides);
    }

    public function test_returns_no_duplicate_when_no_match(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
            'parent' => ['email_exists' => false, 'phone_exists' => false],
        ]);
    }

    public function test_detects_enrolled_student_duplicate(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'enrolled'],
        ]);
    }

    public function test_detects_pending_pre_registration_duplicate(): void
    {
        PreRegistration::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'pending'],
        ]);
    }

    public function test_approved_pre_registration_does_not_trigger_duplicate(): void
    {
        PreRegistration::factory()->approved()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
        ]);
    }

    public function test_ignores_soft_deleted_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'deleted_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
        ]);
    }

    public function test_name_matching_is_case_insensitive(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'first_name' => 'juan',
            'last_name' => 'dela cruz',
        ]));

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'enrolled'],
        ]);
    }

    public function test_detects_existing_parent_email(): void
    {
        ParentUser::factory()->create(['email' => 'parent@example.com']);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'email' => 'parent@example.com',
        ]));

        $response->assertOk()->assertJson([
            'parent' => ['email_exists' => true, 'phone_exists' => false],
        ]);
    }

    public function test_detects_existing_parent_phone_when_no_email(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'phone' => '09171234567',
        ]));

        $response->assertOk()->assertJson([
            'parent' => ['email_exists' => false, 'phone_exists' => true],
        ]);
    }

    public function test_response_contains_no_student_identifying_details(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $data = $response->json();
        $this->assertArrayNotHasKey('id', $data['student']);
        $this->assertArrayNotHasKey('name', $data['student']);
        $this->assertArrayNotHasKey('student_number', $data['student']);
    }

    public function test_returns_422_when_required_fields_missing(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations/check', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'first_name', 'last_name', 'birthday']);
    }
}
```

- [ ] **Step 3: Run tests to confirm they fail**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PreRegistrationCheckTest.php
```

Expected: FAIL — route not found.

- [ ] **Step 4: Create the controller**

```bash
vendor/bin/sail artisan make:controller Portal/PreRegistrationCheckController --no-interaction
```

Replace the generated file with:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\StudentDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreRegistrationCheckController extends Controller
{
    public function __construct(
        private readonly StudentDuplicateService $duplicateService,
    ) {}

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'date_format:Y-m-d', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $branchId = $validated['branch_id'];
        $firstName = $validated['first_name'];
        $lastName = $validated['last_name'];
        $birthday = $validated['birthday'];

        $studentStatus = null;
        $isStudentDuplicate = false;

        if ($this->duplicateService->isEnrolledStudent($branchId, $firstName, $lastName, $birthday)) {
            $isStudentDuplicate = true;
            $studentStatus = 'enrolled';
        } elseif ($this->duplicateService->hasPendingPreRegistration($branchId, $firstName, $lastName, $birthday)) {
            $isStudentDuplicate = true;
            $studentStatus = 'pending';
        }

        $emailExists = isset($validated['email'])
            && $this->duplicateService->parentEmailExists($validated['email']);

        $phoneExists = ! isset($validated['email'])
            && isset($validated['phone'])
            && $this->duplicateService->parentPhoneExists($validated['phone']);

        return response()->json([
            'student' => [
                'is_duplicate' => $isStudentDuplicate,
                'status' => $studentStatus,
            ],
            'parent' => [
                'email_exists' => $emailExists,
                'phone_exists' => $phoneExists,
            ],
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/portal-api.php`, add before the existing public auth routes (at the top of the file), after the `use` statements:

```php
use App\Http\Controllers\Portal\PreRegistrationCheckController;
```

Then add a new public route group at the top of the file (before `Route::post('/auth/login', ...)`):

```php
// Pre-registration — public (rate limited)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/pre-registrations/check', [PreRegistrationCheckController::class, 'check']);
});
```

- [ ] **Step 6: Run the tests — expect pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PreRegistrationCheckTest.php
```

Expected: All PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/PreRegistrationCheckController.php routes/portal-api.php tests/Feature/Portal/PreRegistrationCheckTest.php
git commit -m "feat: add public real-time pre-registration duplicate check endpoint"
```

---

## Task 4: Portal Pre-Registration Store Endpoint

**Files:**
- Create: `app/Http/Controllers/Portal/PreRegistrationController.php`
- Modify: `routes/portal-api.php`
- Create: `tests/Feature/Portal/PreRegistrationStoreTest.php`

**Interfaces:**
- Consumes: `StudentDuplicateService` from Task 2, `SystemConfiguration::getValue()`, `PreRegistrationContact` model
- Produces: `POST /api/v1/portal/pre-registrations` — public, rate-limited, returns 201 with warnings

- [ ] **Step 1: Search docs**

Run `mcp__laravel-boost__search-docs` with queries `["validation rules required_if", "eloquent create relationships"]`.

- [ ] **Step 2: Write the failing tests**

```bash
vendor/bin/sail artisan make:test --phpunit Portal/PreRegistrationStoreTest --no-interaction
```

Replace generated file with:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SystemConfigurationSeeder::class]);
        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'grade_level' => 'Grade 3',
            'enrollment_type' => 'non_subscription',
            'signatory_name' => 'Maria dela Cruz',
            'contacts' => [
                [
                    'full_name' => 'Maria dela Cruz',
                    'relationship' => 'Mother',
                    'phone' => '09171234567',
                    'address' => '123 Main St',
                    'email' => null,
                    'is_primary' => true,
                ],
            ],
        ], $overrides);
    }

    public function test_creates_pre_registration_when_no_duplicate(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload());

        $response->assertCreated();
        $this->assertDatabaseHas('pre_registrations', [
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'status' => 'pending',
        ]);
    }

    public function test_sets_duplicate_check_passed_at_on_created_record(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $preReg = PreRegistration::first();
        $this->assertNotNull($preReg->duplicate_check_passed_at);
    }

    public function test_blocks_when_student_already_enrolled(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.student.0', fn ($msg) => str_contains($msg, 'already enrolled'));
    }

    public function test_blocks_when_pending_pre_registration_exists(): void
    {
        PreRegistration::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.student.0', fn ($msg) => str_contains($msg, 'already pending'));
    }

    public function test_does_not_block_when_rejected_pre_registration_exists(): void
    {
        PreRegistration::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'status' => 'rejected',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertCreated();
    }

    public function test_name_matching_is_case_insensitive(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'first_name' => 'juan',
            'last_name' => 'dela cruz',
        ]))->assertUnprocessable();
    }

    public function test_returns_warning_when_parent_email_exists(): void
    {
        ParentUser::factory()->create(['email' => 'maria@example.com']);

        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'contacts' => [[
                'full_name' => 'Maria dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
                'is_primary' => true,
            ]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('warnings.parent_email_exists', true)
            ->assertJsonPath('warnings.parent_phone_exists', false);
    }

    public function test_returns_warning_when_parent_phone_exists_and_no_email(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('warnings.parent_email_exists', false)
            ->assertJsonPath('warnings.parent_phone_exists', true);
    }

    public function test_sets_parent_email_exists_flag_on_record(): void
    {
        ParentUser::factory()->create(['email' => 'maria@example.com']);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'contacts' => [[
                'full_name' => 'Maria dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
                'is_primary' => true,
            ]],
        ]))->assertCreated();

        $this->assertTrue(PreRegistration::first()->parent_email_exists);
    }

    public function test_sets_parent_phone_exists_flag_on_record(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $this->assertTrue(PreRegistration::first()->parent_phone_exists);
    }

    public function test_creates_contact_records(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $this->assertDatabaseHas('pre_registration_contacts', [
            'full_name' => 'Maria dela Cruz',
            'relationship' => 'Mother',
            'is_primary' => true,
        ]);
    }

    public function test_returns_422_when_required_fields_missing(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'first_name', 'last_name', 'birthday', 'grade_level', 'enrollment_type', 'signatory_name', 'contacts']);
    }
}
```

- [ ] **Step 3: Run tests to confirm they fail**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PreRegistrationStoreTest.php
```

Expected: FAIL — route not found.

- [ ] **Step 4: Create the controller**

```bash
vendor/bin/sail artisan make:controller Portal/PreRegistrationController --no-interaction
```

Replace the generated file with:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Enums\PreRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\PreRegistration;
use App\Models\SystemConfiguration;
use App\Services\StudentDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreRegistrationController extends Controller
{
    public function __construct(
        private readonly StudentDuplicateService $duplicateService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'enrollment_type' => ['required', 'in:subscription,non_subscription'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'subscription_start_month' => ['required_if:enrollment_type,subscription', 'nullable', 'string'],
            'subscription_start_year' => ['required_if:enrollment_type,subscription', 'nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required_if:enrollment_type,subscription', 'nullable', 'string'],
            'subscription_end_year' => ['required_if:enrollment_type,subscription', 'nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'signatory_name' => ['required', 'string', 'max:255'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.full_name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'max:30'],
            'contacts.*.address' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        $branchId = $validated['branch_id'];
        $firstName = $validated['first_name'];
        $lastName = $validated['last_name'];
        $birthday = $validated['birthday'];

        if ($this->duplicateService->isEnrolledStudent($branchId, $firstName, $lastName, $birthday)) {
            return response()->json([
                'message' => 'A student with these details is already enrolled.',
                'errors' => [
                    'student' => [
                        "A student named {$firstName} {$lastName} (born {$birthday}) is already enrolled at this branch.",
                    ],
                ],
            ], 422);
        }

        if ($this->duplicateService->hasPendingPreRegistration($branchId, $firstName, $lastName, $birthday)) {
            return response()->json([
                'message' => 'A pre-registration for this student is already pending review.',
                'errors' => [
                    'student' => [
                        "A pre-registration for {$firstName} {$lastName} (born {$birthday}) is already pending review.",
                    ],
                ],
            ], 422);
        }

        $primaryContact = collect($validated['contacts'])->firstWhere('is_primary', true)
            ?? $validated['contacts'][0];

        $primaryEmail = $primaryContact['email'] ?? null;
        $primaryPhone = $primaryContact['phone'] ?? null;

        $emailExists = $primaryEmail && $this->duplicateService->parentEmailExists($primaryEmail);
        $phoneExists = ! $primaryEmail && $primaryPhone && $this->duplicateService->parentPhoneExists($primaryPhone);

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

        $preRegistration = PreRegistration::create([
            'branch_id' => $branchId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'student_number' => $validated['student_number'] ?? null,
            'grade_level' => $validated['grade_level'],
            'section' => $validated['section'] ?? null,
            'birthday' => $birthday,
            'enrollment_type' => $validated['enrollment_type'],
            'allergies' => isset($validated['allergies']) ? strip_tags($validated['allergies']) : null,
            'notes' => isset($validated['notes']) ? strip_tags($validated['notes']) : null,
            'subscription_start_month' => $validated['subscription_start_month'] ?? null,
            'subscription_start_year' => $validated['subscription_start_year'] ?? null,
            'subscription_end_month' => $validated['subscription_end_month'] ?? null,
            'subscription_end_year' => $validated['subscription_end_year'] ?? null,
            'signatory_name' => $validated['signatory_name'],
            'acknowledged_at' => now(),
            'status' => PreRegistrationStatus::Pending,
            'submitter_ip' => $request->ip(),
            'expires_at' => now()->addDays($expiryDays),
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => $emailExists,
            'parent_phone_exists' => $phoneExists,
        ]);

        $preRegistration->contacts()->createMany($validated['contacts']);

        return response()->json([
            'data' => [
                'id' => $preRegistration->id,
                'status' => $preRegistration->status->value,
            ],
            'warnings' => [
                'parent_email_exists' => $emailExists,
                'parent_phone_exists' => $phoneExists,
            ],
        ], 201);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/portal-api.php`, add the import at the top:

```php
use App\Http\Controllers\Portal\PreRegistrationController;
```

Add a new public route group (after the check route group):

```php
// Pre-registration submit — public (rate limited)
Route::middleware(['throttle:5,10'])->group(function () {
    Route::post('/pre-registrations', [PreRegistrationController::class, 'store']);
});
```

- [ ] **Step 6: Run the tests — expect pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PreRegistrationStoreTest.php
```

Expected: All PASS.

- [ ] **Step 7: Also run the check tests to ensure no regression**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Portal/PreRegistrationCheckTest.php
```

Expected: All PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/PreRegistrationController.php routes/portal-api.php tests/Feature/Portal/PreRegistrationStoreTest.php
git commit -m "feat: add portal pre-registration store endpoint with duplicate validation"
```

---

## Task 5: Harden POS Approval Duplicate Guard

**Files:**
- Modify: `app/Http/Controllers/Kitchen/PreRegistrationController.php`
- Modify: `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php`

**Interfaces:**
- Consumes: `StudentDuplicateService` from Task 2 (injected into existing constructor)
- Modifies: `approve()` — adds name+birthday as primary guard before student_number check

- [ ] **Step 1: Search docs**

Run `mcp__laravel-boost__search-docs` with queries `["database transactions lock for update", "abort_if"]`.

- [ ] **Step 2: Write the failing tests**

Replace the contents of `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php` (the file from Task 1) with the full test suite:

```php
<?php

namespace Tests\Feature\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Mail\PreRegistrationApprovedMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreRegistrationApprovalDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function preRegWithContact(array $preRegOverrides = [], array $contactOverrides = []): PreRegistration
    {
        $preReg = PreRegistration::factory()->pending()->create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'student_number' => null,
        ], $preRegOverrides));

        PreRegistrationContact::factory()->primary()->create(array_merge([
            'pre_registration_id' => $preReg->id,
        ], $contactOverrides));

        return $preReg;
    }

    public function test_pre_registration_has_duplicate_check_columns(): void
    {
        $preReg = PreRegistration::factory()->create([
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => true,
            'parent_phone_exists' => false,
        ]);

        $this->assertNotNull($preReg->duplicate_check_passed_at);
        $this->assertTrue($preReg->parent_email_exists);
        $this->assertFalse($preReg->parent_phone_exists);
    }

    public function test_approval_blocked_when_name_birthday_matches_enrolled_student_and_no_student_number(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $preReg = $this->preRegWithContact(['student_number' => null]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_blocked_when_name_birthday_matches_enrolled_student_with_student_number_set(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $preReg = $this->preRegWithContact(['student_number' => 'ABC-2025-001']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_blocked_when_student_number_matches_enrolled_student(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'ABC-2025-001',
        ]);

        $preReg = $this->preRegWithContact([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '2014-06-20',
            'student_number' => 'ABC-2025-001',
        ]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_proceeds_when_no_student_number_and_no_name_birthday_match(): void
    {
        Mail::fake();

        $preReg = $this->preRegWithContact(['student_number' => null]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
        ]);
    }

    public function test_approval_links_student_to_existing_parent_via_email(): void
    {
        Mail::fake();

        $parent = ParentUser::factory()->create(['email' => 'maria@example.com']);

        $preReg = $this->preRegWithContact([], ['email' => 'maria@example.com']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $student = Student::where('first_name', 'Juan')->first();
        $this->assertTrue($parent->students()->where('student_id', $student->id)->exists());
    }

    public function test_approval_creates_new_parent_account_when_email_not_found(): void
    {
        Mail::fake();

        $preReg = $this->preRegWithContact([], ['email' => 'new@example.com']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('parents', ['email' => 'new@example.com']);
    }
}
```

- [ ] **Step 3: Run tests to confirm failures**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
```

Expected: The approval-blocking tests fail (name+birthday check doesn't exist yet). The column test passes.

- [ ] **Step 4: Inject `StudentDuplicateService` into `Kitchen\PreRegistrationController`**

In `app/Http/Controllers/Kitchen/PreRegistrationController.php`, update the constructor:

```php
public function __construct(
    private readonly EnrollmentService $enrollmentService,
    private readonly ParentProvisioningService $provisioningService,
    private readonly \App\Services\StudentDuplicateService $duplicateService,
) {}
```

Add the import at the top:

```php
use App\Services\StudentDuplicateService;
```

- [ ] **Step 5: Add name+birthday primary guard in `approve()`**

In `approve()`, replace the existing duplicate-check block (lines 182–189):

```php
// Current code to REPLACE:
if ($locked->student_number) {
    $duplicate = Student::withoutGlobalScopes()
        ->where('branch_id', $locked->branch_id)
        ->where('student_number', $locked->student_number)
        ->whereNull('deleted_at')
        ->exists();

    abort_if($duplicate, 422, 'A student with this student number already exists. Please resolve the duplicate before approving.');
}
```

With:

```php
// PRIMARY: name + birthday — always runs, regardless of student_number
abort_if(
    $this->duplicateService->isEnrolledStudent(
        $locked->branch_id,
        $locked->first_name,
        $locked->last_name,
        $locked->birthday->toDateString(),
    ),
    422,
    'A student with this name and birthday is already enrolled. Please resolve the duplicate before approving.'
);

// SUPPLEMENTARY: student_number — only when provided
if ($locked->student_number) {
    $numberDuplicate = Student::withoutGlobalScopes()
        ->where('branch_id', $locked->branch_id)
        ->where('student_number', $locked->student_number)
        ->whereNull('deleted_at')
        ->exists();

    abort_if($numberDuplicate, 422, 'A student with this student number already exists. Please resolve the duplicate before approving.');
}
```

- [ ] **Step 6: Run the tests — expect all pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
```

Expected: All PASS.

- [ ] **Step 7: Run the full existing PreRegistrationTest to confirm no regression**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/PreRegistrationTest.php
```

Expected: All PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/PreRegistrationController.php tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php
git commit -m "feat: add name+birthday primary duplicate guard to pre-registration approval"
```

---

## Task 6: Full Suite Verification

- [ ] **Step 1: Invoke `/superpowers:verification-before-completion`** before claiming done.

- [ ] **Step 2: Run the full test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All PASS. No regressions.

- [ ] **Step 3: Run laravel-simplifier**

Invoke `laravel-simplifier` agent on the four new/modified PHP files:
- `app/Services/StudentDuplicateService.php`
- `app/Http/Controllers/Portal/PreRegistrationCheckController.php`
- `app/Http/Controllers/Portal/PreRegistrationController.php`
- `app/Http/Controllers/Kitchen/PreRegistrationController.php`

Apply any suggested simplifications, run tests again to confirm still passing.

- [ ] **Step 4: Final commit if simplifier made changes**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add -p
git commit -m "refactor: simplify pre-registration duplicate validation code"
```

- [ ] **Step 5: Mark this plan complete**

Check off all tasks above in this file, then update `docs/superpowers/plans/2026-06-30-pre-registration-duplicate-validation.md` with all checkboxes ticked.
