# Subscription → Non-Subscription Downgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the naive student type-switch with a proper downgrade flow that hard-deletes unpaid monthly payments, preserves paid history, adds a manual void-payment action for current/future paid months, fixes the stale list cache bug, and surfaces historical ex-subscriber data in the subscription report.

**Architecture:** New `SubscriptionDowngradeController` handles preview + execute endpoints backed by a dedicated `DowngradeStudentSubscriptionAction`. A new `void` endpoint is added to `PaymentController`. Existing methods get defensive guards. Three backend changes flow into POS and portal frontend updates.

**Tech Stack:** Laravel 13, PHPUnit 12, bavix/laravel-wallet, spatie/laravel-activitylog, Next.js App Router (React 19), TanStack Query, Tailwind v4, TypeScript strict mode.

## Global Constraints

- All commands via `vendor/bin/sail` — never run PHP/Artisan/Composer directly
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP file change
- Run `vendor/bin/sail artisan test --compact` with a specific filter after every backend task
- Use `LazilyRefreshDatabase` + `PermissionSeeder` on every backend Feature test
- Always `Sanctum::actingAs($user, ['staff'])` + `withHeaders(['X-Branch-Id' => $branch->id])` for kitchen API tests
- Never mock the database — hit real DB in all backend tests
- Before writing any backend implementation: invoke `/superpowers:tdd` and search docs with `mcp__laravel-boost__search-docs`
- Before marking the entire plan done: invoke `/superpowers:verification-before-completion` then run `laravel-simplifier` on all changed PHP files

---

## File Map

### New files
| File | Purpose |
|---|---|
| `database/migrations/XXXX_add_voided_fields_to_student_monthly_payments_table.php` | Adds `voided_at`, `voided_by`, `void_reason` |
| `app/Actions/DowngradeStudentSubscriptionAction.php` | Business logic for the downgrade transaction |
| `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php` | Preview + execute endpoints |
| `tests/Feature/Kitchen/SubscriptionDowngradeTest.php` | Preview + execute tests |
| `tests/Feature/Kitchen/VoidPaymentTest.php` | Void payment tests |
| `tests/Feature/Reports/SubscriptionReportHistoricalTest.php` | Historical section tests |
| `tests/Feature/Portal/StudentPaymentHistoryExSubscriberTest.php` | Portal payment history after type switch |

### Modified files
| File | Change |
|---|---|
| `app/Models/StudentMonthlyPayment.php` | Add fillable + casts for voided fields |
| `app/Http/Controllers/Kitchen/PaymentController.php` | Guards on toggle/record; voided fields in index() |
| `app/Http/Controllers/Kitchen/BillingReportController.php` | Exclude voided by default; allow voided filter |
| `app/Http/Controllers/Kitchen/SubscriptionReportController.php` | Add historical_data |
| `app/Http/Controllers/Portal/StudentPaymentHistoryController.php` | Remove subscription guard; filter voided |
| `routes/kitchen-api.php` | Register 3 new routes |
| `tests/Feature/Kitchen/PaymentControllerTest.php` | Add guard tests |
| `tests/Feature/Kitchen/SubscriptionReportTest.php` | Extend with historical tests (or new file) |
| `~/sunbites-pos/types/student.ts` | PaymentStatus, MonthlyPayment, DowngradePreview types |
| `~/sunbites-pos/lib/api/students.ts` | preview, downgrade, voidPayment methods |
| `~/sunbites-pos/lib/api/reports.ts` | HistoricalSubscriberRow type + subscriptionUsage return type |
| `~/sunbites-pos/app/(kitchen)/students/[id]/page.tsx` | DowngradeConfirmDialog; voided payment rows; Void button |
| `~/sunbites-pos/app/(kitchen)/reports/subscription/page.tsx` | Former Subscribers section |
| `~/sunbites-pos/app/(kitchen)/reports/billing/page.tsx` | StatusBadge 3-state; voided filter option |
| `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.tsx` | Filter voided |

---

## Task 1: Migration + Model

**Files:**
- Create: `database/migrations/XXXX_add_voided_fields_to_student_monthly_payments_table.php`
- Modify: `app/Models/StudentMonthlyPayment.php`

**Interfaces:**
- Produces: `StudentMonthlyPayment` with `voided_at` (datetime), `voided_by` (int|null), `void_reason` (string|null), `status` accepting `"voided"`

- [ ] **Step 1: Search docs**
  ```
  mcp__laravel-boost__search-docs queries=["migrations alter table", "model fillable casts"]
  ```

- [ ] **Step 2: Create the migration**
  ```bash
  vendor/bin/sail artisan make:migration add_voided_fields_to_student_monthly_payments_table --no-interaction
  ```
  Open the generated file and replace its `up()` and `down()` bodies:
  ```php
  public function up(): void
  {
      Schema::table('student_monthly_payments', function (Blueprint $table) {
          $table->timestamp('voided_at')->nullable()->after('recorded_by');
          $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete()->after('voided_at');
          $table->string('void_reason')->nullable()->after('voided_by');
      });
  }

  public function down(): void
  {
      Schema::table('student_monthly_payments', function (Blueprint $table) {
          $table->dropConstrainedForeignId('voided_by');
          $table->dropColumn(['voided_at', 'void_reason']);
      });
  }
  ```

- [ ] **Step 3: Run the migration**
  ```bash
  vendor/bin/sail artisan migrate
  ```
  Expected: `Migrating: XXXX_add_voided_fields... Done.`

- [ ] **Step 4: Update `StudentMonthlyPayment` model**

  Open `app/Models/StudentMonthlyPayment.php` and update `$fillable` and `casts()`:

  ```php
  protected $fillable = [
      'student_id',
      'school_month',
      'year',
      'status',
      'amount',
      'recorded_at',
      'recorded_by',
      'voided_at',   // new
      'voided_by',   // new
      'void_reason', // new
  ];

  protected function casts(): array
  {
      return [
          'school_month' => SchoolMonth::class,
          'year'         => 'integer',
          'amount'       => 'decimal:2',
          'recorded_at'  => 'datetime',
          'voided_at'    => 'datetime', // new
      ];
  }
  ```

- [ ] **Step 5: Run pint**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Step 6: Verify migration with schema tool**
  ```
  mcp__laravel-boost__database-schema table="student_monthly_payments"
  ```
  Confirm `voided_at`, `voided_by`, `void_reason` columns exist.

- [ ] **Step 7: Commit**
  ```bash
  git add database/migrations/ app/Models/StudentMonthlyPayment.php
  git commit -m "feat: add voided fields to student_monthly_payments"
  ```

---

## Task 2: Preview Endpoint

**Files:**
- Create: `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php`
- Create: `tests/Feature/Kitchen/SubscriptionDowngradeTest.php`
- Modify: `routes/kitchen-api.php`

**Interfaces:**
- Consumes: `StudentMonthlyPayment` with `voided_at`, `voided_by`, `void_reason` (Task 1)
- Produces: `GET /api/v1/students/{student}/subscription-downgrade-preview` returning `DowngradePreview` shape

- [ ] **Step 1: Invoke TDD skill**
  ```
  /superpowers:tdd
  ```

- [ ] **Step 2: Search docs**
  ```
  mcp__laravel-boost__search-docs queries=["eloquent collections", "carbon date comparison"] packages=["laravel/framework"]
  ```

- [ ] **Step 3: Create the test file**
  ```bash
  vendor/bin/sail artisan make:test --phpunit Kitchen/SubscriptionDowngradeTest --no-interaction
  ```

