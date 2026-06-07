<?php

namespace Tests\Feature\Reports;

use App\Enums\InventoryLogType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $supervisor;

    private Branch $branch;

    private InventoryItem $item;

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

        $this->item = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Rice',
            'quantity' => 50,
            'restock_threshold' => 10,
        ]);
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

    /** @param  string|null  $createdAt  Y-m-d H:i:s or null for now */
    private function makeLog(InventoryItem $item, InventoryLogType $type, float $quantityChange, ?string $createdAt = null): void
    {
        InventoryLog::create([
            'branch_id' => $item->branch_id,
            'inventory_item_id' => $item->id,
            'adjusted_by' => $this->admin->id,
            'type' => $type->value,
            'quantity_change' => $quantityChange,
            'stock_after' => max(0, (float) $item->quantity + $quantityChange),
            'reason' => 'Test',
            'item_name_snapshot' => $item->name,
            'created_at' => $createdAt ?? now()->toDateTimeString(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Date range filter
    // -------------------------------------------------------------------------

    public function test_date_range_filter_returns_only_logs_within_range(): void
    {
        $this->makeLog($this->item, InventoryLogType::Restock, 10, '2024-01-10 08:00:00');
        $this->makeLog($this->item, InventoryLogType::Restock, 10, '2024-01-15 08:00:00');
        $this->makeLog($this->item, InventoryLogType::Restock, 10, '2024-01-20 08:00:00');

        $response = $this->asAdmin()->getJson('/api/v1/reports/inventory?from=2024-01-11&to=2024-01-18');

        $response->assertOk();
        $this->assertCount(1, $response->json('logs.data'));
    }

    // -------------------------------------------------------------------------
    // Type filter
    // -------------------------------------------------------------------------

    public function test_type_filter_narrows_to_specified_type(): void
    {
        $this->makeLog($this->item, InventoryLogType::Restock, 10);
        $this->makeLog($this->item, InventoryLogType::Manual, -2);
        $this->makeLog($this->item, InventoryLogType::Waste, -1);

        $response = $this->asAdmin()->getJson('/api/v1/reports/inventory?type=restock');

        $response->assertOk();
        $this->assertCount(1, $response->json('logs.data'));
        $this->assertSame('restock', $response->json('logs.data.0.type'));
    }

    // -------------------------------------------------------------------------
    // Item filter
    // -------------------------------------------------------------------------

    public function test_item_filter_narrows_to_single_items_logs(): void
    {
        $other = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Chicken']);
        $this->makeLog($this->item, InventoryLogType::Restock, 10);
        $this->makeLog($other, InventoryLogType::Restock, 5);

        $response = $this->asAdmin()->getJson("/api/v1/reports/inventory?item_id={$this->item->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('logs.data'));
        $this->assertSame('Rice', $response->json('logs.data.0.item_name_snapshot'));
    }

    // -------------------------------------------------------------------------
    // Summary counts
    // -------------------------------------------------------------------------

    public function test_summary_counts_reflect_database_state(): void
    {
        // setUp creates one OK item (quantity=50, threshold=10, no overstock_threshold)
        InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 0, 'restock_threshold' => 10]);
        InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 5, 'restock_threshold' => 10]);
        InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 50, 'restock_threshold' => 10, 'overstock_threshold' => 30]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/inventory');

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.out_of_stock'));
        $this->assertSame(1, $response->json('summary.below_threshold'));
        $this->assertSame(1, $response->json('summary.over_stock'));
        $this->assertSame(4, $response->json('summary.total_items'));
    }

    // -------------------------------------------------------------------------
    // Discrepancy section
    // -------------------------------------------------------------------------

    public function test_discrepancy_section_groups_manual_logs_by_item_with_correct_net_change(): void
    {
        $other = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Chicken']);

        $this->makeLog($this->item, InventoryLogType::Manual, 5);    // Rice: +5
        $this->makeLog($this->item, InventoryLogType::Manual, -3);   // Rice: -3 → net = +2
        $this->makeLog($other, InventoryLogType::Manual, -10);       // Chicken: -10
        $this->makeLog($this->item, InventoryLogType::Restock, 20);  // not a manual log — excluded

        $response = $this->asAdmin()->getJson('/api/v1/reports/inventory');

        $response->assertOk();
        $discrepancy = $response->json('discrepancy');
        $this->assertCount(2, $discrepancy);

        // Sorted by abs(net_change) desc — Chicken(|-10|=10) before Rice(|+2|=2)
        $this->assertSame('Chicken', $discrepancy[0]['item_name']);
        $this->assertSame(1, $discrepancy[0]['adjustment_count']);
        $this->assertEquals(-10, $discrepancy[0]['net_change']);

        $this->assertSame('Rice', $discrepancy[1]['item_name']);
        $this->assertSame(2, $discrepancy[1]['adjustment_count']);
        $this->assertEquals(2, $discrepancy[1]['net_change']);
    }

    public function test_discrepancy_section_is_empty_when_no_manual_logs_in_range(): void
    {
        $this->makeLog($this->item, InventoryLogType::Restock, 10, '2024-01-05 08:00:00');
        $this->makeLog($this->item, InventoryLogType::Waste, -2, '2024-01-05 08:00:00');

        $response = $this->asAdmin()->getJson('/api/v1/reports/inventory?from=2024-01-01&to=2024-01-31');

        $response->assertOk();
        $this->assertEmpty($response->json('discrepancy'));
    }

    // -------------------------------------------------------------------------
    // Auth & roles
    // -------------------------------------------------------------------------

    public function test_supervisor_can_view_inventory_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/inventory');

        $response->assertOk();
    }

    public function test_supervisor_cannot_export_inventory_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/inventory/export');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/inventory');

        $response->assertUnauthorized();
    }
}
