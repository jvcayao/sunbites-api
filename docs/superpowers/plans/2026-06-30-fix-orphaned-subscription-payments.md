# Fix Orphaned Subscription Payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Clean up orphaned `student_monthly_payments` rows left behind by the old naive `updateType` flow, and block that endpoint from creating the same dirty data again.

**Architecture:** Two independent backend changes — a guard on `StudentController::updateType()` that returns 422 for subscription→non-subscription switches (directing callers to the proper downgrade endpoint), and an idempotent Artisan command `subscriptions:fix-orphaned-payments` that hard-deletes orphaned unpaid payments while retaining paid history and flagging it for manual staff review. Both changes follow the same business rules as the live downgrade flow.

**Tech Stack:** Laravel 13, PHPUnit 12, spatie/laravel-activitylog, Artisan console commands.

## Global Constraints

- All commands via `vendor/bin/sail` — never run PHP/Artisan/Composer directly
- Run `vendor/bin/sail bin pint --dirty --format agent` after every PHP file change
- Run `vendor/bin/sail artisan test --compact` with a filter after every task
- Use `LazilyRefreshDatabase` on every Feature test class
- No `PermissionSeeder` needed — these tests do not hit auth-guarded HTTP endpoints (except Task 1 which reuses the existing `StudentDetailTest` setUp)
- Use PHP 8 attribute-based command signature: `#[Signature(...)]` / `#[Description(...)]`
- Command has no authenticated user — omit `causedBy()` in activity log; `causer_id` will be `null`

---

## File Map

### New files
| File | Purpose |
|---|---|
| `app/Console/Commands/FixOrphanedSubscriptionPaymentsCommand.php` | Artisan command |
| `tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php` | Command tests |

### Modified files
| File | Change |
|---|---|
| `app/Http/Controllers/Kitchen/StudentController.php` | Add guard in `updateType()` |
| `tests/Feature/StudentDetailTest.php` | Rename + update one existing test to assert 422 |

---

## Task 1: `updateType` Guard + Update Existing Test

**Files:**
- Modify: `app/Http/Controllers/Kitchen/StudentController.php:259-279`
- Modify: `tests/Feature/StudentDetailTest.php:161-174`

**Interfaces:**
- Produces: `PATCH /api/v1/students/{student}/type` returns 422 when switching subscription→non_subscription; upgrade path (non_subscription→subscription) unchanged

- [x] **Step 1: Update the existing test to expect 422 (write the failing test)**

Open `tests/Feature/StudentDetailTest.php`. Find `test_manager_can_downgrade_subscription_student_to_wallet` (around line 161) and replace it entirely with:

```php
public function test_updateType_rejects_subscription_to_non_subscription_downgrade(): void
{
    $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

    $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
        'student_type' => 'non_subscription',
    ]);

    $response->assertUnprocessable();
    $this->assertDatabaseHas('students', [
        'id' => $student->id,
        'student_type' => 'subscription',
    ]);
}
```

- [x] **Step 2: Run the test to verify it FAILS (red)**

```bash
vendor/bin/sail artisan test --compact --filter=test_updateType_rejects_subscription_to_non_subscription_downgrade
```

Expected: **FAIL** — the endpoint currently returns 200, not 422.

- [x] **Step 3: Add the guard to `updateType()`**

Open `app/Http/Controllers/Kitchen/StudentController.php`. In `updateType()`, add the `abort_if` check immediately after validation, before the update:

```php
public function updateType(Request $request, Student $student): JsonResponse
{
    $validated = $request->validate([
        'student_type' => ['required', Rule::enum(StudentType::class)],
    ]);

    abort_if(
        $student->student_type === StudentType::Subscription
            && StudentType::from($validated['student_type']) === StudentType::NonSubscription,
        422,
        'Subscription students must be downgraded via the dedicated downgrade endpoint.'
    );

    $oldType = $student->student_type->value;

    $student->update(['student_type' => StudentType::from($validated['student_type'])]);

    activity('students')
        ->causedBy($request->user())
        ->performedOn($student)
        ->withProperties([
            'old_type' => $oldType,
            'new_type' => $validated['student_type'],
        ])
        ->log('students.type_changed');

    return response()->json(new StudentResource($student->fresh()));
}
```