- [ ] **Step 4: Write the preview tests**

  Open `tests/Feature/Kitchen/SubscriptionDowngradeTest.php` and replace its contents:

  ```php
  <?php

  namespace Tests\Feature\Kitchen;

  use App\Models\Branch;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use App\Models\User;
  use Carbon\Carbon;
  use Database\Seeders\PermissionSeeder;
  use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
  use Laravel\Sanctum\Sanctum;
  use Tests\TestCase;

  class SubscriptionDowngradeTest extends TestCase
  {
      use LazilyRefreshDatabase;

      private User $admin;
      private Branch $branch;
      private Student $student;

      protected function setUp(): void
      {
          parent::setUp();
          $this->seed(PermissionSeeder::class);

          $this->branch = Branch::factory()->create(['is_active' => true]);

          $this->admin = User::factory()->create();
          $this->admin->assignRole('admin');
          $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

          $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
      }

      private function asAdmin(): static
      {
          Sanctum::actingAs($this->admin, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      private function asUserWithRole(string $role): static
      {
          $user = User::factory()->create();
          $user->assignRole($role);
          $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
          Sanctum::actingAs($user, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      // -----------------------------------------------------------------------
      // preview
      // -----------------------------------------------------------------------

      public function test_admin_can_preview_downgrade_with_mixed_payments(): void
      {
          $now = now();
          // Past paid month (cannot void)
          StudentMonthlyPayment::factory()->paid()->create([
              'student_id'  => $this->student->id,
              'school_month' => 'june',
              'year'         => $now->year - 1,
              'amount'       => 2970,
          ]);
          // Current month paid (voidable)
          $currentSchoolMonth = \App\Enums\SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';
          StudentMonthlyPayment::factory()->paid()->create([
              'student_id'  => $this->student->id,
              'school_month' => $currentSchoolMonth,
              'year'         => $now->year,
              'amount'       => 2970,
          ]);
          // Future unpaid (to be deleted)
          StudentMonthlyPayment::factory()->unpaid()->create([
              'student_id'  => $this->student->id,
              'school_month' => 'march',
              'year'         => $now->year + 1,
              'amount'       => 945,
          ]);

          $response = $this->asAdmin()->getJson(
              "/api/v1/students/{$this->student->id}/subscription-downgrade-preview"
          );

          $response->assertOk();
          $response->assertJsonStructure([
              'paid_months_retained',
              'paid_voidable_months',
              'unpaid_months_to_delete',
              'unpaid_months_to_delete_count',
              'wallet_balance',
          ]);
          $this->assertCount(1, $response->json('paid_months_retained'));
          $this->assertCount(1, $response->json('paid_voidable_months'));
          $this->assertCount(1, $response->json('unpaid_months_to_delete'));
          $this->assertEquals(1, $response->json('unpaid_months_to_delete_count'));
      }

      public function test_supervisor_can_access_preview(): void
      {
          $response = $this->asUserWithRole('supervisor')->getJson(
              "/api/v1/students/{$this->student->id}/subscription-downgrade-preview"
          );
          $response->assertOk();
      }

      public function test_preview_returns_422_for_non_subscription_student(): void
      {
          $nonSub = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

          $response = $this->asAdmin()->getJson(
              "/api/v1/students/{$nonSub->id}/subscription-downgrade-preview"
          );

          $response->assertUnprocessable();
      }
  }
  ```

- [ ] **Step 5: Run tests — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionDowngradeTest
  ```
  Expected: FAIL — `Route [api/v1/students/{student}/subscription-downgrade-preview] not defined`

- [ ] **Step 6: Create the controller**
  ```bash
  vendor/bin/sail artisan make:controller Kitchen/SubscriptionDowngradeController --no-interaction
  ```

- [ ] **Step 7: Implement `preview()` in the controller**

  Open `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php`:

  ```php
  <?php

  namespace App\Http\Controllers\Kitchen;

  use App\Enums\SchoolMonth;
  use App\Http\Controllers\Controller;
  use App\Models\Student;
  use Illuminate\Http\JsonResponse;

  class SubscriptionDowngradeController extends Controller
  {
      public function preview(Student $student): JsonResponse
      {
          abort_unless(
              $student->student_type === \App\Enums\StudentType::Subscription,
              422,
              'Student is not a subscription student.'
          );

          $now = now()->startOfMonth();

          $payments = $student->monthlyPayments()->get();

          $paidRetained   = [];
          $paidVoidable   = [];
          $unpaidToDelete = [];

          foreach ($payments as $payment) {
              $paymentDate = \Carbon\Carbon::createFromDate(
                  $payment->year,
                  $payment->school_month->toMonthNumber(),
                  1
              )->startOfMonth();

              if ($payment->status === 'paid') {
                  if ($paymentDate->lt($now)) {
                      $paidRetained[] = [
                          'id'           => $payment->id,
                          'school_month' => $payment->school_month->value,
                          'year'         => $payment->year,
                          'amount'       => (float) $payment->amount,
                          'label'        => $payment->school_month->label().' '.$payment->year,
                      ];
                  } else {
                      $paidVoidable[] = [
                          'id'           => $payment->id,
                          'school_month' => $payment->school_month->value,
                          'year'         => $payment->year,
                          'amount'       => (float) $payment->amount,
                          'label'        => $payment->school_month->label().' '.$payment->year,
                      ];
                  }
              } else {
                  $unpaidToDelete[] = $payment->school_month->label().' '.$payment->year;
              }
          }

          $walletBalance = $student->wallet ? (float) $student->wallet->balanceFloatNum : 0.0;

          return response()->json([
              'paid_months_retained'       => $paidRetained,
              'paid_voidable_months'       => $paidVoidable,
              'unpaid_months_to_delete'    => $unpaidToDelete,
              'unpaid_months_to_delete_count' => count($unpaidToDelete),
              'wallet_balance'             => $walletBalance,
          ]);
      }
  }
  ```

- [ ] **Step 8: Register the preview route**

  Open `routes/kitchen-api.php`. In the `role:admin|manager|supervisor` group, directly after the existing `Route::patch('/students/{student}/type', ...)` line, add:

  ```php
  Route::get('/students/{student}/subscription-downgrade-preview', [SubscriptionDowngradeController::class, 'preview']);
  ```

  Also add the `use` statement at the top of the file:
  ```php
  use App\Http\Controllers\Kitchen\SubscriptionDowngradeController;
  ```

- [ ] **Step 9: Run tests — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionDowngradeTest
  ```
  Expected: 3 tests pass.

- [ ] **Step 10: Run pint**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Step 11: Commit**
  ```bash
  git add app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php routes/kitchen-api.php tests/Feature/Kitchen/SubscriptionDowngradeTest.php
  git commit -m "feat: add subscription downgrade preview endpoint"
  ```

---

## Task 3: Downgrade Action + Execute Endpoint

**Files:**
- Create: `app/Actions/DowngradeStudentSubscriptionAction.php`
- Modify: `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php`
- Modify: `routes/kitchen-api.php`
- Modify: `tests/Feature/Kitchen/SubscriptionDowngradeTest.php`

**Interfaces:**
- Consumes: `SubscriptionDowngradeController` (Task 2), `StudentMonthlyPayment` model (Task 1)
- Produces: `POST /api/v1/students/{student}/downgrade-subscription` — deletes unpaid, changes type, logs activity

- [ ] **Step 1: Search docs**
  ```
  mcp__laravel-boost__search-docs queries=["database transaction", "activity log", "eloquent delete"] packages=["laravel/framework", "spatie/laravel-activitylog"]
  ```

- [ ] **Step 2: Add execute tests** to `tests/Feature/Kitchen/SubscriptionDowngradeTest.php`

  Append these test methods inside the class:

  ```php
  // -----------------------------------------------------------------------
  // execute
  // -----------------------------------------------------------------------

  public function test_admin_can_downgrade_subscription_student(): void
  {
      $now = now();
      $currentMonth = \App\Enums\SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

      StudentMonthlyPayment::factory()->paid()->create([
          'student_id'   => $this->student->id,
          'school_month' => 'june',
          'year'         => $now->year - 1,
          'amount'       => 2970,
      ]);
      $unpaid = StudentMonthlyPayment::factory()->unpaid()->create([
          'student_id'   => $this->student->id,
          'school_month' => $currentMonth,
          'year'         => $now->year,
          'amount'       => 2970,
      ]);

      $response = $this->asAdmin()->postJson(
          "/api/v1/students/{$this->student->id}/downgrade-subscription"
      );

      $response->assertOk();
      $response->assertJsonPath('student_type', 'non_subscription');

      // Unpaid must be hard-deleted
      $this->assertDatabaseMissing('student_monthly_payments', ['id' => $unpaid->id]);

      // Past paid must remain
      $this->assertDatabaseCount('student_monthly_payments', 1);
      $this->assertDatabaseHas('students', [
          'id'           => $this->student->id,
          'student_type' => 'non_subscription',
      ]);
  }

  public function test_downgrade_logs_activity_with_deleted_months(): void
  {
      $now = now();
      $currentMonth = \App\Enums\SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

      StudentMonthlyPayment::factory()->unpaid()->create([
          'student_id'   => $this->student->id,
          'school_month' => $currentMonth,
          'year'         => $now->year,
          'amount'       => 2970,
      ]);

      $this->asAdmin()->postJson(
          "/api/v1/students/{$this->student->id}/downgrade-subscription"
      );

      $this->assertDatabaseHas('activity_log', [
          'subject_type' => Student::class,
          'subject_id'   => $this->student->id,
          'description'  => 'students.downgraded_to_non_subscription',
      ]);
  }

  public function test_downgrade_fails_if_student_is_not_subscription(): void
  {
      $nonSub = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

      $response = $this->asAdmin()->postJson(
          "/api/v1/students/{$nonSub->id}/downgrade-subscription"
      );

      $response->assertUnprocessable();
  }

  public function test_supervisor_cannot_execute_downgrade(): void
  {
      $response = $this->asUserWithRole('supervisor')->postJson(
          "/api/v1/students/{$this->student->id}/downgrade-subscription"
      );

      $response->assertForbidden();
  }
  ```

