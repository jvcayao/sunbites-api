<?php

namespace Tests\Feature\Kitchen;

use App\Enums\MenuCategory;
use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asCashier(): static
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function schoolMonth(): string
    {
        // Use a month that's always in the school calendar: 'july'
        return 'july';
    }

    private function schoolYear(): int
    {
        return 2025;
    }

    private function reportParams(array $overrides = []): array
    {
        return array_merge([
            'month' => $this->schoolMonth(),
            'year' => $this->schoolYear(),
        ], $overrides);
    }

    private function createMealItem(): PosMenuItem
    {
        return PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Meal->value,
        ]);
    }

    private function createCompletedSubscriptionOrder(Student $student, PosMenuItem $item, int $qty = 1): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
            'created_at' => now()->setYear($this->schoolYear())->setMonth(7)->setDay(5),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $item->id,
            'quantity' => $qty,
        ]);
    }

    // --- Tests ---

    public function test_report_returns_subscription_students_with_zero_usage_when_no_orders(): void
    {
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
        ]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($row);
        $this->assertEquals(0, $row['subscription_monthly_status']['categories']['meal']['used']);
        $this->assertGreaterThan(0, $row['subscription_monthly_status']['categories']['meal']['allocated']);
    }

    public function test_report_counts_completed_subscription_orders(): void
    {
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
        ]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        $mealItem = $this->createMealItem();
        $this->createCompletedSubscriptionOrder($student, $mealItem, 3);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals(3, $row['subscription_monthly_status']['categories']['meal']['used']);
    }

    public function test_report_excludes_voided_orders_from_used_count(): void
    {
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
        ]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        $mealItem = $this->createMealItem();

        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $voidedOrder = Order::factory()->voided()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'subscription',
            'created_at' => now()->setYear(2025)->setMonth(7)->setDay(5),
        ]);
        OrderItem::factory()->create([
            'order_id' => $voidedOrder->id,
            'pos_menu_item_id' => $mealItem->id,
            'quantity' => 10,
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals(0, $row['subscription_monthly_status']['categories']['meal']['used']);
    }

    public function test_report_payment_status_paid(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::factory()->create([
            'student_id' => $student->id,
            'school_month' => $this->schoolMonth(),
            'year' => $this->schoolYear(),
            'status' => 'paid',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals('paid', $row['payment_status']);
    }

    public function test_report_payment_status_unpaid(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::factory()->create([
            'student_id' => $student->id,
            'school_month' => $this->schoolMonth(),
            'year' => $this->schoolYear(),
            'status' => 'unpaid',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals('unpaid', $row['payment_status']);
    }

    public function test_report_payment_status_not_recorded_when_no_payment_record(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals('not_recorded', $row['payment_status']);
    }

    public function test_report_excludes_orders_from_different_month(): void
    {
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
        ]);

        $student = Student::factory()->subscription()->enrolled()->create(['branch_id' => $this->branch->id]);
        $mealItem = $this->createMealItem();

        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        // Order in August (not July)
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
            'created_at' => now()->setYear(2025)->setMonth(8)->setDay(5),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $mealItem->id,
            'quantity' => 5,
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertEquals(0, $row['subscription_monthly_status']['categories']['meal']['used']);
    }

    public function test_report_excludes_students_from_other_branches(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->subscription()->enrolled()->create(['branch_id' => $otherBranch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($otherStudent->id, $ids);
    }

    public function test_report_excludes_non_subscription_students(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $nonSub = Student::factory()->nonSubscription()->enrolled()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($nonSub->id, $ids);
    }

    public function test_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));
        $response->assertUnauthorized();
    }

    public function test_supervisor_can_access_report(): void
    {
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asSupervisor()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertOk();
    }

    public function test_cashier_cannot_access_report(): void
    {
        $response = $this->asCashier()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams()));

        $response->assertForbidden();
    }

    public function test_invalid_month_returns_422(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/subscription?'.http_build_query($this->reportParams(['month' => 'invalidmonth'])));

        $response->assertUnprocessable();
    }

    public function test_missing_year_returns_422(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/subscription?month=july');

        $response->assertUnprocessable();
    }
}