- [x] **Step 4: Run the updated test to verify it PASSES (green)**

```bash
vendor/bin/sail artisan test --compact --filter=test_updateType_rejects_subscription_to_non_subscription_downgrade
```

Expected: **PASS**.

- [x] **Step 5: Run the full `StudentDetailTest` to confirm no regressions**

```bash
vendor/bin/sail artisan test --compact tests/Feature/StudentDetailTest.php
```

Expected: All tests pass. In particular, `test_manager_can_upgrade_wallet_student_to_subscription` (non_subscription→subscription) must still pass — it is unaffected by the guard.

- [x] **Step 6: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/StudentController.php tests/Feature/StudentDetailTest.php
git commit -m "fix: block subscription→non-subscription switch via updateType; enforce downgrade endpoint"
```

---

## Task 2: Artisan Command + Tests

**Files:**
- Create: `app/Console/Commands/FixOrphanedSubscriptionPaymentsCommand.php`
- Create: `tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php`

**Interfaces:**
- Consumes: `Student::withoutBranch()`, `student->monthlyPayments` (HasMany), `StudentMonthlyPayment::whereIn()->delete()`, `activity('students')->performedOn()->withProperties()->log()`
- Produces: `vendor/bin/sail artisan subscriptions:fix-orphaned-payments [--execute] [--branch=ID]` — exit 0 on success, exit 1 when branch ID not found

- [x] **Step 1: Create the test file (write all failing tests)**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php
```

Replace the generated file with:

```php
<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class FixOrphanedPaymentsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
    }

    public function test_dry_run_makes_no_database_changes(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments')
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_deletes_unpaid_orphaned_payments(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_retains_paid_orphaned_payments(): void
    {
        $payment = StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_skips_voided_payments(): void
    {
        $payment = StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
            'status' => 'voided',
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_execute_logs_activity_with_no_causer(): void
    {
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $activity = Activity::where('description', 'students.orphaned_payments_cleaned')
            ->where('subject_id', $this->student->id)
            ->first();

        $this->assertNotNull($activity, 'Expected activity log entry not found.');
        $this->assertNull($activity->causer_id);
        $this->assertEquals(1, $activity->properties['deleted_count']);
        $this->assertContains('july 2025', $activity->properties['deleted_months']);
    }

    public function test_student_with_no_orphaned_payments_is_skipped(): void
    {
        // student has no monthly payments at all
        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertEquals(
            0,
            Activity::where('description', 'students.orphaned_payments_cleaned')->count()
        );
    }

    public function test_branch_flag_restricts_scope_to_one_branch(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->nonSubscription()->create(['branch_id' => $otherBranch->id]);

        $otherPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $otherStudent->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $thisPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'august',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', [
            '--execute' => true,
            '--branch' => $this->branch->id,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('student_monthly_payments', ['id' => $thisPayment->id]);
        $this->assertDatabaseHas('student_monthly_payments', ['id' => $otherPayment->id]);
    }

    public function test_branch_flag_with_invalid_id_aborts_with_failure(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', [
            '--execute' => true,
            '--branch' => 99999,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('student_monthly_payments', ['id' => $payment->id]);
    }

    public function test_command_is_idempotent(): void
    {
        StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
        ]);

        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])->assertExitCode(0);
        $this->artisan('subscriptions:fix-orphaned-payments', ['--execute' => true])->assertExitCode(0);

        $this->assertEquals(
            1,
            Activity::where('description', 'students.orphaned_payments_cleaned')->count()
        );
    }
}
```

- [x] **Step 2: Run the tests to verify they ALL FAIL (red)**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php
```

Expected: **ALL FAIL** with "Command 'subscriptions:fix-orphaned-payments' is not defined."

- [x] **Step 3: Create the command**

```bash
vendor/bin/sail artisan make:command FixOrphanedSubscriptionPaymentsCommand --no-interaction
```

Replace the generated file with:

```php
<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('subscriptions:fix-orphaned-payments {--execute} {--branch=}')]
#[Description('Clean up orphaned monthly payments on non-subscription students.')]
class FixOrphanedSubscriptionPaymentsCommand extends Command
{
    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $branchId = $this->option('branch');