- [ ] **Step 3: Run tests — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionDowngradeTest
  ```
  Expected: 4 new tests fail — route not found.

- [ ] **Step 4: Create the Action class**
  ```bash
  vendor/bin/sail artisan make:class Actions/DowngradeStudentSubscriptionAction --no-interaction
  ```

  Open `app/Actions/DowngradeStudentSubscriptionAction.php` and replace entirely:

  ```php
  <?php

  namespace App\Actions;

  use App\Enums\StudentType;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use App\Models\User;
  use Illuminate\Support\Facades\DB;

  class DowngradeStudentSubscriptionAction
  {
      public function execute(Student $student, User $causer): Student
      {
          return DB::transaction(function () use ($student, $causer): Student {
              $unpaidPayments = $student->monthlyPayments()
                  ->where('status', 'unpaid')
                  ->get();

              $deletedMonthLabels = $unpaidPayments
                  ->map(fn ($p) => $p->school_month->label().' '.$p->year)
                  ->values()
                  ->all();

              $paidMonthLabels = $student->monthlyPayments()
                  ->where('status', 'paid')
                  ->get()
                  ->map(fn ($p) => $p->school_month->label().' '.$p->year)
                  ->values()
                  ->all();

              StudentMonthlyPayment::whereIn('id', $unpaidPayments->pluck('id'))->delete();

              $student->update(['student_type' => StudentType::NonSubscription]);

              activity('students')
                  ->causedBy($causer)
                  ->performedOn($student)
                  ->withProperties([
                      'deleted_months'       => $deletedMonthLabels,
                      'deleted_count'        => count($deletedMonthLabels),
                      'paid_months_retained' => $paidMonthLabels,
                      'note'                 => 'Unpaid monthly payments were removed. Paid months are retained for history.',
                  ])
                  ->log('students.downgraded_to_non_subscription');

              return $student->fresh();
          });
      }
  }
  ```

- [ ] **Step 5: Add `execute()` to `SubscriptionDowngradeController`**

  Add a new method to `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php`:

  ```php
  use App\Actions\DowngradeStudentSubscriptionAction;
  use App\Http\Resources\StudentResource;
  use Illuminate\Http\Request;

  public function execute(Request $request, Student $student, DowngradeStudentSubscriptionAction $action): JsonResponse
  {
      abort_unless(
          $student->student_type === \App\Enums\StudentType::Subscription,
          422,
          'Student is not a subscription student.'
      );

      $student = $action->execute($student, $request->user());

      return response()->json(new StudentResource($student));
  }
  ```

- [ ] **Step 6: Register the execute route**

  Open `routes/kitchen-api.php`. In the `role:admin|manager` group (the payment toggle group), add:

  ```php
  Route::post('/students/{student}/downgrade-subscription', [SubscriptionDowngradeController::class, 'execute']);
  ```

- [ ] **Step 7: Run tests — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionDowngradeTest
  ```
  Expected: 7 tests pass.

- [ ] **Step 8: Run pint**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Step 9: Commit**
  ```bash
  git add app/Actions/DowngradeStudentSubscriptionAction.php app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php routes/kitchen-api.php tests/Feature/Kitchen/SubscriptionDowngradeTest.php
  git commit -m "feat: add subscription downgrade execute endpoint"
  ```

---

## Task 4: Void Payment Endpoint

**Files:**
- Modify: `app/Http/Controllers/Kitchen/PaymentController.php`
- Create: `tests/Feature/Kitchen/VoidPaymentTest.php`
- Modify: `routes/kitchen-api.php`

**Interfaces:**
- Consumes: `StudentMonthlyPayment` with voided fields (Task 1)
- Produces: `PATCH /api/v1/students/{student}/payments/{payment}/void` — sets status=voided with audit fields

- [ ] **Step 1: Write failing tests**
  ```bash
  vendor/bin/sail artisan make:test --phpunit Kitchen/VoidPaymentTest --no-interaction
  ```

  Open `tests/Feature/Kitchen/VoidPaymentTest.php` and replace:

  ```php
  <?php

  namespace Tests\Feature\Kitchen;

  use App\Models\Branch;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use App\Models\User;
  use Carbon\Carbon;
  use Database\Seeders\PermissionSeeder;
  use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
  use Laravel\Sanctum\Sanctum;
  use Tests\TestCase;

  class VoidPaymentTest extends TestCase
  {
      use LazilyRefreshDatabase;

      private User $admin;
      private Branch $branch;
      private Student $student;

      protected function setUp(): void
      {
          parent::setUp();
          $this->seed(PermissionSeeder::class);

          $this->branch = Branch::factory()->create(['is_active' => true]);
          $this->admin  = User::factory()->create();
          $this->admin->assignRole('admin');
          $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
          $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
      }

      private function asAdmin(): static
      {
          Sanctum::actingAs($this->admin, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      private function asUserWithRole(string $role): static
      {
          $user = User::factory()->create();
          $user->assignRole($role);
          $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
          Sanctum::actingAs($user, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      private function makeCurrentMonthPayment(string $status = 'paid'): StudentMonthlyPayment
      {
          $now          = now();
          $currentMonth = \App\Enums\SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

          return StudentMonthlyPayment::factory()->state(['status' => $status])->create([
              'student_id'   => $this->student->id,
              'school_month' => $currentMonth,
              'year'         => $now->year,
              'amount'       => 2970,
              'recorded_at'  => $status === 'paid' ? now() : null,
          ]);
      }

      private function makePastMonthPayment(): StudentMonthlyPayment
      {
          return StudentMonthlyPayment::factory()->paid()->create([
              'student_id'   => $this->student->id,
              'school_month' => 'june',
              'year'         => now()->year - 1,
              'amount'       => 2970,
          ]);
      }

      public function test_admin_can_void_current_month_paid_payment(): void
      {
          $payment = $this->makeCurrentMonthPayment('paid');

          $response = $this->asAdmin()->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              ['reason' => 'Student downgraded mid-month.']
          );

          $response->assertOk();
          $this->assertDatabaseHas('student_monthly_payments', [
              'id'     => $payment->id,
              'status' => 'voided',
          ]);
          $this->assertNotNull(
              StudentMonthlyPayment::find($payment->id)->voided_at
          );
      }

      public function test_cannot_void_past_month_paid_payment(): void
      {
          $payment = $this->makePastMonthPayment();

          $response = $this->asAdmin()->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              ['reason' => 'Attempt to void past month.']
          );

          $response->assertUnprocessable();
          $this->assertDatabaseHas('student_monthly_payments', [
              'id'     => $payment->id,
              'status' => 'paid',
          ]);
      }

      public function test_cannot_void_unpaid_payment(): void
      {
          $payment = $this->makeCurrentMonthPayment('unpaid');

          $response = $this->asAdmin()->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              ['reason' => 'Should not work.']
          );

          $response->assertUnprocessable();
      }

      public function test_void_requires_reason(): void
      {
          $payment = $this->makeCurrentMonthPayment('paid');

          $response = $this->asAdmin()->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              []
          );

          $response->assertUnprocessable();
          $response->assertJsonValidationErrors(['reason']);
      }

      public function test_supervisor_cannot_void_payment(): void
      {
          $payment = $this->makeCurrentMonthPayment('paid');

          $response = $this->asUserWithRole('supervisor')->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              ['reason' => 'Test.']
          );

          $response->assertForbidden();
      }

      public function test_void_logs_activity(): void
      {
          $payment = $this->makeCurrentMonthPayment('paid');

          $this->asAdmin()->patchJson(
              "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
              ['reason' => 'Refund issued separately.']
          );

          $this->assertDatabaseHas('activity_log', [
              'subject_type' => Student::class,
              'subject_id'   => $this->student->id,
              'description'  => 'student_payment.voided',
          ]);
      }
  }
  ```

