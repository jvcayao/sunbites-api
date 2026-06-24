<?php

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParentUser;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SpendingSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    private ParentUser $parent;

    private Student $student;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->parent = ParentUser::factory()->create();
        $this->student = Student::factory()->for($this->branch)->create();
        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token)->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function callApi(array $params = []): TestResponse
    {
        return $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/spending-summary?".http_build_query($params));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson("/api/v1/portal/students/{$this->student->id}/spending-summary")
            ->assertUnauthorized();
    }

    public function test_non_linked_student_returns_403(): void
    {
        $other = Student::factory()->for($this->branch)->create();

        $this->asParent()
            ->getJson("/api/v1/portal/students/{$other->id}/spending-summary")
            ->assertForbidden();
    }

    public function test_response_has_correct_structure(): void
    {
        $this->callApi()
            ->assertOk()
            ->assertJsonStructure([
                'monthly' => [['month', 'label', 'total']],
                'top_items',
                'payment_method_split' => ['wallet', 'cash', 'subscription', 'gcash'],
                'ytd_total',
                'this_month_total',
                'last_month_total',
            ]);
    }

    public function test_student_with_no_orders_returns_zeros(): void
    {
        $response = $this->callApi()->assertOk()->json();

        $this->assertEquals(0, $response['ytd_total'], 'ytd_total should be 0');
        $this->assertEquals(0, $response['this_month_total'], 'this_month_total should be 0');
        $this->assertEquals(0, $response['last_month_total'], 'last_month_total should be 0');
        $this->assertEmpty($response['top_items']);
        $this->assertEquals(['wallet' => 0, 'cash' => 0, 'subscription' => 0, 'gcash' => 0], $response['payment_method_split']);
    }

    public function test_this_month_total_sums_current_month_orders(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 500.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Cash,
            'status' => OrderStatus::Completed,
            'total' => 250.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $this->callApi()
            ->assertOk()
            ->assertJsonPath('this_month_total', 750);
    }

    public function test_voided_orders_are_excluded(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 300.00,
            'voided_at' => now(),  // voided — must be excluded
            'created_at' => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 200.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $this->callApi()
            ->assertOk()
            ->assertJsonPath('this_month_total', 200);
    }

    public function test_last_month_total_covers_previous_calendar_month(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 400.00,
            'voided_at' => null,
            'created_at' => now()->subMonth(),
        ]);

        $this->callApi()
            ->assertOk()
            ->assertJsonPath('last_month_total', 400);
    }

    public function test_ytd_total_covers_from_school_year_start(): void
    {
        // Order from June of the current school year (always in YTD)
        $schoolYearStart = now()->month >= 6
            ? now()->year.'-06-15'
            : (now()->year - 1).'-06-15';

        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 600.00,
            'voided_at' => null,
            'created_at' => $schoolYearStart,
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 200.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $this->callApi()
            ->assertOk()
            ->assertJsonPath('ytd_total', 800);
    }

    public function test_monthly_array_always_has_exactly_6_entries(): void
    {
        $monthly = $this->callApi()->assertOk()->json('monthly');

        $this->assertCount(6, $monthly);
    }

    public function test_missing_months_are_filled_with_zero(): void
    {
        // Only create an order for this month; the other 5 months should be 0
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 100.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $monthly = $this->callApi()->assertOk()->json('monthly');

        $zeroMonths = array_filter($monthly, fn ($m) => $m['total'] == 0);
        $this->assertCount(5, $zeroMonths);
    }

    public function test_top_items_are_limited_to_5_ordered_by_count(): void
    {
        $order = Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 500.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        // Create 6 distinct items with different counts
        $items = [
            ['name' => 'Spaghetti',    'count' => 5],
            ['name' => 'Rice Meal',    'count' => 4],
            ['name' => 'Orange Juice', 'count' => 3],
            ['name' => 'Burger',       'count' => 2],
            ['name' => 'Pancit',       'count' => 1],
            ['name' => 'Hotdog',       'count' => 1],
        ];

        foreach ($items as $item) {
            OrderItem::factory()->count($item['count'])->create([
                'order_id' => $order->id,
                'pos_menu_item_id' => $menuItem->id,
                'name' => $item['name'],
                'price' => 50.00,
                'quantity' => 1,
                'line_total' => 50.00,
            ]);
        }

        $topItems = $this->callApi()->assertOk()->json('top_items');

        $this->assertCount(5, $topItems);
        $this->assertEquals('Spaghetti', $topItems[0]['name']);
        $this->assertEquals(5, $topItems[0]['count']);
    }

    public function test_payment_method_split_includes_all_four_keys(): void
    {
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Wallet,
            'status' => OrderStatus::Completed,
            'total' => 100.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);
        Order::factory()->for($this->student)->create([
            'branch_id' => $this->branch->id,
            'payment_method' => PaymentMethod::Cash,
            'status' => OrderStatus::Completed,
            'total' => 100.00,
            'voided_at' => null,
            'created_at' => now(),
        ]);

        $split = $this->callApi()->assertOk()->json('payment_method_split');

        $this->assertArrayHasKey('wallet', $split);
        $this->assertArrayHasKey('cash', $split);
        $this->assertArrayHasKey('subscription', $split);
        $this->assertArrayHasKey('gcash', $split);
        $this->assertEquals(100, array_sum($split));
    }
}
