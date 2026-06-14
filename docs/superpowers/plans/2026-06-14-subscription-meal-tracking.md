# Subscription Meal Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-category monthly meal usage tracking (used / allocated / remaining) to subscription students — surfaced on the POS cart panel, a new subscription report page, the admin student profile, and the parent portal.

**Architecture:** Compute monthly usage on the fly from `order_items` filtered by subscription payment method and school month. Two helper methods on `Student` model drive all three API endpoints. The subscription report uses a single bulk query (not N per-student calls) to avoid N+1. No new database columns.

**Tech Stack:** Laravel 13 (PHPUnit 12, Eloquent, DB facade), Next.js App Router (TanStack Query, Tailwind v4, TypeScript)

---

## File Map

### Laravel API (`~/sunbites-api`)

| Action | File |
|---|---|
| Modify | `app/Models/Student.php` |
| Modify | `app/Http/Controllers/Kitchen/StudentLookupController.php` |
| Modify | `app/Http/Controllers/Kitchen/StudentController.php` |
| Modify | `app/Http/Controllers/Portal/StudentController.php` |
| Modify | `routes/kitchen-api.php` |
| Create | `app/Http/Controllers/Kitchen/SubscriptionReportController.php` |
| Modify | `tests/Feature/StudentDetailTest.php` |
| Create | `tests/Feature/Kitchen/SubscriptionReportTest.php` |
| Create | `tests/Feature/Portal/SubscriptionStatusTest.php` |

### POS (`~/sunbites-pos`)

| Action | File |
|---|---|
| Modify | `types/order.ts` |
| Modify | `components/pos/cart-panel.tsx` |
| Modify | `lib/api/reports.ts` |
| Modify | `components/layouts/kitchen-layout.tsx` |
| Create | `app/(kitchen)/reports/subscription/page.tsx` |
| Create | `app/(kitchen)/reports/subscription/loading.tsx` |

### Parent Portal (`~/sunbites-portal`)

| Action | File |
|---|---|
| Modify | `types/portal.ts` |
| Modify | `app/(portal)/students/[id]/page.tsx` |

---

## Task 1: Student Model — add `monthlySubscriptionUsageByCategory()` and `currentMonthSubscriptionStatus()`

**Files:**
- Modify: `app/Models/Student.php`
- Modify: `tests/Feature/StudentDetailTest.php`

- [ ] **Step 1: Write the failing test for monthly status on POS student lookup**

Open `tests/Feature/StudentDetailTest.php`. Add this test after the existing `test_non_subscription_student_pos_lookup_has_null_daily_status` test:

```php
public function test_subscription_student_pos_lookup_includes_monthly_status(): void
{
    $student = Student::factory()->subscription()->enrolled()->create([
        'branch_id' => $this->branch->id,
        'qr_code' => 'SB-MONTHLY001X',
    ]);

    BranchSubscriptionConfig::factory()->create([
        'branch_id' => $this->branch->id,
        'meal_daily_limit' => 1,
        'snack_daily_limit' => 1,
        'drink_daily_limit' => 1,
        'extra_daily_limit' => 1,
    ]);

    $response = $this->asManager()->postJson('/api/v1/pos/students/lookup', [
        'type' => 'qr',
        'value' => 'SB-MONTHLY001X',
    ]);

    $response->assertOk();
    $status = $response->json('student.subscription_monthly_status');
    $this->assertNotNull($status);
    $this->assertArrayHasKey('month', $status);
    $this->assertArrayHasKey('year', $status);
    $this->assertArrayHasKey('categories', $status);
    $this->assertArrayHasKey('meal', $status['categories']);
    $this->assertArrayHasKey('snack', $status['categories']);
    $this->assertArrayHasKey('drink', $status['categories']);
    $this->assertArrayHasKey('extra', $status['categories']);
    $this->assertArrayHasKey('allocated', $status['categories']['meal']);
    $this->assertArrayHasKey('used', $status['categories']['meal']);
    $this->assertArrayHasKey('remaining', $status['categories']['meal']);
    $this->assertEquals(0, $status['categories']['meal']['used']);
}

public function test_non_subscription_student_pos_lookup_has_null_monthly_status(): void
{
    $student = Student::factory()->nonSubscription()->enrolled()->create([
        'branch_id' => $this->branch->id,
        'qr_code' => 'SB-NOSUB001MNT',
    ]);

    $response = $this->asManager()->postJson('/api/v1/pos/students/lookup', [
        'type' => 'qr',
        'value' => 'SB-NOSUB001MNT',
    ]);

    $response->assertOk();
    $this->assertNull($response->json('student.subscription_monthly_status'));
}
```