- [ ] **Step 2: Run tests — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=VoidPaymentTest
  ```
  Expected: All fail — route not defined.

- [ ] **Step 3: Add `void()` to `PaymentController`**

  Open `app/Http/Controllers/Kitchen/PaymentController.php` and add the method:

  ```php
  use App\Enums\SchoolMonth;

  public function void(Request $request, Student $student, StudentMonthlyPayment $payment): JsonResponse
  {
      abort_if($payment->student_id !== $student->id, 404);
      abort_if($payment->status !== 'paid', 422, 'Only paid payments can be voided.');

      $validated = $request->validate([
          'reason' => ['required', 'string', 'max:500'],
      ]);

      $paymentDate = \Carbon\Carbon::createFromDate(
          $payment->year,
          $payment->school_month->toMonthNumber(),
          1
      )->startOfMonth();

      abort_if(
          $paymentDate->lt(now()->startOfMonth()),
          422,
          'Cannot void a past month\'s payment — this subscription period has already been consumed.'
      );

      $payment->update([
          'status'      => 'voided',
          'voided_at'   => now(),
          'voided_by'   => $request->user()->id,
          'void_reason' => $validated['reason'],
      ]);

      activity('payments')
          ->causedBy($request->user())
          ->performedOn($student)
          ->withProperties([
              'school_month' => $payment->school_month->value,
              'year'         => $payment->year,
              'amount'       => $payment->amount,
              'reason'       => $validated['reason'],
          ])
          ->log('student_payment.voided');

      return response()->json([
          'id'          => $payment->id,
          'status'      => $payment->status,
          'voided_at'   => $payment->voided_at?->toDateTimeString(),
          'void_reason' => $payment->void_reason,
      ]);
  }
  ```

- [ ] **Step 4: Register the void route**

  In `routes/kitchen-api.php`, inside the `role:admin|manager` payment group, add **before** the existing `{payment}` PATCH line (so the more-specific path is matched first):

  ```php
  Route::patch('/students/{student}/payments/{payment}/void', [PaymentController::class, 'void']);
  ```

- [ ] **Step 5: Run tests — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=VoidPaymentTest
  ```
  Expected: 6 tests pass.

- [ ] **Step 6: Run pint**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Step 7: Commit**
  ```bash
  git add app/Http/Controllers/Kitchen/PaymentController.php routes/kitchen-api.php tests/Feature/Kitchen/VoidPaymentTest.php
  git commit -m "feat: add void payment endpoint"
  ```

---

## Task 5: Guard Existing PaymentController Methods + Fix index() Response

**Files:**
- Modify: `app/Http/Controllers/Kitchen/PaymentController.php`
- Modify: `tests/Feature/Kitchen/PaymentControllerTest.php`

**Interfaces:**
- Produces: `toggle()` and `record()` reject voided payments with 422; `index()` returns `voided_at` and `void_reason`

- [ ] **Step 1: Add guard tests to `PaymentControllerTest.php`**

  Open `tests/Feature/Kitchen/PaymentControllerTest.php` and append:

  ```php
  public function test_cannot_toggle_a_voided_payment(): void
  {
      $this->payment->update([
          'status'      => 'voided',
          'voided_at'   => now(),
          'voided_by'   => $this->admin->id,
          'void_reason' => 'Was voided.',
      ]);

      $response = $this->asAdmin()->patchJson(
          "/api/v1/students/{$this->student->id}/payments/{$this->payment->id}"
      );

      $response->assertUnprocessable();
      $this->assertDatabaseHas('student_monthly_payments', [
          'id'     => $this->payment->id,
          'status' => 'voided',
      ]);
  }

  public function test_cannot_record_payment_on_voided_record(): void
  {
      $this->payment->update([
          'status'      => 'voided',
          'voided_at'   => now(),
          'voided_by'   => $this->admin->id,
          'void_reason' => 'Was voided.',
          'school_month' => 'june',
          'year'         => 2025,
      ]);

      $response = $this->asAdmin()->postJson(
          "/api/v1/students/{$this->student->id}/payments",
          ['school_month' => 'june', 'year' => 2025, 'amount' => 2970]
      );

      $response->assertUnprocessable();
  }

  public function test_payment_index_returns_voided_fields(): void
  {
      $this->payment->update([
          'status'      => 'voided',
          'voided_at'   => now(),
          'voided_by'   => $this->admin->id,
          'void_reason' => 'Student downgraded.',
      ]);

      $response = $this->asAdmin()->getJson(
          "/api/v1/students/{$this->student->id}/payments"
      );

      $response->assertOk();
      $response->assertJsonFragment(['void_reason' => 'Student downgraded.']);
  }
  ```

- [ ] **Step 2: Run new tests — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=PaymentControllerTest
  ```
  Expected: 3 new tests fail.

- [ ] **Step 3: Add guard to `toggle()`**

  In `PaymentController::toggle()`, add after the ownership check:

  ```php
  abort_if($payment->status === 'voided', 422, 'Cannot modify a voided payment.');
  ```

- [ ] **Step 4: Add guard to `record()`**

  In `PaymentController::record()`, add after the `firstOrFail()` call:

  ```php
  abort_if($payment->status === 'voided', 422, 'Cannot record payment on a voided record.');
  ```

- [ ] **Step 5: Add `voided_at` and `void_reason` to `index()` response**

  In `PaymentController::index()`, update the `map()` callback:

  ```php
  ->map(fn ($p) => [
      'id'               => $p->id,
      'school_month'     => $p->school_month?->value,
      'school_month_label' => $p->school_month?->label(),
      'year'             => $p->year,
      'status'           => $p->status,
      'amount'           => $p->amount,
      'recorded_at'      => $p->recorded_at?->toDateTimeString(),
      'voided_at'        => $p->voided_at?->toDateTimeString(),
      'void_reason'      => $p->void_reason,
  ])
  ```

- [ ] **Step 6: Run all payment tests — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=PaymentControllerTest
  ```
  Expected: All tests pass.

- [ ] **Step 7: Run pint**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Step 8: Commit**
  ```bash
  git add app/Http/Controllers/Kitchen/PaymentController.php tests/Feature/Kitchen/PaymentControllerTest.php
  git commit -m "fix: guard toggle/record against voided payments; add voided fields to index response"
  ```

---

## Task 6: BillingReportController — Exclude Voided by Default

**Files:**
- Modify: `app/Http/Controllers/Kitchen/BillingReportController.php`
- Modify (or create): `tests/Feature/Reports/BillingReportVoidedTest.php`

**Interfaces:**
- Produces: `buildQuery()` excludes `status=voided` by default; `status=voided` filter works

- [ ] **Step 1: Search docs**
  ```
  mcp__laravel-boost__search-docs queries=["query builder where not in", "validation rule in"] packages=["laravel/framework"]
  ```

- [ ] **Step 2: Write failing tests**
  ```bash
  vendor/bin/sail artisan make:test --phpunit Reports/BillingReportVoidedTest --no-interaction
  ```

  Open `tests/Feature/Reports/BillingReportVoidedTest.php`:

  ```php
  <?php

  namespace Tests\Feature\Reports;

  use App\Models\Branch;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use App\Models\User;
  use Database\Seeders\PermissionSeeder;
  use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
  use Laravel\Sanctum\Sanctum;
  use Tests\TestCase;

  class BillingReportVoidedTest extends TestCase
  {
      use LazilyRefreshDatabase;

      private User $admin;
      private Branch $branch;
      private Student $student;

      protected function setUp(): void
      {
          parent::setUp();
          $this->seed(PermissionSeeder::class);

          $this->branch  = Branch::factory()->create(['is_active' => true]);
          $this->admin   = User::factory()->create();
          $this->admin->assignRole('admin');
          $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
          $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
      }

      private function asAdmin(): static
      {
          Sanctum::actingAs($this->admin, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      public function test_billing_report_excludes_voided_by_default(): void
      {
          StudentMonthlyPayment::factory()->create([
              'student_id'   => $this->student->id,
              'school_month' => 'june',
              'year'         => now()->year,
              'status'       => 'voided',
              'amount'       => 2970,
              'voided_at'    => now(),
              'voided_by'    => $this->admin->id,
              'void_reason'  => 'Test.',
          ]);

          $response = $this->asAdmin()->getJson(
              '/api/v1/reports/billing?year='.now()->year.'&school_month=june'
          );

          $response->assertOk();
          $this->assertCount(0, $response->json('data'));
      }

      public function test_billing_report_can_filter_by_voided(): void
      {
          StudentMonthlyPayment::factory()->create([
              'student_id'   => $this->student->id,
              'school_month' => 'june',
              'year'         => now()->year,
              'status'       => 'voided',
              'amount'       => 2970,
              'voided_at'    => now(),
              'voided_by'    => $this->admin->id,
              'void_reason'  => 'Test.',
          ]);

          $response = $this->asAdmin()->getJson(
              '/api/v1/reports/billing?year='.now()->year.'&school_month=june&status=voided'
          );

          $response->assertOk();
          $this->assertCount(1, $response->json('data'));
      }
  }
  ```

