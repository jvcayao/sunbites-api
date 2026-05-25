<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Enums\StaffStatus;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\StaffDailyStatus;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

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

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_dashboard_returns_student_stat_cards(): void
    {
        Student::factory()->count(3)->create(['branch_id' => $this->branch->id, 'enrollment_status' => 'enrolled']);
        Student::factory()->create(['branch_id' => $this->branch->id, 'enrollment_status' => 'paused']);

        $response = $this->asAdmin()->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('total_students', 4)
            ->assertJsonPath('enrolled_count', 3);
    }

    public function test_dashboard_returns_meals_today_count(): void
    {
        Order::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Completed,
        ]);
        Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'status' => OrderStatus::Voided,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('meals_today', 2);
    }

    public function test_dashboard_shows_low_stock_items(): void
    {
        InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Rice',
            'quantity' => 0,
            'restock_threshold' => 10,
        ]);
        InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Chicken',
            'quantity' => 5,
            'restock_threshold' => 10,
        ]);
        InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Oil',
            'quantity' => 50,
            'restock_threshold' => 10,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/dashboard');

        $response->assertOk();

        $lowStock = $response->json('low_stock');
        $this->assertCount(2, $lowStock);

        $riceItem = collect($lowStock)->firstWhere('name', 'Rice');
        $this->assertEquals('out', $riceItem['status']);

        $chickenItem = collect($lowStock)->firstWhere('name', 'Chicken');
        $this->assertEquals('low', $chickenItem['status']);
    }

    public function test_dashboard_shows_credit_alerts(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 0,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/dashboard');

        $response->assertOk();
        $this->assertCount(1, $response->json('credit_alerts'));
        $this->assertEquals(150.0, $response->json('credit_alerts.0.credit_balance'));
    }

    public function test_supervisor_can_access_dashboard(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/dashboard');

        $response->assertOk();
    }

    public function test_staff_status_upsert_creates_record(): void
    {
        $staffUser = User::factory()->create();

        $response = $this->asAdmin()->postJson('/api/v1/dashboard/staff-status', [
            'user_id' => $staffUser->id,
            'status' => StaffStatus::OnLeave->value,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('staff_daily_statuses', [
            'user_id' => $staffUser->id,
            'branch_id' => $this->branch->id,
            'status' => StaffStatus::OnLeave->value,
        ]);
    }

    public function test_staff_status_upsert_updates_existing_record(): void
    {
        $staffUser = User::factory()->create();

        StaffDailyStatus::create([
            'user_id' => $staffUser->id,
            'branch_id' => $this->branch->id,
            'date' => today(),
            'status' => StaffStatus::Working->value,
            'updated_by' => $this->admin->id,
        ]);

        $this->asAdmin()->postJson('/api/v1/dashboard/staff-status', [
            'user_id' => $staffUser->id,
            'status' => StaffStatus::OnBreak->value,
        ]);

        $this->assertDatabaseCount('staff_daily_statuses', 1);
        $this->assertDatabaseHas('staff_daily_statuses', [
            'user_id' => $staffUser->id,
            'status' => StaffStatus::OnBreak->value,
        ]);
    }

    public function test_staff_roster_defaults_working_when_no_status_record(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/dashboard');

        $response->assertOk();

        // The admin user is in the branch — verify roster contains them
        $roster = $response->json('staff_roster');
        $adminEntry = collect($roster)->firstWhere('id', $this->admin->id);

        $this->assertNotNull($adminEntry);
        $this->assertEquals(StaffStatus::Working->value, $adminEntry['status']);
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson('/api/v1/dashboard');

        $response->assertUnauthorized();
    }

    public function test_cashier_cannot_access_dashboard(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson('/api/v1/dashboard');

        $response->assertForbidden();
    }
}