- [ ] **Step 2: Run the failing tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_subscription_student_pos_lookup_includes_monthly_status
```

Expected: FAIL — `subscription_monthly_status` key does not exist in response.

- [ ] **Step 3: Add the two new methods to `app/Models/Student.php`**

Add these imports at the top of `Student.php` (they may already be imported — check first and only add what's missing):

```php
use App\Models\BranchSubscriptionConfig;
use App\Enums\SchoolMonth;
```

Then add the two methods at the end of the class, just before the closing `}`, after `todaySubscriptionUsageByCategory()`:

```php
/**
 * Monthly meal usage per category for the given school month.
 * Uses withoutGlobalScopes() on the Order relation so this is safe
 * to call from both kitchen (branch-scoped) and portal (no active branch) contexts.
 */
public function monthlySubscriptionUsageByCategory(SchoolMonth $month, int $year): Collection
{
    return OrderItem::whereHas('order', fn ($q) => $q
        ->withoutGlobalScopes()
        ->where('student_id', $this->id)
        ->where('payment_method', PaymentMethod::Subscription)
        ->where('status', OrderStatus::Completed)
        ->whereYear('created_at', $year)
        ->whereMonth('created_at', $month->toMonthNumber())
    )->with('menuItem')->get()
        ->groupBy(fn ($item) => $item->menuItem->category->value)
        ->map(fn ($items) => $items->sum('quantity'));
}

/** @return array<string, mixed>|null */
public function currentMonthSubscriptionStatus(): ?array
{
    if ($this->student_type !== StudentType::Subscription) {
        return null;
    }

    $month = SchoolMonth::fromMonthNumber(now()->month);
    if ($month === null) {
        return null;
    }

    $year = now()->year;
    $config = BranchSubscriptionConfig::forBranch($this->branch_id);
    $days = config('sunbites.school_months')[$month->value]['days'];
    $used = $this->monthlySubscriptionUsageByCategory($month, $year);

    $categories = [];
    foreach (MenuCategory::cases() as $category) {
        $allocated = $days * $config->limitForCategory($category);
        $usedCount = (int) ($used[$category->value] ?? 0);
        $categories[$category->value] = [
            'allocated' => $allocated,
            'used' => $usedCount,
            'remaining' => max(0, $allocated - $usedCount),
        ];
    }

    return [
        'month' => $month->value,
        'year' => $year,
        'categories' => $categories,
    ];
}
```

Note: `OrderItem`, `PaymentMethod`, `OrderStatus`, `MenuCategory`, `StudentType` should already be imported in `Student.php` (they are used by `todaySubscriptionUsageByCategory()`). Verify and add any that are missing.

- [ ] **Step 4: Run Pint**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run the failing tests — they should still fail (method exists but not wired up yet)**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_subscription_student_pos_lookup_includes_monthly_status
```

Expected: FAIL — key still missing from response. Model compiles without error.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-api && git add app/Models/Student.php tests/Feature/StudentDetailTest.php
git commit -m "feat: add monthlySubscriptionUsageByCategory and currentMonthSubscriptionStatus to Student model"
```

---

## Task 2: Wire `subscription_monthly_status` into POS Student Lookup

**Files:**
- Modify: `app/Http/Controllers/Kitchen/StudentLookupController.php`

- [ ] **Step 1: Add `subscription_monthly_status` to `fullStudentData()` in `StudentLookupController`**

In `app/Http/Controllers/Kitchen/StudentLookupController.php`, inside the `fullStudentData()` method, add the new field to the return array. The current last field in the array is `'subscription_daily_status'`. Add after it:

```php
'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
```

The full return array should now end with:

```php
'subscription_daily_status' => $student->student_type === StudentType::Subscription
    ? $this->buildDailyStatus($student)
    : null,
'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
```

- [ ] **Step 2: Run Pint**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 3: Run the tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_subscription_student_pos_lookup_includes_monthly_status
```

Expected: PASS (if running in a school month) or careful assertion — if `now()->month` is not a school month, `subscription_monthly_status` will be `null`. The test will pass because June (month 6) through March (month 3) are all school months in config. If running between April and May, the test needs adjustment — but this is unlikely in the school year context.

- [ ] **Step 4: Run the full `test_non_subscription` test too**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_non_subscription_student_pos_lookup_has_null_monthly_status
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/sunbites-api && git add app/Http/Controllers/Kitchen/StudentLookupController.php
git commit -m "feat: add subscription_monthly_status to POS student lookup response"
```

---

## Task 3: Wire `subscription_monthly_status` into Admin Student Show

**Files:**
- Modify: `app/Http/Controllers/Kitchen/StudentController.php`
- Modify: `tests/Feature/StudentDetailTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/StudentDetailTest.php`, add this test in the admin student show section:

```php
public function test_admin_student_show_includes_subscription_monthly_status_for_subscription_student(): void
{
    $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

    BranchSubscriptionConfig::factory()->create([
        'branch_id' => $this->branch->id,
        'meal_daily_limit' => 1,
        'snack_daily_limit' => 1,
        'drink_daily_limit' => 1,
        'extra_daily_limit' => 1,
    ]);

    $response = $this->asManager()->getJson("/api/v1/students/{$student->id}");

    $response->assertOk();
    $status = $response->json('subscription_monthly_status');
    $this->assertNotNull($status);
    $this->assertArrayHasKey('month', $status);
    $this->assertArrayHasKey('year', $status);
    $this->assertArrayHasKey('categories', $status);
}