- [ ] **Step 3: Run — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=BillingReportVoidedTest
  ```

- [ ] **Step 4: Update `buildQuery()` in `BillingReportController`**

  Add `->where('status', '!=', 'voided')` as the first condition in the `StudentMonthlyPayment::whereIn(...)` chain in `buildQuery()`.

  Also update the `status` validation in `filterRules()` from:
  ```php
  'status' => ['nullable', 'string', 'in:paid,unpaid'],
  ```
  to:
  ```php
  'status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
  ```

  And in `buildQuery()`, update the status filter condition — when status is NOT `voided`, the default exclusion still applies:
  ```php
  ->when(
      isset($validated['status']) && $validated['status'] === 'voided',
      fn ($q) => $q->where('status', 'voided'),
      fn ($q) => $q->where('status', '!=', 'voided')
          ->when(isset($validated['status']), fn ($inner) => $inner->where('status', $validated['status']))
  )
  ```

- [ ] **Step 5: Run — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=BillingReportVoidedTest
  ```

- [ ] **Step 6: Run pint + commit**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  git add app/Http/Controllers/Kitchen/BillingReportController.php tests/Feature/Reports/BillingReportVoidedTest.php
  git commit -m "feat: exclude voided payments from billing report by default"
  ```

---

## Task 7: SubscriptionReportController — Historical Section

**Files:**
- Modify: `app/Http/Controllers/Kitchen/SubscriptionReportController.php`
- Create: `tests/Feature/Kitchen/SubscriptionReportHistoricalTest.php`

**Interfaces:**
- Produces: Response gains `historical_data: HistoricalSubscriberRow[]` alongside existing `data`

- [ ] **Step 1: Write failing tests**
  ```bash
  vendor/bin/sail artisan make:test --phpunit Kitchen/SubscriptionReportHistoricalTest --no-interaction
  ```

  Open `tests/Feature/Kitchen/SubscriptionReportHistoricalTest.php`:

  ```php
  <?php

  namespace Tests\Feature\Kitchen;

  use App\Models\Branch;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use App\Models\User;
  use Database\Seeders\PermissionSeeder;
  use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
  use Laravel\Sanctum\Sanctum;
  use Tests\TestCase;

  class SubscriptionReportHistoricalTest extends TestCase
  {
      use LazilyRefreshDatabase;

      private User $admin;
      private Branch $branch;

      protected function setUp(): void
      {
          parent::setUp();
          $this->seed(PermissionSeeder::class);

          $this->branch = Branch::factory()->create(['is_active' => true]);
          $this->admin  = User::factory()->create();
          $this->admin->assignRole('admin');
          $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
      }

      private function asAdmin(): static
      {
          Sanctum::actingAs($this->admin, ['staff']);
          return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
      }

      public function test_subscription_report_includes_historical_section(): void
      {
          $exSubscriber = Student::factory()->nonSubscription()->create([
              'branch_id' => $this->branch->id,
          ]);
          StudentMonthlyPayment::factory()->paid()->create([
              'student_id'   => $exSubscriber->id,
              'school_month' => 'june',
              'year'         => now()->year,
              'amount'       => 2970,
          ]);

          $response = $this->asAdmin()->getJson(
              '/api/v1/reports/subscription?month=june&year='.now()->year
          );

          $response->assertOk();
          $response->assertJsonStructure(['data', 'meta', 'historical_data']);

          $historical = $response->json('historical_data');
          $this->assertCount(1, $historical);
          $this->assertEquals($exSubscriber->id, $historical[0]['id']);
          $this->assertEquals(2970.0, $historical[0]['payment_amount']);
      }

      public function test_historical_data_excludes_other_branch_students(): void
      {
          $otherBranch = Branch::factory()->create(['is_active' => true]);
          $otherStudent = Student::factory()->nonSubscription()->create([
              'branch_id' => $otherBranch->id,
          ]);
          StudentMonthlyPayment::factory()->paid()->create([
              'student_id'   => $otherStudent->id,
              'school_month' => 'june',
              'year'         => now()->year,
          ]);

          $response = $this->asAdmin()->getJson(
              '/api/v1/reports/subscription?month=june&year='.now()->year
          );

          $response->assertOk();
          $this->assertCount(0, $response->json('historical_data'));
      }

      public function test_historical_data_is_empty_when_no_ex_subscribers(): void
      {
          $response = $this->asAdmin()->getJson(
              '/api/v1/reports/subscription?month=june&year='.now()->year
          );

          $response->assertOk();
          $this->assertEquals([], $response->json('historical_data'));
      }
  }
  ```

- [ ] **Step 2: Run — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionReportHistoricalTest
  ```

- [ ] **Step 3: Update `SubscriptionReportController::index()`**

  In `app/Http/Controllers/Kitchen/SubscriptionReportController.php`, before the `return response()->json($data)` line, add the `historical_data` query and update the return:

  ```php
  $historical = Student::where('branch_id', $branch->id)
      ->where('student_type', 'non_subscription')
      ->whereNull('deleted_at')
      ->whereHas('monthlyPayments', fn ($q) => $q
          ->where('school_month', $monthEnum->value)
          ->where('year', $year)
          ->where('status', 'paid')
      )
      ->with(['monthlyPayments' => fn ($q) => $q
          ->where('school_month', $monthEnum->value)
          ->where('year', $year)
          ->where('status', 'paid')
      ])
      ->get(['id', 'first_name', 'last_name', 'student_number', 'grade_level', 'section'])
      ->map(fn ($s) => [
          'id'             => $s->id,
          'full_name'      => $s->full_name,
          'student_number' => $s->student_number,
          'grade_level'    => $s->grade_level,
          'section'        => $s->section,
          'payment_amount' => (float) $s->monthlyPayments->first()?->amount ?? 0,
      ]);

  return response()->json([
      ...$data->toArray(),
      'historical_data' => $historical,
  ]);
  ```

  Note: `$data` is the result of `$students->through(...)` — it's a `LengthAwarePaginator`. Its `toArray()` gives the paginated shape. Spread that and add `historical_data` alongside.

  Actually, looking at the existing controller, `$data` is a paginator with `through()` applied. The response is `response()->json($data)` which auto-serializes it. Change to:

  ```php
  return response()->json([
      'data'          => $data->items(),
      'meta'          => $this->paginationMeta($data),
      'historical_data' => $historical,
  ]);
  ```

  Check if `paginationMeta` exists in the base controller — if not, use:
  ```php
  'meta' => [
      'current_page' => $students->currentPage(),
      'last_page'    => $students->lastPage(),
      'per_page'     => $students->perPage(),
      'total'        => $students->total(),
      'from'         => $students->firstItem(),
      'to'           => $students->lastItem(),
  ],
  ```

  Check the base `Controller.php` for `paginationMeta()` before deciding which to use.

- [ ] **Step 4: Run — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=SubscriptionReportHistoricalTest
  ```

- [ ] **Step 5: Run pint + commit**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  git add app/Http/Controllers/Kitchen/SubscriptionReportController.php tests/Feature/Kitchen/SubscriptionReportHistoricalTest.php
  git commit -m "feat: add historical_data section to subscription report"
  ```

---

## Task 8: Portal StudentPaymentHistoryController

**Files:**
- Modify: `app/Http/Controllers/Portal/StudentPaymentHistoryController.php`
- Create: `tests/Feature/Portal/StudentPaymentHistoryExSubscriberTest.php`

**Interfaces:**
- Produces: Non-subscription students' paid payment history accessible; voided records excluded

