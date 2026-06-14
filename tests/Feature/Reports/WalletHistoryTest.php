<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $supervisor;

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

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
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

    public function test_supervisor_cannot_access_wallet_history(): void
    {
        $response = $this->asSupervisor()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=purchases");

        $response->assertForbidden();
    }

    public function test_purchases_returns_paginated_order_list(): void
    {
        $cashier = User::factory()->create();
        Order::factory()->count(3)->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
        ]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=purchases&per_page=2");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'date', 'description', 'amount']],
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);

        $this->assertEquals(2, count($response->json('data')));
        $this->assertEquals(3, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_purchases_description_contains_order_item_names(): void
    {
        $cashier = User::factory()->create();
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
            'total' => 80.00,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Fried Rice', 'pos_menu_item_id' => $menuItem->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Juice', 'pos_menu_item_id' => $menuItem->id]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=purchases");

        $row = $response->json('data.0');
        $this->assertStringContainsString('Fried Rice', $row['description']);
        $this->assertStringContainsString('Juice', $row['description']);
        $this->assertEquals(80.00, $row['amount']);
    }

    public function test_purchases_search_filters_by_item_name(): void
    {
        $cashier = User::factory()->create();
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $order1 = Order::factory()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
        ]);
        OrderItem::factory()->create(['order_id' => $order1->id, 'name' => 'Fried Rice', 'pos_menu_item_id' => $menuItem->id]);

        $order2 = Order::factory()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
        ]);
        OrderItem::factory()->create(['order_id' => $order2->id, 'name' => 'Spaghetti', 'pos_menu_item_id' => $menuItem->id]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=purchases&search=rice");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('Fried Rice', $response->json('data.0.description'));
    }

    public function test_topups_returns_paginated_deposit_list(): void
    {
        $this->student->deposit(50000, ['performed_by' => $this->admin->id]);
        $this->student->deposit(20000, ['performed_by' => $this->admin->id]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=topups");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'date', 'description', 'amount', 'added_by']],
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_topups_added_by_shows_staff_name(): void
    {
        $this->student->deposit(50000, ['performed_by' => $this->admin->id]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=topups");

        $row = $response->json('data.0');
        $this->assertEquals($this->admin->full_name, $row['added_by']);
        $this->assertEquals(500.00, $row['amount']);
    }

    public function test_topups_added_by_falls_back_to_dash_when_no_meta(): void
    {
        $this->student->deposit(50000); // no performed_by in meta

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=topups");

        $this->assertEquals('—', $response->json('data.0.added_by'));
    }

    public function test_voided_orders_are_excluded_from_purchases(): void
    {
        $cashier = User::factory()->create();

        Order::factory()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
            'status' => OrderStatus::Completed,
        ]);

        Order::factory()->voided()->create([
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $cashier->id,
        ]);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$this->student->id}/history?type=purchases");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals(OrderStatus::Completed->value, Order::find($response->json('data.0.id'))->status->value);
    }

    public function test_student_from_other_branch_returns_404(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);

        // Bind active_branch before the request so BranchScope filters correctly
        // during route model binding (SetActiveBranch middleware relies on a prior
        // request having resolved the Sanctum guard in test mode).
        app()->instance('active_branch', $this->branch);

        $response = $this->asAdmin()
            ->getJson("/api/v1/reports/wallet/{$otherStudent->id}/history?type=purchases");

        $response->assertNotFound();
    }
}