public function test_admin_student_show_has_null_monthly_status_for_non_subscription_student(): void
{
    $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

    $response = $this->asManager()->getJson("/api/v1/students/{$student->id}");

    $response->assertOk();
    $this->assertNull($response->json('subscription_monthly_status'));
}
```

- [ ] **Step 2: Run failing tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_admin_student_show_includes_subscription_monthly_status
```

Expected: FAIL — key missing from response.

- [ ] **Step 3: Update `StudentController::show()` to include the new field**

In `app/Http/Controllers/Kitchen/StudentController.php`, the `show()` method currently returns:

```php
return response()->json([
    'student' => new StudentResource($student),
    'wallet_transactions' => $walletTransactions,
    'activity_logs' => $activityLogs,
]);
```

Change it to:

```php
return response()->json([
    'student' => new StudentResource($student),
    'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
    'wallet_transactions' => $walletTransactions,
    'activity_logs' => $activityLogs,
]);
```

- [ ] **Step 4: Run Pint**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact --filter=test_admin_student_show_includes_subscription_monthly_status
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-api && git add app/Http/Controllers/Kitchen/StudentController.php tests/Feature/StudentDetailTest.php
git commit -m "feat: add subscription_monthly_status to admin student show response"
```

---

## Task 4: Wire `subscription_monthly_status` into Portal Student List

**Files:**
- Modify: `app/Http/Controllers/Portal/StudentController.php`
- Create: `tests/Feature/Portal/SubscriptionStatusTest.php`

- [ ] **Step 1: Create the failing test**

Create `tests/Feature/Portal/SubscriptionStatusTest.php`:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionStatusTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private ParentUser $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function asParent(ParentUser $parent): static
    {
        Sanctum::actingAs($parent, ['parent']);
        return $this;
    }

    public function test_portal_students_list_includes_subscription_monthly_status_for_subscription_student(): void
    {
        $parent = ParentUser::factory()->create();
        $student = Student::factory()->subscription()->enrolled()->create([
            'branch_id' => $this->branch->id,
        ]);
        $parent->students()->attach($student->id, [
            'linked_at' => now(),
            'wallet_alert_threshold' => 100,
        ]);

        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        $response = $this->asParent($parent)->getJson('/api/v1/portal/students');

        $response->assertOk();
        $studentData = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($studentData);
        $status = $studentData['subscription_monthly_status'];
        $this->assertNotNull($status);
        $this->assertArrayHasKey('month', $status);
        $this->assertArrayHasKey('year', $status);
        $this->assertArrayHasKey('categories', $status);
        $this->assertArrayHasKey('meal', $status['categories']);
        $this->assertEquals(0, $status['categories']['meal']['used']);
    }

    public function test_portal_students_list_has_null_monthly_status_for_non_subscription_student(): void
    {
        $parent = ParentUser::factory()->create();
        $student = Student::factory()->nonSubscription()->enrolled()->create([
            'branch_id' => $this->branch->id,
        ]);
        $parent->students()->attach($student->id, [
            'linked_at' => now(),
            'wallet_alert_threshold' => 100,
        ]);

        $response = $this->asParent($parent)->getJson('/api/v1/portal/students');

        $response->assertOk();
        $studentData = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNull($studentData['subscription_monthly_status']);
    }
}
```

- [ ] **Step 2: Run failing tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Portal/SubscriptionStatusTest.php
```

Expected: FAIL — key missing from response.

- [ ] **Step 3: Update `Portal\StudentController::index()` to include the new field**

In `app/Http/Controllers/Portal/StudentController.php`, the `index()` method maps students. Add `subscription_monthly_status` to the map closure. The current map ends with `'linked_at' => $student->pivot->linked_at`. Add after it:

```php
'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
```

The student object from the relationship already has `branch_id` loaded, so `currentMonthSubscriptionStatus()` will work correctly.

Also add the import for `StudentType` if not already present (check the top of the file):
```php
use App\Enums\StudentType;
```

- [ ] **Step 4: Run Pint**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Portal/SubscriptionStatusTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-api && git add app/Http/Controllers/Portal/StudentController.php tests/Feature/Portal/SubscriptionStatusTest.php
git commit -m "feat: add subscription_monthly_status to portal student list response"
```

---

## Task 5: Subscription Report Endpoint

**Files:**
- Create: `app/Http/Controllers/Kitchen/SubscriptionReportController.php`
- Modify: `routes/kitchen-api.php`
- Create: `tests/Feature/Kitchen/SubscriptionReportTest.php`

- [ ] **Step 1: Create the test file**

Create `tests/Feature/Kitchen/SubscriptionReportTest.php`:

```php
<?php

namespace Tests\Feature\Kitchen;

use App\Enums\MenuCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function asSupervisor(): static
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($user, ['staff']);
        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asCashier(): static
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($user, ['staff']);
        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function createSubscriptionOrder(Student $student, string $category, int $quantity, string $date): Order
    {
        $menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category' => $category,
            'is_subscription_item' => true,
        ]);
        $order = Order::create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $this->admin->id,
            'receipt_number' => 'TEST-' . uniqid(),
            'payment_method' => PaymentMethod::Subscription,
            'subtotal' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'status' => OrderStatus::Completed,
            'created_at' => $date,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $menuItem->id,
            'name' => $menuItem->name,
            'price' => 0,
            'quantity' => $quantity,
            'line_total' => 0,
        ]);
        return $order;
    }

    public function test_report_returns_correct_used_allocated_remaining_per_category(): void
    {
        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 0,
            'extra_daily_limit' => 0,
        ]);

        // 10 meal orders in July 2025
        for ($i = 1; $i <= 10; $i++) {
            $this->createSubscriptionOrder($student, MenuCategory::Meal->value, 1, "2025-07-{$i}");
        }

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($row);

        $meal = $row['subscription_monthly_status']['categories']['meal'];
        $this->assertEquals(22, $meal['allocated']); // 22 days × 1 daily limit
        $this->assertEquals(10, $meal['used']);
        $this->assertEquals(12, $meal['remaining']);

        $snack = $row['subscription_monthly_status']['categories']['snack'];
        $this->assertEquals(22, $snack['allocated']);
        $this->assertEquals(0, $snack['used']);
        $this->assertEquals(22, $snack['remaining']);

        $drink = $row['subscription_monthly_status']['categories']['drink'];
        $this->assertEquals(0, $drink['allocated']); // 22 × 0 daily limit
        $this->assertEquals(0, $drink['used']);
        $this->assertEquals(0, $drink['remaining']);
    }

    public function test_report_excludes_voided_orders(): void
    {
        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        // 5 completed meal orders
        for ($i = 1; $i <= 5; $i++) {
            $this->createSubscriptionOrder($student, MenuCategory::Meal->value, 1, "2025-07-{$i}");
        }

        // 3 voided meal orders — should NOT count
        $menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Meal->value,
            'is_subscription_item' => true,
        ]);
        for ($i = 6; $i <= 8; $i++) {
            $order = Order::create([
                'branch_id' => $this->branch->id,
                'student_id' => $student->id,
                'cashier_id' => $this->admin->id,
                'receipt_number' => 'VOID-' . uniqid(),
                'payment_method' => PaymentMethod::Subscription,
                'subtotal' => 0,
                'discount_amount' => 0,
                'total' => 0,
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $this->admin->id,
                'void_reason' => 'test void',
                'created_at' => "2025-07-{$i}",
            ]);
            OrderItem::create([
                'order_id' => $order->id,
                'pos_menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'price' => 0,
                'quantity' => 1,
                'line_total' => 0,
            ]);
        }

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals(5, $row['subscription_monthly_status']['categories']['meal']['used']);
    }

    public function test_report_returns_payment_status(): void
    {
        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::factory()->create([
            'student_id' => $student->id,
            'school_month' => SchoolMonth::July,
            'year' => 2025,
            'status' => 'paid',
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals('paid', $row['payment_status']);
    }

    public function test_report_returns_not_recorded_when_no_payment_record(): void
    {
        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals('not_recorded', $row['payment_status']);
    }

    public function test_report_filters_by_year_and_excludes_other_month_orders(): void
    {
        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        // Order in August — should NOT count when querying July
        $this->createSubscriptionOrder($student, MenuCategory::Meal->value, 1, '2025-08-01');

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals(0, $row['subscription_monthly_status']['categories']['meal']['used']);
    }

    public function test_report_excludes_non_subscription_students(): void
    {
        $nonSub = Student::factory()->nonSubscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($nonSub->id, $ids);
    }

    public function test_report_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->subscription()->enrolled()->create(['branch_id' => $otherBranch->id]);
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/subscription?month=july&year=2025');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($otherStudent->id, $ids);
    }

    public function test_report_returns_401_for_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/reports/subscription?month=july&year=2025')
            ->assertUnauthorized();
    }

    public function test_report_returns_403_for_cashier_role(): void
    {
        $this->asCashier()
            ->getJson('/api/v1/reports/subscription?month=july&year=2025')
            ->assertForbidden();
    }

    public function test_report_allows_supervisor_role(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $this->asSupervisor()
            ->getJson('/api/v1/reports/subscription?month=july&year=2025')
            ->assertOk();
    }

    public function test_report_returns_422_for_invalid_month(): void
    {
        $this->asAdmin()
            ->getJson('/api/v1/reports/subscription?month=invalidmonth&year=2025')
            ->assertUnprocessable();
    }

    public function test_report_returns_422_when_month_is_missing(): void
    {
        $this->asAdmin()
            ->getJson('/api/v1/reports/subscription?year=2025')
            ->assertUnprocessable();
    }

    public function test_report_returns_422_when_year_is_missing(): void
    {
        $this->asAdmin()
            ->getJson('/api/v1/reports/subscription?month=july')
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run failing tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Kitchen/SubscriptionReportTest.php
```