- [ ] **Step 1: Write failing tests**
  ```bash
  vendor/bin/sail artisan make:test --phpunit Portal/StudentPaymentHistoryExSubscriberTest --no-interaction
  ```

  Open `tests/Feature/Portal/StudentPaymentHistoryExSubscriberTest.php`:

  ```php
  <?php

  namespace Tests\Feature\Portal;

  use App\Models\Branch;
  use App\Models\ParentUser;
  use App\Models\Student;
  use App\Models\StudentMonthlyPayment;
  use Database\Seeders\PermissionSeeder;
  use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
  use Laravel\Sanctum\Sanctum;
  use Tests\TestCase;

  class StudentPaymentHistoryExSubscriberTest extends TestCase
  {
      use LazilyRefreshDatabase;

      private ParentUser $parent;
      private Branch $branch;
      private Student $student;

      protected function setUp(): void
      {
          parent::setUp();
          $this->seed(PermissionSeeder::class);

          $this->branch  = Branch::factory()->create(['is_active' => true]);
          $this->parent  = ParentUser::factory()->create();
          $this->student = Student::factory()->nonSubscription()->create([
              'branch_id' => $this->branch->id,
          ]);
          $this->parent->students()->attach($this->student->id, [
              'wallet_alert_threshold' => null,
              'linked_at'              => now(),
              'linked_by'              => null,
          ]);
      }

      private function asParent(): static
      {
          Sanctum::actingAs($this->parent, ['parent']);
          return $this;
      }

      public function test_portal_payment_history_accessible_after_type_switch(): void
      {
          StudentMonthlyPayment::factory()->paid()->create([
              'student_id'   => $this->student->id,
              'school_month' => 'june',
              'year'         => now()->year,
              'amount'       => 2970,
          ]);

          $response = $this->asParent()->getJson(
              "/api/v1/portal/students/{$this->student->id}/payment-history"
          );

          $response->assertOk();
          $this->assertCount(1, $response->json('data'));
      }

      public function test_portal_payment_history_excludes_voided_records(): void
      {
          StudentMonthlyPayment::factory()->create([
              'student_id'   => $this->student->id,
              'school_month' => 'june',
              'year'         => now()->year,
              'status'       => 'voided',
              'amount'       => 2970,
              'voided_at'    => now(),
              'voided_by'    => null,
              'void_reason'  => 'Type switch.',
          ]);

          $response = $this->asParent()->getJson(
              "/api/v1/portal/students/{$this->student->id}/payment-history"
          );

          $response->assertOk();
          $this->assertCount(0, $response->json('data'));
      }
  }
  ```

- [ ] **Step 2: Run — verify they FAIL**
  ```bash
  vendor/bin/sail artisan test --compact --filter=StudentPaymentHistoryExSubscriberTest
  ```

- [ ] **Step 3: Update `StudentPaymentHistoryController`**

  Open `app/Http/Controllers/Portal/StudentPaymentHistoryController.php`.

  Remove the `abort_unless(StudentType::Subscription, ...)` block entirely.

  Update the `$payments` query to exclude voided:

  ```php
  $payments = $student->monthlyPayments()
      ->where('status', '!=', 'voided')
      ->get()
      ->sortBy(fn ($payment) => [$payment->year, array_search($payment->school_month->value, $monthOrder)])
      ->values()
      ->map(fn ($payment) => [
          'id'          => $payment->id,
          'school_month' => $payment->school_month->value,
          'year'         => $payment->year,
          'amount'       => (float) $payment->amount,
          'status'       => $payment->status,
          'paid_at'      => $payment->recorded_at?->toDateTimeString(),
      ]);
  ```

- [ ] **Step 4: Run — verify they PASS**
  ```bash
  vendor/bin/sail artisan test --compact --filter=StudentPaymentHistoryExSubscriberTest
  ```

