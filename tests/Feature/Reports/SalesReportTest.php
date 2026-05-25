<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_admin_can_view_sales_report(): void
    {
        Order::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'total' => 100.00,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales');

        $response->assertOk()->assertJsonStructure(['data', 'meta', 'summary']);
        $this->assertEquals(3, $response->json('summary.total_orders'));
        $this->assertEquals(300.0, $response->json('summary.total_revenue'));
    }

    public function test_supervisor_can_view_sales_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/sales');

        $response->assertOk();
    }

    public function test_payment_method_filter_works(): void
    {
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Cash,
        ]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Wallet,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales?payment_method=cash');

        $response->assertOk()
            ->assertJsonPath('summary.total_orders', 1);
    }

    public function test_walk_in_filter_returns_only_walk_in_orders(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'student_id' => null,
        ]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'student_id' => $student->id,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales?is_walk_in=1');

        $response->assertOk()
            ->assertJsonPath('summary.total_orders', 1);
    }

    public function test_report_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create();
        Order::factory()->count(2)->create([
            'branch_id' => $otherBranch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
        ]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales');

        $response->assertOk()
            ->assertJsonPath('summary.total_orders', 1);
    }

    public function test_supervisor_cannot_export_sales_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/sales/export');

        $response->assertForbidden();
    }

    public function test_manager_can_export_sales_report(): void
    {
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->manager->id,
            'status' => OrderStatus::Completed,
        ]);

        $response = $this->asManager()->getJson('/api/v1/reports/sales/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_voided_orders_excluded_from_sales_report(): void
    {
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
        ]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Voided,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales');

        $response->assertOk()
            ->assertJsonPath('summary.total_orders', 1);
    }

    public function test_summary_net_revenue_excludes_discounts(): void
    {
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
            'total' => 90.00,
            'discount_amount' => 10.00,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/sales');

        $response->assertOk();
        $this->assertEquals(90.0, $response->json('summary.total_revenue'));
        $this->assertEquals(10.0, $response->json('summary.total_discounts'));
        $this->assertEquals(80.0, $response->json('summary.net_revenue'));
    }
}
