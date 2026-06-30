<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsReportTest extends TestCase
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

    private function defaultParams(): array
    {
        return [
            'from_month' => 'june',
            'from_year' => 2025,
            'to_month' => 'july',
            'to_year' => 2025,
        ];
    }

    // ---------------------------------------------------------------------------
    // Auth / validation
    // ---------------------------------------------------------------------------

    public function test_manager_can_fetch_analytics(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $response->assertOk();
        $response->assertJsonStructure([
            'period' => ['from_month', 'from_year', 'to_month', 'to_year', 'months'],
            'sales' => ['kpis', 'revenue_trend', 'payment_methods', 'top_items', 'peak_hours'],
            'students' => ['kpis', 'by_grade', 'switch_trend'],
            'billing' => ['kpis', 'monthly_trend', 'by_grade'],
            'wallet' => ['kpis', 'monthly_trend'],
            'credits' => ['kpis', 'distribution'],
            'inventory' => ['kpis', 'top_consumed', 'stock_events'],
        ]);
        $this->assertSame(['June 2025', 'July 2025'], $response->json('period.months'));
    }

    public function test_supervisor_cannot_access_analytics(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $response->assertForbidden();
    }

    public function test_missing_from_month_returns_422(): void
    {
        $params = $this->defaultParams();
        unset($params['from_month']);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($params));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from_month']);
    }

    public function test_invalid_school_month_returns_422(): void
    {
        $params = array_merge($this->defaultParams(), ['from_month' => 'april']);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($params));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from_month']);
    }

    // ---------------------------------------------------------------------------
    // Sales
    // ---------------------------------------------------------------------------

    public function test_sales_kpis_reflect_completed_orders_in_range(): void
    {
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $cashier = User::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'total' => 100.00,
            'discount_amount' => 10.00,
            'created_at' => '2025-06-15 10:00:00',
        ]);
        OrderItem::factory()->for($order)->create(['pos_menu_item_id' => $menuItem->id, 'name' => 'Rice Bowl', 'quantity' => 2]);

        // Outside range — must not appear
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'total' => 500.00,
            'discount_amount' => 0,
            'created_at' => '2025-08-01 10:00:00',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $response->assertOk();
        $this->assertSame(100.00, $response->json('sales.kpis.total_revenue'));
        $this->assertSame(1, $response->json('sales.kpis.total_orders'));
        $this->assertSame(10.00, $response->json('sales.kpis.total_discounts'));
        $this->assertSame(90.00, $response->json('sales.kpis.net_revenue'));
    }

    public function test_sales_revenue_trend_has_entry_per_period(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $trend = $response->json('sales.revenue_trend');
        $this->assertCount(2, $trend);
        $this->assertSame('June 2025', $trend[0]['label']);
        $this->assertSame('July 2025', $trend[1]['label']);
    }

    public function test_sales_top_items_sorted_by_quantity(): void
    {
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $cashier = User::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'created_at' => '2025-06-10 10:00:00',
        ]);
        OrderItem::factory()->for($order)->create(['pos_menu_item_id' => $menuItem->id, 'name' => 'Chicken', 'quantity' => 5]);
        OrderItem::factory()->for($order)->create(['pos_menu_item_id' => $menuItem->id, 'name' => 'Rice',    'quantity' => 10]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $items = $response->json('sales.top_items');
        $this->assertSame('Rice', $items[0]['name']);
        $this->assertSame(10, $items[0]['quantity']);
    }

    // ---------------------------------------------------------------------------
    // Students
    // ---------------------------------------------------------------------------

    public function test_students_kpis_count_branch_students(): void
    {
        Student::factory()->subscription()->enrolled()->count(3)->create(['branch_id' => $this->branch->id]);
        Student::factory()->nonSubscription()->enrolled()->count(2)->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $response->assertOk();
        $this->assertSame(5, $response->json('students.kpis.total_students'));
        $this->assertSame(3, $response->json('students.kpis.subscription_count'));
        $this->assertSame(2, $response->json('students.kpis.non_subscription_count'));
    }

    public function test_students_new_enrollments_counts_created_in_range(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'created_at' => '2025-06-15']);
        Student::factory()->create(['branch_id' => $this->branch->id, 'created_at' => '2025-08-01']); // outside range

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $this->assertSame(1, $response->json('students.kpis.new_enrollments'));
    }

    public function test_students_switch_trend_counts_upgrades_and_downgrades(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        DB::table('activity_log')->insert([
            'subject_type' => Student::class,
            'subject_id' => $student->id,
            'description' => 'students.updated',
            'attribute_changes' => json_encode([
                'attributes' => ['student_type' => 'subscription'],
                'old' => ['student_type' => 'non_subscription'],
            ]),
            'created_at' => '2025-06-20',
            'updated_at' => '2025-06-20',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $trend = $response->json('students.switch_trend');
        $june = collect($trend)->firstWhere('label', 'June 2025');
        $this->assertSame(1, $june['upgrades']);
        $this->assertSame(0, $june['downgrades']);
    }

    // ---------------------------------------------------------------------------
    // Billing
    // ---------------------------------------------------------------------------

    public function test_billing_monthly_trend_splits_paid_unpaid_void(): void
    {
        // Unique constraint (student_id, school_month, year) — one student per status
        $s1 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $s2 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $s3 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::factory()->for($s1)->paid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 2970]);
        StudentMonthlyPayment::factory()->for($s2)->unpaid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 2970]);
        StudentMonthlyPayment::factory()->for($s3)->state(['status' => 'voided'])->create(['school_month' => 'june', 'year' => 2025, 'amount' => 2970]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $trend = $response->json('billing.monthly_trend');
        $june = collect($trend)->firstWhere('label', 'June 2025');

        $this->assertSame(1, $june['paid_count']);
        $this->assertSame(1, $june['unpaid_count']);
        $this->assertSame(1, $june['void_count']);
        $this->assertSame(2970.00, $june['paid_amount']);
        $this->assertSame(2970.00, $june['unpaid_amount']);
        $this->assertSame(2970.00, $june['void_amount']);
    }

    public function test_billing_collection_rate_excludes_voided_payments(): void
    {
        $s1 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $s2 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $s3 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::factory()->for($s1)->paid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);
        StudentMonthlyPayment::factory()->for($s2)->unpaid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);
        StudentMonthlyPayment::factory()->for($s3)->state(['status' => 'voided'])->create(['school_month' => 'june', 'year' => 2025, 'amount' => 1000]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        // paid/(paid+unpaid)*100 = 1000/2000*100 = 50.0
        $this->assertSame(50.0, $response->json('billing.kpis.collection_rate'));
    }

    public function test_billing_by_grade_counts_payment_records(): void
    {
        $s1 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id, 'grade_level' => 'Grade 1']);
        $s2 = Student::factory()->subscription()->create(['branch_id' => $this->branch->id, 'grade_level' => 'Grade 1']);

        StudentMonthlyPayment::factory()->for($s1)->paid()->create(['school_month' => 'june', 'year' => 2025, 'amount' => 2970]);
        StudentMonthlyPayment::factory()->for($s2)->paid()->create(['school_month' => 'july', 'year' => 2025, 'amount' => 2970]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $gradeRow = collect($response->json('billing.by_grade'))->firstWhere('grade_level', 'Grade 1');
        $this->assertSame(2, $gradeRow['paid']);
    }

    // ---------------------------------------------------------------------------
    // Wallet
    // ---------------------------------------------------------------------------

    public function test_wallet_kpis_sum_branch_student_transactions(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        // Create wallet manually so we control created_at
        $walletId = DB::table('wallets')->insertGetId([
            'holder_type' => Student::class,
            'holder_id' => $student->id,
            'name' => 'Default Wallet',
            'slug' => 'default',
            'uuid' => Str::uuid(),
            'balance' => 2000,
            'decimal_places' => 2,
            'created_at' => '2025-06-01',
            'updated_at' => '2025-06-01',
        ]);

        DB::table('transactions')->insert([
            ['payable_type' => Student::class, 'payable_id' => $student->id, 'wallet_id' => $walletId, 'type' => 'deposit',  'amount' => 5000, 'confirmed' => 1, 'uuid' => Str::uuid(), 'created_at' => '2025-06-10', 'updated_at' => '2025-06-10'],
            ['payable_type' => Student::class, 'payable_id' => $student->id, 'wallet_id' => $walletId, 'type' => 'withdraw', 'amount' => -3000, 'confirmed' => 1, 'uuid' => Str::uuid(), 'created_at' => '2025-06-15', 'updated_at' => '2025-06-15'],
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $walletKpis = $response->json('wallet.kpis');
        $this->assertEqualsWithDelta(50.0, $walletKpis['total_credits'], 0.01);
        $this->assertEqualsWithDelta(30.0, $walletKpis['total_debits'], 0.01);
        $this->assertEqualsWithDelta(20.0, $walletKpis['net_flow'], 0.01);
    }

    // ---------------------------------------------------------------------------
    // Credits
    // ---------------------------------------------------------------------------

    public function test_credits_kpis_reflect_live_credit_balances(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 150.00]);
        Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 0.00]); // excluded

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $credits = $response->json('credits.kpis');
        $this->assertSame(1, $credits['students_on_credit']);
        $this->assertSame(150.00, $credits['total_credit_balance']);
    }

    // ---------------------------------------------------------------------------
    // Inventory
    // ---------------------------------------------------------------------------

    public function test_inventory_top_consumed_sums_sale_logs(): void
    {
        $item = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Rice', 'unit' => 'kg']);
        $staff = User::factory()->create();

        DB::table('inventory_logs')->insert([
            ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'sale',    'quantity_change' => -5,  'stock_after' => 95,  'reason' => 'sale',    'item_name_snapshot' => 'Rice', 'created_at' => '2025-06-10'],
            ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'sale',    'quantity_change' => -3,  'stock_after' => 92,  'reason' => 'sale',    'item_name_snapshot' => 'Rice', 'created_at' => '2025-07-05'],
            ['branch_id' => $this->branch->id, 'inventory_item_id' => $item->id, 'adjusted_by' => $staff->id, 'type' => 'restock', 'quantity_change' => 20,  'stock_after' => 112, 'reason' => 'restock', 'item_name_snapshot' => 'Rice', 'created_at' => '2025-06-01'],
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $top = $response->json('inventory.top_consumed');
        $this->assertSame('Rice', $top[0]['name']);
        $this->assertSame(8.0, (float) $top[0]['quantity']);
        $this->assertSame('kg', $top[0]['unit']);
    }

    // ---------------------------------------------------------------------------
    // Branch scoping
    // ---------------------------------------------------------------------------

    public function test_branch_scoping_excludes_other_branch_data(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create();
        Order::factory()->create([
            'branch_id' => $otherBranch->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'total' => 9999,
            'created_at' => '2025-06-10',
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/analytics?'.http_build_query($this->defaultParams()));

        $this->assertSame(0, $response->json('sales.kpis.total_orders'));
        $this->assertSame(0.00, $response->json('sales.kpis.total_revenue'));
    }
}