- [ ] **Step 5: Run pint + commit**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  git add app/Http/Controllers/Portal/StudentPaymentHistoryController.php tests/Feature/Portal/StudentPaymentHistoryExSubscriberTest.php
  git commit -m "fix: allow portal payment history for ex-subscription students; exclude voided records"
  ```

---

## Task 9: POS TypeScript Types

**Files:**
- Modify: `~/sunbites-pos/types/student.ts`
- Modify: `~/sunbites-pos/lib/api/reports.ts`

**Working directory:** `~/sunbites-pos`

**Interfaces:**
- Produces: `PaymentStatus = "paid" | "unpaid" | "voided"`, `MonthlyPayment` with voided fields, `DowngradePreview`, `HistoricalSubscriberRow`

- [ ] **Step 1: Update `PaymentStatus` and `MonthlyPayment` in `types/student.ts`**

  Change line 19:
  ```typescript
  export type PaymentStatus = "paid" | "unpaid" | "voided";
  ```

  In the `MonthlyPayment` interface (after `recorded_at`), add:
  ```typescript
  voided_at: string | null;
  void_reason: string | null;
  ```

  Add the new types after the existing `MonthlyPayment` interface:
  ```typescript
  export interface DowngradePreviewMonth {
    id: number;
    school_month: SchoolMonth;
    year: number;
    amount: number;
    label: string;
  }

  export interface DowngradePreview {
    paid_months_retained: DowngradePreviewMonth[];
    paid_voidable_months: DowngradePreviewMonth[];
    unpaid_months_to_delete: string[];
    unpaid_months_to_delete_count: number;
    wallet_balance: number;
  }
  ```

- [ ] **Step 2: Update `lib/api/reports.ts`**

  Add `HistoricalSubscriberRow` interface after `SubscriptionReportRow`:
  ```typescript
  export interface HistoricalSubscriberRow {
    id: number;
    full_name: string;
    student_number: string | null;
    grade_level: string;
    section: string | null;
    payment_amount: number;
  }
  ```

  Update `subscriptionUsage` return type from:
  ```typescript
  apiClient.get<{ data: SubscriptionReportRow[]; meta: PaginatedMeta }>(
  ```
  to:
  ```typescript
  apiClient.get<{
    data: SubscriptionReportRow[];
    meta: PaginatedMeta;
    historical_data: HistoricalSubscriberRow[];
  }>(
  ```

- [ ] **Step 3: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -40
  ```
  Expect zero errors (or only pre-existing unrelated errors).

- [ ] **Step 4: Commit**
  ```bash
  cd ~/sunbites-pos
  git add types/student.ts lib/api/reports.ts
  git commit -m "feat: add voided status, DowngradePreview, and HistoricalSubscriberRow types"
  ```

---

## Task 10: POS API Service Layer

**Files:**
- Modify: `~/sunbites-pos/lib/api/students.ts`

**Interfaces:**
- Consumes: `DowngradePreview` (Task 9), `Student`, `MonthlyPayment` types
- Produces: `downgradeSubscriptionPreview(id)`, `downgradeSubscription(id)`, `voidPayment(id, paymentId, reason)`

- [ ] **Step 1: Add three new methods to `studentApi`**

  Open `~/sunbites-pos/lib/api/students.ts`. After the existing `updateType` method, add:

  ```typescript
  downgradeSubscriptionPreview: (id: number) =>
    apiClient.get<DowngradePreview>(
      `/students/${id}/subscription-downgrade-preview`,
    ),

  downgradeSubscription: (id: number) =>
    apiClient.post<Student>(`/students/${id}/downgrade-subscription`),

  voidPayment: (studentId: number, paymentId: number, reason: string) =>
    apiClient.patch<MonthlyPayment>(
      `/students/${studentId}/payments/${paymentId}/void`,
      { reason },
    ),
  ```

  Add import for `DowngradePreview` at the top of the file:
  ```typescript
  import type {
    ...
    DowngradePreview,
    ...
  } from "@/types/student";
  ```

- [ ] **Step 2: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
  ```

- [ ] **Step 3: Commit**
  ```bash
  cd ~/sunbites-pos
  git add lib/api/students.ts
  git commit -m "feat: add downgrade preview, downgrade, and void payment API methods"
  ```

---

## Task 11: POS — DowngradeConfirmDialog

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/students/[id]/page.tsx`

**Interfaces:**
- Consumes: `studentApi.downgradeSubscriptionPreview`, `studentApi.downgradeSubscription` (Task 10)
- Produces: `DowngradeConfirmDialog` component replaces the `ChangeTypeDialog` for subscription→non_subscription; invalidates all 4 query keys on success

- [ ] **Step 1: Add `DowngradeConfirmDialog` component**

  In `app/(kitchen)/students/[id]/page.tsx`, **before** the existing `ChangeTypeDialog` component definition (around line 542), add:

  ```tsx
  // ---------------------------------------------------------------------------
  // DowngradeConfirmDialog
  // ---------------------------------------------------------------------------

  interface DowngradeConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    studentId: number;
  }

  function DowngradeConfirmDialog({
    open,
    onClose,
    studentId,
  }: DowngradeConfirmDialogProps) {
    const queryClient = useQueryClient();

    const { data: preview, isLoading: previewLoading } = useQuery({
      queryKey: ["student-downgrade-preview", studentId],
      queryFn: () => studentApi.downgradeSubscriptionPreview(studentId),
      enabled: open,
    });

    const mutation = useMutation({
      mutationFn: () => studentApi.downgradeSubscription(studentId),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ["student", studentId] });
        queryClient.invalidateQueries({ queryKey: ["student-payments", studentId] });
        queryClient.invalidateQueries({ queryKey: ["students", "subscription"] });
        queryClient.invalidateQueries({ queryKey: ["students", "non_subscription"] });
        toast.success("Student switched to non-subscription.");
        onClose();
      },
      onError: (err: ApiError) => {
        toast.error(err.message ?? "Failed to downgrade student.");
      },
    });

    return (
      <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
        <DialogContent showCloseButton={false}>
          <DialogHeader>
            <DialogTitle>Switch to Non-Subscription (Wallet)</DialogTitle>
            <DialogDescription>
              Review what will happen to this student's monthly payment records.
            </DialogDescription>
          </DialogHeader>

          {previewLoading ? (
            <div className="space-y-2 py-2">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-3/4" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          ) : preview ? (
            <div className="space-y-3 text-sm py-1">
              {preview.unpaid_months_to_delete_count > 0 && (
                <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 space-y-1">
                  <p className="font-semibold text-destructive">
                    {preview.unpaid_months_to_delete_count} unpaid month
                    {preview.unpaid_months_to_delete_count > 1 ? "s" : ""} will be permanently deleted:
                  </p>
                  <p className="text-muted-foreground text-xs">
                    {preview.unpaid_months_to_delete.join(", ")}
                  </p>
                </div>
              )}

              {preview.paid_months_retained.length > 0 && (
                <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-1">
                  <p className="font-semibold">
                    {preview.paid_months_retained.length} paid month
                    {preview.paid_months_retained.length > 1 ? "s" : ""} kept as history (cannot be voided):
                  </p>
                  <p className="text-muted-foreground text-xs">
                    {preview.paid_months_retained.map((m) => m.label).join(", ")}
                  </p>
                </div>
              )}

              {preview.paid_voidable_months.length > 0 && (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 space-y-1">
                  <p className="font-semibold text-amber-800">
                    {preview.paid_voidable_months.length} paid month
                    {preview.paid_voidable_months.length > 1 ? "s" : ""} can be voided from the Payments tab:
                  </p>
                  <p className="text-amber-700 text-xs">
                    {preview.paid_voidable_months.map((m) => m.label).join(", ")} — use wallet top-up to issue refunds.
                  </p>
                </div>
              )}

              <p className="text-xs text-muted-foreground">
                Current wallet balance: ₱{preview.wallet_balance.toLocaleString("en-PH", { minimumFractionDigits: 2 })}
              </p>
            </div>
          ) : null}

          <DialogFooter>
            <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => mutation.mutate()}
              disabled={previewLoading || mutation.isPending}
            >
              {mutation.isPending ? "Switching…" : "Confirm Switch"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }
  ```

- [ ] **Step 2: Update `ChangeTypeDialog` to delegate to `DowngradeConfirmDialog`**

  Find the `ChangeTypeDialog` component (around line 542). Update its render to show `DowngradeConfirmDialog` when going subscription → non_subscription:

  ```tsx
  function ChangeTypeDialog({
    open,
    onClose,
    studentId,
    currentType,
  }: ChangeTypeDialogProps) {
    // For the downgrade direction, use the dedicated dialog
    if (currentType === "subscription") {
      return (
        <DowngradeConfirmDialog
          open={open}
          onClose={onClose}
          studentId={studentId}
        />
      );
    }

    // Upgrade path: simple toggle (non_subscription → subscription)
    const queryClient = useQueryClient();
    const mutation = useMutation({
      mutationFn: () => studentApi.updateType(studentId, "subscription"),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ["student", studentId] });
        queryClient.invalidateQueries({ queryKey: ["students", "subscription"] });
        queryClient.invalidateQueries({ queryKey: ["students", "non_subscription"] });
        toast.success("Student type updated successfully.");
        onClose();
      },
      onError: (err: ApiError) => {
        toast.error(err.message ?? "Failed to update student type.");
        onClose();
      },
    });

    return (
      <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
        <DialogContent showCloseButton={false}>
          <DialogHeader>
            <DialogTitle>Switch from Wallet (Pay-per-meal) to Subscription</DialogTitle>
            <DialogDescription>
              After switching, use the Payments tab to add a subscription period.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
              Cancel
            </Button>
            <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
              {mutation.isPending ? "Updating…" : "Confirm"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }
  ```

- [ ] **Step 3: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
  ```

- [ ] **Step 4: Commit**
  ```bash
  cd ~/sunbites-pos
  git add app/\(kitchen\)/students/\[id\]/page.tsx
  git commit -m "feat: replace ChangeTypeDialog with DowngradeConfirmDialog for subscription->non_subscription"
  ```

---

## Task 12: POS — Payments Tab Voided UI + Void Button

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/students/[id]/page.tsx`

**Interfaces:**
- Consumes: `studentApi.voidPayment` (Task 10), `MonthlyPayment` with `voided_at`, `void_reason` (Task 9)
- Produces: Voided rows styled with strikethrough + Voided badge; Void Payment button on current/future paid rows

- [ ] **Step 1: Update the payment row rendering**

  In the payments tab section (around line 1708 where `const isPaid = payment.status === "paid"`), replace the rendering block:

  ```tsx
  {paymentsByYear[year].map((payment) => {
    const isPaid    = payment.status === "paid";
    const isVoided  = payment.status === "voided";
    const isUnpaid  = payment.status === "unpaid";

    // Determine if this payment is current or future (can be voided)
    const paymentDate = new Date(payment.year, monthToNumber(payment.school_month) - 1, 1);
    const nowMonth    = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const isVoidable  = isPaid && paymentDate >= nowMonth;

    return (
      <div
        key={payment.id}
        className={cn(
          "flex items-center justify-between rounded-lg border border-border bg-card p-3",
          isVoided && "opacity-60",
        )}
      >
        <div>
          <p className={cn("text-sm font-medium", isVoided && "line-through text-muted-foreground")}>
            {payment.school_month_label} {payment.year}
          </p>
          <p className="text-xs text-muted-foreground">
            {isVoided ? (
              <>
                <span className="line-through">₱{payment.amount}</span>
                {payment.void_reason && (
                  <span className="ml-1">· {payment.void_reason}</span>
                )}
              </>
            ) : (
              <>
                ₱{payment.amount}
                {payment.recorded_at
                  ? ` · Paid ${new Date(payment.recorded_at).toLocaleDateString()}`
                  : ""}
              </>
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {isVoided ? (
            <span className="text-[11px] font-bold px-3 py-1 rounded-full border bg-muted text-muted-foreground border-border">
              Voided
            </span>
          ) : (
            <>
              <span
                className={cn(
                  "text-[11px] font-bold px-3 py-1 rounded-full border",
                  isPaid
                    ? "bg-green-100 text-green-700 border-green-300"
                    : "bg-red-100 text-destructive border-red-300",
                )}
              >
                {isPaid ? "Paid" : "Unpaid"}
              </span>
              {canToggle && !isVoided && (
                <>
                  <Button
                    type="button"
                    size="sm"
                    variant={isPaid ? "outline" : "default"}
                    className={isPaid ? "text-muted-foreground" : ""}
                    onClick={() => toggleMutation.mutate(payment.id)}
                    disabled={toggleMutation.isPending}
                  >
                    {isPaid ? "Mark Unpaid" : "Mark as Paid"}
                  </Button>
                  {isUnpaid && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setEditingPayment(payment)}
                    >
                      Edit Amount
                    </Button>
                  )}
                  {isVoidable && canToggle && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="text-destructive hover:text-destructive"
                      onClick={() => setVoidingPayment(payment)}
                    >
                      Void
                    </Button>
                  )}
                </>
              )}
            </>
          )}
        </div>
      </div>
    );
  })}
  ```

  Add a helper function `monthToNumber` near the top of the file (with other helper functions):
  ```typescript
  const MONTH_TO_NUMBER: Record<string, number> = {
    june: 6, july: 7, august: 8, september: 9, october: 10,
    november: 11, december: 12, january: 1, february: 2, march: 3,
  };
  function monthToNumber(month: string): number {
    return MONTH_TO_NUMBER[month] ?? 1;
  }
  ```

- [ ] **Step 2: Add `voidingPayment` state and `VoidPaymentDialog` component**

  Add state variable near other payment dialog states:
  ```typescript
  const [voidingPayment, setVoidingPayment] = useState<MonthlyPayment | null>(null);
  ```

  Add the `VoidPaymentDialog` component (before the `PaymentTab` function or inline):

  ```tsx
  interface VoidPaymentDialogProps {
    open: boolean;
    onClose: () => void;
    studentId: number;
    payment: MonthlyPayment | null;
  }

  function VoidPaymentDialog({ open, onClose, studentId, payment }: VoidPaymentDialogProps) {
    const queryClient = useQueryClient();
    const [reason, setReason] = useState("");

    const mutation = useMutation({
      mutationFn: () => studentApi.voidPayment(studentId, payment!.id, reason),
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ["student-payments", studentId] });
        queryClient.invalidateQueries({ queryKey: ["student", studentId] });
        toast.success("Payment voided.");
        setReason("");
        onClose();
      },
      onError: (err: ApiError) => {
        toast.error(err.message ?? "Failed to void payment.");
      },
    });

    return (
      <Dialog open={open} onOpenChange={(o) => { if (!o) { setReason(""); onClose(); } }}>
        <DialogContent showCloseButton={false}>
          <DialogHeader>
            <DialogTitle>Void Payment — {payment?.school_month_label} {payment?.year}</DialogTitle>
            <DialogDescription>
              This marks the ₱{payment?.amount} payment as voided. Issue any refund separately via wallet top-up.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="void-reason">Reason (required)</Label>
            <Textarea
              id="void-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Student downgraded mid-month. Refund issued separately."
              rows={3}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => mutation.mutate()}
              disabled={!reason.trim() || mutation.isPending}
            >
              {mutation.isPending ? "Voiding…" : "Void Payment"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }
  ```

  Render the dialog in the page return (alongside other dialogs):
  ```tsx
  <VoidPaymentDialog
    open={voidingPayment !== null}
    onClose={() => setVoidingPayment(null)}
    studentId={studentId}
    payment={voidingPayment}
  />
  ```

  Add `Textarea` to the imports if not already present:
  ```typescript
  import { Textarea } from "@/components/ui/textarea";
  ```

- [ ] **Step 3: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -30
  ```

- [ ] **Step 4: Commit**
  ```bash
  cd ~/sunbites-pos
  git add app/\(kitchen\)/students/\[id\]/page.tsx
  git commit -m "feat: add voided payment rendering and Void Payment button to payments tab"
  ```

---

## Task 13: POS — Subscription Report Former Subscribers Section

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/reports/subscription/page.tsx`

**Interfaces:**
- Consumes: `historical_data: HistoricalSubscriberRow[]` from the updated `subscriptionUsage` API (Tasks 7 + 9)

- [ ] **Step 1: Add state + render the Former Subscribers section**

  In `app/(kitchen)/reports/subscription/page.tsx`:

  Add state for expanding the section:
  ```typescript
  const [showHistorical, setShowHistorical] = useState(false);
  ```

  After the closing `</div>` of the pagination block, add:

  ```tsx
  {/* Former Subscribers */}
  {(data?.historical_data?.length ?? 0) > 0 && (
    <div className="space-y-2">
      <button
        type="button"
        onClick={() => setShowHistorical((v) => !v)}
        className="text-sm text-muted-foreground hover:text-foreground underline"
      >
        {showHistorical ? "Hide" : "Show"} {data!.historical_data.length} former subscriber
        {data!.historical_data.length > 1 ? "s" : ""} with paid records for this month
      </button>

      {showHistorical && (
        <div className="rounded-xl border border-border bg-card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-muted/40">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">Student</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-muted-foreground">Grade</th>
                <th className="px-4 py-2 text-right text-xs font-semibold text-muted-foreground">Amount Paid</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {data!.historical_data.map((row) => (
                <tr key={row.id} className="hover:bg-muted/20">
                  <td className="px-4 py-2.5">
                    <p className="font-medium text-foreground">{row.full_name}</p>
                    {row.student_number && (
                      <p className="text-xs font-mono text-muted-foreground">{row.student_number}</p>
                    )}
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full border bg-muted text-muted-foreground border-border">
                      Switched
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-muted-foreground">
                    {row.grade_level}
                    {row.section && <p className="text-xs">{row.section}</p>}
                  </td>
                  <td className="px-4 py-2.5 text-right font-medium">
                    ₱{row.payment_amount.toLocaleString("en-PH", { minimumFractionDigits: 2 })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )}
  ```

- [ ] **Step 2: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
  ```

- [ ] **Step 3: Commit**
  ```bash
  cd ~/sunbites-pos
  git add app/\(kitchen\)/reports/subscription/page.tsx
  git commit -m "feat: add Former Subscribers section to subscription report"
  ```

---

## Task 14: POS — Billing Report StatusBadge + Voided Filter

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/reports/billing/page.tsx`

**Interfaces:**
- Produces: `StatusBadge` handles `"voided"`; dropdown includes Voided filter option

- [ ] **Step 1: Update `StatusBadge` component**

  Find the `StatusBadge` function (around line 113) and update:

  ```tsx
  function StatusBadge({ status }: { status: "paid" | "unpaid" | "voided" }) {
    const map: Record<string, string> = {
      paid:   "bg-green-100 text-green-700 border-green-300",
      unpaid: "bg-red-100 text-destructive border-red-300",
      voided: "bg-muted text-muted-foreground border-border",
    };
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    return (
      <span
        className={cn(
          "text-[11px] font-bold px-2 py-0.5 rounded-full border",
          map[status] ?? map.voided,
          status === "voided" && "line-through",
        )}
      >
        {label}
      </span>
    );
  }
  ```

- [ ] **Step 2: Add "Voided" option to the status filter Select**

  Find the status filter `<Select>` and add:
  ```tsx
  <SelectItem value="voided">Voided</SelectItem>
  ```
  alongside the existing Paid/Unpaid items.

- [ ] **Step 3: Update the `BillingPayment` type** in `reports.ts` if the status field is typed — find it and add `"voided"` to the union.

- [ ] **Step 4: Run TypeScript check**
  ```bash
  cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
  ```

- [ ] **Step 5: Commit**
  ```bash
  cd ~/sunbites-pos
  git add app/\(kitchen\)/reports/billing/page.tsx lib/api/reports.ts
  git commit -m "feat: handle voided status in billing report filter and badge"
  ```

---

## Task 15: Portal — Payment Timeline Voided Filter

**Files:**
- Modify: `~/sunbites-portal/app/(portal)/dashboard/_components/payment-history-timeline.tsx`

**Interfaces:**
- Produces: Voided entries filtered out before slice(-5); `isOverdue` logic unaffected

- [ ] **Step 1: Add voided filter**

  Open `payment-history-timeline.tsx`. Find the line where `entries` is assigned (near `const entries = data?.data ?? []`) and add a filter directly after:

  ```typescript
  const entries = (data?.data ?? []).filter((p) => p.status !== "voided");
  ```

  This ensures voided months never appear in the last-5-months grid, and `isOverdue` logic (which checks the current month entry's status) is not confused by a voided current month.

- [ ] **Step 2: Run TypeScript check**
  ```bash
  cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -20
  ```

- [ ] **Step 3: Commit**
  ```bash
  cd ~/sunbites-portal
  git add app/\(portal\)/dashboard/_components/payment-history-timeline.tsx
  git commit -m "fix: filter voided payments from dashboard payment history timeline"
  ```

---

## Final Steps

- [ ] **Run full backend test suite**
  ```bash
  cd ~/sunbites-api
  vendor/bin/sail artisan test --compact
  ```
  Expected: All tests green.

- [ ] **Invoke verification skill**
  ```
  /superpowers:verification-before-completion
  ```

- [ ] **Run laravel-simplifier on all changed PHP files**
  Use the `laravel-simplifier` agent on:
  - `app/Actions/DowngradeStudentSubscriptionAction.php`
  - `app/Http/Controllers/Kitchen/SubscriptionDowngradeController.php`
  - `app/Http/Controllers/Kitchen/PaymentController.php`
  - `app/Http/Controllers/Kitchen/BillingReportController.php`
  - `app/Http/Controllers/Kitchen/SubscriptionReportController.php`
  - `app/Http/Controllers/Portal/StudentPaymentHistoryController.php`

- [ ] **Run pint one final time**
  ```bash
  vendor/bin/sail bin pint --dirty --format agent
  ```

- [ ] **Mark all tasks complete in this file (check all boxes)**