Expected: FAIL — route not found (404).

- [ ] **Step 3: Create `SubscriptionReportController`**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan make:class app/Http/Controllers/Kitchen/SubscriptionReportController --no-interaction
```

Replace the contents of `app/Http/Controllers/Kitchen/SubscriptionReportController.php` with:

```php
<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\MenuCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\BranchSubscriptionConfig;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', Rule::in(array_column(SchoolMonth::cases(), 'value'))],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        $month = SchoolMonth::from($validated['month']);
        $year = (int) $validated['year'];
        $branchId = app('active_branch')->id;

        $students = Student::query()
            ->where('student_type', StudentType::Subscription)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $studentIds = $students->pluck('id');

        $usageByStudent = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('pos_menu_items', 'order_items.pos_menu_item_id', '=', 'pos_menu_items.id')
            ->where('orders.payment_method', PaymentMethod::Subscription->value)
            ->where('orders.status', OrderStatus::Completed->value)
            ->whereYear('orders.created_at', $year)
            ->whereMonth('orders.created_at', $month->toMonthNumber())
            ->whereIn('orders.student_id', $studentIds)
            ->select(
                'orders.student_id',
                'pos_menu_items.category',
                DB::raw('SUM(order_items.quantity) as total_quantity')
            )
            ->groupBy('orders.student_id', 'pos_menu_items.category')
            ->get()
            ->groupBy('student_id');

        $paymentsByStudent = StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where('school_month', $month->value)
            ->where('year', $year)
            ->get()
            ->keyBy('student_id');

        $config = BranchSubscriptionConfig::forBranch($branchId);
        $days = config('sunbites.school_months')[$month->value]['days'];

        $students->through(function (Student $student) use ($usageByStudent, $paymentsByStudent, $config, $days, $month, $year) {
            $usageByCategory = $usageByStudent->get($student->id, collect())->keyBy('category');
            $payment = $paymentsByStudent->get($student->id);

            $categories = [];
            foreach (MenuCategory::cases() as $category) {
                $allocated = $days * $config->limitForCategory($category);
                $usedCount = (int) ($usageByCategory->get($category->value)?->total_quantity ?? 0);
                $categories[$category->value] = [
                    'allocated' => $allocated,
                    'used' => $usedCount,
                    'remaining' => max(0, $allocated - $usedCount),
                ];
            }

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'student_number' => $student->student_number,
                'grade_level' => $student->grade_level,
                'section' => $student->section,
                'payment_status' => $payment?->status ?? 'not_recorded',
                'subscription_monthly_status' => [
                    'month' => $month->value,
                    'year' => $year,
                    'categories' => $categories,
                ],
            ];
        });

        return response()->json($students);
    }
}
```

- [ ] **Step 4: Register the route in `routes/kitchen-api.php`**

Inside the reports `Route::middleware('role:admin|manager|supervisor')->group(function () {` block (alongside sales, students, inventory, billing), add:

```php
Route::get('/subscription', [SubscriptionReportController::class, 'index']);
```

Also add the import at the top of `kitchen-api.php`:

```php
use App\Http\Controllers\Kitchen\SubscriptionReportController;
```

- [ ] **Step 5: Run Pint**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 6: Run the tests**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact tests/Feature/Kitchen/SubscriptionReportTest.php
```

Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
cd ~/sunbites-api && git add app/Http/Controllers/Kitchen/SubscriptionReportController.php routes/kitchen-api.php tests/Feature/Kitchen/SubscriptionReportTest.php
git commit -m "feat: add subscription usage report endpoint GET /reports/subscription"
```

---

## Task 6: POS — Update Types and Cart Panel Monthly Usage

**Files:**
- Modify: `~/sunbites-pos/types/order.ts`
- Modify: `~/sunbites-pos/components/pos/cart-panel.tsx`

- [ ] **Step 1: Add `SubscriptionMonthlyStatus` and update `PosStudent` in `types/order.ts`**

In `~/sunbites-pos/types/order.ts`, the existing `SubscriptionCategoryStatus` interface (line 4) has `used`, `limit`, `remaining`. The new monthly status uses `allocated` instead of `limit`. Add a new interface after the existing one:

```typescript
export interface SubscriptionMonthlyCategoryStatus {
  allocated: number;
  used: number;
  remaining: number;
}

export interface SubscriptionMonthlyStatus {
  month: string;
  year: number;
  categories: {
    meal: SubscriptionMonthlyCategoryStatus;
    snack: SubscriptionMonthlyCategoryStatus;
    drink: SubscriptionMonthlyCategoryStatus;
    extra: SubscriptionMonthlyCategoryStatus;
  };
}
```

Then in the `PosStudent` interface, add the new field after `subscription_daily_status`:

```typescript
subscription_monthly_status: SubscriptionMonthlyStatus | null;
```

- [ ] **Step 2: Add Monthly Usage section to cart panel**

In `~/sunbites-pos/components/pos/cart-panel.tsx`, find the subscription payment section. Currently it shows `subscription_daily_status`. After the daily status block, add the monthly status block. The current daily block looks like:

```tsx
{paymentMethod === "subscription" && student?.subscription_daily_status && (
  <div className="rounded-lg border border-border bg-muted/40 p-2.5 space-y-1.5">
    <p className="text-xs font-semibold text-muted-foreground uppercase">Daily Usage</p>
    ...
  </div>
)}
```

Add this immediately after it (before the fallback `!subscription_daily_status` block):

```tsx
{paymentMethod === "subscription" && student?.subscription_monthly_status && (
  <div className="rounded-lg border border-border bg-muted/40 p-2.5 space-y-1.5">
    <p className="text-xs font-semibold text-muted-foreground uppercase">
      Monthly Usage ({student.subscription_monthly_status.month.charAt(0).toUpperCase() + student.subscription_monthly_status.month.slice(1)})
    </p>
    <div className="space-y-1">
      {Object.entries(student.subscription_monthly_status.categories)
        .filter(([, s]) => s.allocated > 0)
        .map(([cat, s]) => (
          <div key={cat} className="flex items-center justify-between text-xs">
            <span className="capitalize text-muted-foreground">{cat}</span>
            <span className="text-muted-foreground">{s.used} / {s.allocated}</span>
            <span
              className={cn(
                "font-medium tabular-nums",
                s.remaining === 0
                  ? "text-destructive"
                  : s.remaining <= 5
                    ? "text-amber-600"
                    : "text-foreground"
              )}
            >
              {s.remaining} left
            </span>
          </div>
        ))}
    </div>
  </div>
)}
```

- [ ] **Step 3: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: No errors related to the new types.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-pos && git add types/order.ts components/pos/cart-panel.tsx
git commit -m "feat: add monthly usage section to cart panel for subscription students"
```

---

## Task 7: POS — Subscription Report Page

**Files:**
- Modify: `~/sunbites-pos/lib/api/reports.ts`
- Create: `~/sunbites-pos/app/(kitchen)/reports/subscription/page.tsx`
- Create: `~/sunbites-pos/app/(kitchen)/reports/subscription/loading.tsx`
- Modify: `~/sunbites-pos/components/layouts/kitchen-layout.tsx`

- [ ] **Step 1: Add `SubscriptionReportRow` type and `subscriptionUsage` function to `lib/api/reports.ts`**

In `~/sunbites-pos/lib/api/reports.ts`, add these after the existing `BillingPayment` interface and before the `DailySummaryData` interface:

```typescript
export interface SubscriptionReportRow {
  id: number;
  full_name: string;
  student_number: string | null;
  grade_level: string;
  section: string | null;
  payment_status: "paid" | "unpaid" | "not_recorded";
  subscription_monthly_status: {
    month: string;
    year: number;
    categories: {
      meal: { allocated: number; used: number; remaining: number };
      snack: { allocated: number; used: number; remaining: number };
      drink: { allocated: number; used: number; remaining: number };
      extra: { allocated: number; used: number; remaining: number };
    };
  };
}
```

Then in the `reportApi` object (at the bottom of the file), add before the closing `}`:

```typescript
subscription: (params: { month: string; year: number; page?: number }) =>
  apiClient.get<{
    data: SubscriptionReportRow[];
    meta: PaginatedMeta;
  }>("/reports/subscription", {
    params: params as Record<string, string | number | boolean | undefined>,
  }),
```

- [ ] **Step 2: Create the subscription report page**

Create `~/sunbites-pos/app/(kitchen)/reports/subscription/page.tsx`:

```tsx
"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { cn } from "@/lib/utils";
import { reportApi } from "@/lib/api/reports";

const SCHOOL_MONTHS = [
  { value: "june", label: "June" },
  { value: "july", label: "July" },
  { value: "august", label: "August" },
  { value: "september", label: "September" },
  { value: "october", label: "October" },
  { value: "november", label: "November" },
  { value: "december", label: "December" },
  { value: "january", label: "January" },
  { value: "february", label: "February" },
  { value: "march", label: "March" },
] as const;

function currentSchoolMonth(): string {
  const m = new Date().getMonth() + 1;
  const map: Record<number, string> = {
    6: "june", 7: "july", 8: "august", 9: "september", 10: "october",
    11: "november", 12: "december", 1: "january", 2: "february", 3: "march",
  };
  return map[m] ?? "june";
}

function remainingColor(remaining: number, allocated: number): string {
  if (allocated === 0) return "text-muted-foreground";
  if (remaining === 0) return "text-destructive font-semibold";
  if (remaining <= 5) return "text-amber-600 font-semibold";
  return "text-foreground";
}

function PaymentBadge({ status }: { status: "paid" | "unpaid" | "not_recorded" }) {
  if (status === "paid") {
    return (
      <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
        Paid
      </span>
    );
  }
  if (status === "unpaid") {
    return (
      <span className="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
        Unpaid
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
      Not recorded
    </span>
  );
}

function CategoryCell({ allocated, used, remaining }: { allocated: number; used: number; remaining: number }) {
  if (allocated === 0) {
    return <span className="text-muted-foreground text-xs">—</span>;
  }
  return (
    <span className={cn("text-sm tabular-nums", remainingColor(remaining, allocated))}>
      {used} / {allocated}
    </span>
  );
}

export default function SubscriptionReportPage() {
  const [month, setMonth] = useState(currentSchoolMonth);
  const [year, setYear] = useState(new Date().getFullYear());
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useQuery({
    queryKey: ["subscription-report", month, year, page],
    queryFn: () => reportApi.subscription({ month, year, page }),
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">Subscription Usage Report</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Monthly meal usage per subscription student.
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <select
          value={month}
          onChange={(e) => { setMonth(e.target.value); setPage(1); }}
          className="rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:border-ring"
        >
          {SCHOOL_MONTHS.map((m) => (
            <option key={m.value} value={m.value}>{m.label}</option>
          ))}
        </select>

        <input
          type="number"
          value={year}
          onChange={(e) => { setYear(parseInt(e.target.value, 10) || new Date().getFullYear()); setPage(1); }}
          min={2020}
          max={2100}
          className="w-24 rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:border-ring"
        />
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-border bg-muted/40">
            <tr>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Student</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Grade</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Payment</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Meal</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Snack</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Drink</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Extra</th>
              <th className="px-4 py-3 font-semibold text-muted-foreground">Total Remaining</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                  Loading…
                </td>
              </tr>
            ) : error ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-destructive">
                  Failed to load report. Please try again.
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                  No subscription students found for this month.
                </td>
              </tr>
            ) : (
              rows.map((row) => {
                const cats = row.subscription_monthly_status.categories;
                const totalRemaining = cats.meal.remaining + cats.snack.remaining + cats.drink.remaining + cats.extra.remaining;
                return (
                  <tr key={row.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3">
                      <p className="font-medium">{row.full_name}</p>
                      {row.student_number && (
                        <p className="text-xs text-muted-foreground">{row.student_number}</p>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {row.grade_level}
                      {row.section && <span className="text-muted-foreground"> · {row.section}</span>}
                    </td>
                    <td className="px-4 py-3">
                      <PaymentBadge status={row.payment_status} />
                    </td>
                    <td className="px-4 py-3">
                      <CategoryCell {...cats.meal} />
                    </td>
                    <td className="px-4 py-3">
                      <CategoryCell {...cats.snack} />
                    </td>
                    <td className="px-4 py-3">
                      <CategoryCell {...cats.drink} />
                    </td>
                    <td className="px-4 py-3">
                      <CategoryCell {...cats.extra} />
                    </td>
                    <td className="px-4 py-3">
                      <span className={cn("font-semibold tabular-nums", remainingColor(totalRemaining, cats.meal.allocated + cats.snack.allocated + cats.drink.allocated + cats.extra.allocated))}>
                        {totalRemaining}
                      </span>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Page {meta.current_page} of {meta.last_page} · {meta.total} students
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
              className="rounded-lg border border-border px-3 py-1.5 text-sm disabled:opacity-40 hover:bg-muted"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-lg border border-border px-3 py-1.5 text-sm disabled:opacity-40 hover:bg-muted"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Create the loading skeleton**

Create `~/sunbites-pos/app/(kitchen)/reports/subscription/loading.tsx`:

```tsx
export default function SubscriptionReportLoading() {
  return (
    <div className="space-y-4">
      <div className="h-8 w-64 animate-pulse rounded bg-muted" />
      <div className="h-4 w-48 animate-pulse rounded bg-muted" />
      <div className="flex gap-3">
        <div className="h-9 w-32 animate-pulse rounded-lg bg-muted" />
        <div className="h-9 w-24 animate-pulse rounded-lg bg-muted" />
      </div>
      <div className="overflow-hidden rounded-xl border border-border">
        <div className="h-12 w-full animate-pulse bg-muted/40" />
        {Array.from({ length: 8 }).map((_, i) => (
          <div key={i} className="flex gap-4 border-t border-border px-4 py-3">
            <div className="h-5 w-40 animate-pulse rounded bg-muted" />
            <div className="h-5 w-20 animate-pulse rounded bg-muted" />
            <div className="h-5 w-16 animate-pulse rounded bg-muted" />
          </div>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Add "Subscription" to the reports nav in `kitchen-layout.tsx`**

In `~/sunbites-pos/components/layouts/kitchen-layout.tsx`, find the `reportsNav` array (around line 59). Add the new item after the Credits entry:

```typescript
{ label: "Subscription", href: "/reports/subscription", icon: CalendarDays },
```

Also add `"/reports/subscription"` to the `supervisorAllowedReports` array (around line 171):

```typescript
const supervisorAllowedReports = ["/reports/sales", "/reports/students", "/reports/inventory", "/reports/billing", "/reports/subscription"];
```

Note: `CalendarDays` is already imported in this file (used for Subscription Config in the references nav). Verify the import is there.

- [ ] **Step 5: Verify TypeScript compiles**

```bash
cd ~/sunbites-pos && npx tsc --noEmit 2>&1 | head -20
```

Expected: No errors.

- [ ] **Step 6: Commit**

```bash
cd ~/sunbites-pos && git add lib/api/reports.ts app/\(kitchen\)/reports/subscription/ components/layouts/kitchen-layout.tsx
git commit -m "feat: add subscription usage report page and nav entry"
```

---

## Task 8: Parent Portal — Meals This Month Section

**Files:**
- Modify: `~/sunbites-portal/types/portal.ts`
- Modify: `~/sunbites-portal/app/(portal)/students/[id]/page.tsx`

- [ ] **Step 1: Add `SubscriptionMonthlyStatus` type and field to `types/portal.ts`**

In `~/sunbites-portal/types/portal.ts`, add these interfaces before the `StudentSummary` interface:

```typescript
export interface SubscriptionMonthlyCategoryStatus {
  allocated: number;
  used: number;
  remaining: number;
}

export interface SubscriptionMonthlyStatus {
  month: string;
  year: number;
  categories: {
    meal: SubscriptionMonthlyCategoryStatus;
    snack: SubscriptionMonthlyCategoryStatus;
    drink: SubscriptionMonthlyCategoryStatus;
    extra: SubscriptionMonthlyCategoryStatus;
  };
}
```

Then in the `StudentSummary` interface, add the new field after `student_type`:

```typescript
subscription_monthly_status: SubscriptionMonthlyStatus | null;
```

- [ ] **Step 2: Add the Meals This Month card to the student detail page**

In `~/sunbites-portal/app/(portal)/students/[id]/page.tsx`, find the student header section (around line 453 where it shows `student.full_name`). After the header div (after `<EnrollmentStatusBadge status={student.enrollment_status} />`), add the Meals This Month card. It should appear between the student header and the tab bar:

```tsx
{/* Meals This Month — subscription students only */}
{isSubscription && student.subscription_monthly_status && (
  <div className="rounded-xl border border-border bg-card p-4">
    <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-3">
      Meals This Month —{" "}
      {student.subscription_monthly_status.month.charAt(0).toUpperCase() +
        student.subscription_monthly_status.month.slice(1)}{" "}
      {student.subscription_monthly_status.year}
    </h2>
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {Object.entries(student.subscription_monthly_status.categories)
        .filter(([, s]) => s.allocated > 0)
        .map(([cat, s]) => (
          <div key={cat} className="rounded-lg border border-border bg-muted/30 p-3 text-center">
            <p className="text-xs font-medium text-muted-foreground capitalize mb-1">{cat}</p>
            <p className="text-lg font-bold tabular-nums">
              {s.used}
              <span className="text-sm font-normal text-muted-foreground"> / {s.allocated}</span>
            </p>
            <p
              className={
                s.remaining === 0
                  ? "text-xs font-semibold text-destructive"
                  : s.remaining <= 5
                    ? "text-xs font-semibold text-amber-600"
                    : "text-xs text-muted-foreground"
              }
            >
              {s.remaining} remaining
            </p>
          </div>
        ))}
    </div>
  </div>
)}
```

Place this block between the student header `</div>` and the `{/* Tab bar */}` comment.

- [ ] **Step 3: Verify TypeScript compiles**

```bash
cd ~/sunbites-portal && npx tsc --noEmit 2>&1 | head -20
```

Expected: No errors.

- [ ] **Step 4: Commit**

```bash
cd ~/sunbites-portal && git add types/portal.ts app/\(portal\)/students/\[id\]/page.tsx
git commit -m "feat: show subscription meals this month on portal student detail page"
```

---

## Task 9: Run Full Backend Test Suite

- [ ] **Step 1: Run the complete backend test suite**

```bash
cd ~/sunbites-api && vendor/bin/sail artisan test --compact
```

Expected: All tests PASS. If any pre-existing tests fail, investigate — do not mask failures.

- [ ] **Step 2: Run Pint on all modified PHP files**

```bash
cd ~/sunbites-api && vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 3: If all green, commit the final state**

If no additional changes needed, the suite is done. If Pint made formatting changes, commit them:

```bash
cd ~/sunbites-api && git add -p && git commit -m "style: pint formatting fixes"
```