        if ($branchId !== null && ! Branch::find($branchId)) {
            $this->error("Branch #{$branchId} not found.");

            return self::FAILURE;
        }

        if (! $execute) {
            $this->info('[DRY RUN] Scanning for non-subscription students with orphaned payments...');
            $this->newLine();
        }

        $totalStudents = 0;
        $totalPaidRetained = 0;

        $query = Student::withoutBranch()
            ->where('student_type', 'non_subscription')
            ->whereHas('monthlyPayments');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $query->with('monthlyPayments')->chunk(100, function ($students) use ($execute, &$totalStudents, &$totalPaidRetained) {
            foreach ($students as $student) {
                $unpaid = $student->monthlyPayments->filter->isUnpaid();
                $paid = $student->monthlyPayments->filter->isPaid();

                if ($unpaid->isEmpty() && $paid->isEmpty()) {
                    continue;
                }

                $totalStudents++;

                $deletedMonths = $unpaid->map(fn ($p) => "{$p->school_month->value} {$p->year}")->values()->all();
                $retainedMonths = $paid->map(fn ($p) => "{$p->school_month->value} {$p->year}")->values()->all();

                $action = $execute ? 'deleted' : 'would be deleted';

                $this->info("  {$student->full_name}");

                if ($unpaid->isNotEmpty()) {
                    $amount = $unpaid->sum('amount');
                    $this->line("    → {$unpaid->count()} unpaid month(s) {$action}: " . implode(', ', $deletedMonths) . " (₱{$amount})");
                }

                if ($paid->isNotEmpty()) {
                    $totalPaidRetained += $paid->count();
                    foreach ($paid as $p) {
                        $this->warn("    ⚠  Paid month retained: {$p->school_month->value} {$p->year} ₱{$p->amount} — review and void manually if refund needed");
                    }
                }

                if ($execute && $unpaid->isNotEmpty()) {
                    DB::transaction(function () use ($student, $unpaid, $paid, $deletedMonths, $retainedMonths) {
                        StudentMonthlyPayment::whereIn('id', $unpaid->pluck('id'))->delete();

                        activity('students')
                            ->performedOn($student)
                            ->withProperties([
                                'deleted_count' => $unpaid->count(),
                                'deleted_months' => $deletedMonths,
                                'retained_paid_count' => $paid->count(),
                                'retained_paid_months' => $retainedMonths,
                            ])
                            ->log('students.orphaned_payments_cleaned');
                    });
                }
            }
        });

        if ($totalStudents === 0) {
            $this->info('No non-subscription students with orphaned payments found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $label = $execute ? 'Cleaned' : 'Found';
        $this->info("{$label}: {$totalStudents} student(s) with orphaned payments.");

        if ($totalPaidRetained > 0) {
            $this->warn("{$totalPaidRetained} paid month(s) retained — review and void manually if refund needed.");
        }

        if (! $execute) {
            $this->newLine();
            $this->line('Run with --execute to apply changes.');
        }

        return self::SUCCESS;
    }
}
```

- [x] **Step 4: Run the tests to verify they ALL PASS (green)**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php
```

Expected: **ALL 9 PASS**.

- [x] **Step 5: Run the full suite to confirm no regressions**

```bash
vendor/bin/sail artisan test --compact
```

Expected: All tests pass (646 + 9 new = 655 tests).

- [x] **Step 6: Verify the command works with real data**

```bash
# Dry-run first (safe)
vendor/bin/sail artisan subscriptions:fix-orphaned-payments

# Apply (only if dry-run showed students to fix)
vendor/bin/sail artisan subscriptions:fix-orphaned-payments --execute
```

Expected: The 2 non-subscription students on local/staging with orphaned unpaid payments are cleaned up, or "Nothing to do." if already clean.

- [x] **Step 7: Format and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Console/Commands/FixOrphanedSubscriptionPaymentsCommand.php \
        tests/Feature/Kitchen/FixOrphanedPaymentsCommandTest.php
git commit -m "feat: add command to clean up orphaned payments on non-subscription students"
```
