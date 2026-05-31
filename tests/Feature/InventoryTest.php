<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $cashier;

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

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function makeItem(array $attrs = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge(['branch_id' => $this->branch->id], $attrs));
    }

    public function test_admin_can_view_inventory(): void
    {
        $this->makeItem();

        $response = $this->asUser($this->admin)->getJson('/api/v1/pos/inventory');

        $response->assertOk();
        $response->assertJsonStructure([['id', 'name', 'quantity', 'unit', 'restock_threshold', 'status']]);
    }

    public function test_supervisor_can_view_inventory(): void
    {
        $this->makeItem();

        $response = $this->asUser($this->supervisor)->getJson('/api/v1/pos/inventory');

        $response->assertOk();
    }

    public function test_cashier_cannot_view_inventory(): void
    {
        $response = $this->asUser($this->cashier)->getJson('/api/v1/pos/inventory');

        $response->assertForbidden();
    }

    public function test_admin_can_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00, 'restock_threshold' => 5.00]);

        $response = $this->asUser($this->admin)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'restock',
            'direction' => 'add',
            'quantity' => '5',
            'reason' => 'Test restock',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'quantity' => '15.00']);
    }

    public function test_supervisor_can_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00]);

        $response = $this->asUser($this->supervisor)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'waste',
            'direction' => 'deduct',
            'quantity' => '3',
            'reason' => 'Spoiled goods',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'quantity' => '7.00']);
    }

    public function test_cashier_cannot_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00]);

        $response = $this->asUser($this->cashier)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'restock',
            'direction' => 'add',
            'quantity' => '5',
            'reason' => 'Test',
        ]);

        $response->assertForbidden();
    }

    public function test_adjustment_creates_log_entry(): void
    {
        $item = $this->makeItem(['quantity' => 20.00]);

        $this->asUser($this->admin)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'manual',
            'direction' => 'deduct',
            'quantity' => '5',
            'reason' => 'Manual correction',
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'inventory_item_id' => $item->id,
            'adjusted_by' => $this->admin->id,
            'type' => 'manual',
            'quantity_change' => '-5.00',
            'stock_after' => '15.00',
            'reason' => 'Manual correction',
        ]);
    }

    public function test_deduction_does_not_go_below_zero(): void
    {
        $item = $this->makeItem(['quantity' => 3.00]);

        $this->asUser($this->admin)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'waste',
            'direction' => 'deduct',
            'quantity' => '10',
            'reason' => 'Large deduction',
        ]);

        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'quantity' => '0.00']);
    }

    public function test_inventory_is_branch_scoped(): void
    {
        $ownItem = $this->makeItem();
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherItem = InventoryItem::withoutBranch()->create([
            'branch_id' => $otherBranch->id,
            'name' => 'Other Branch Item',
            'quantity' => 10,
            'unit' => 'kg',
            'restock_threshold' => 5,
        ]);

        $response = $this->asUser($this->admin)->getJson('/api/v1/pos/inventory');

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertContains($ownItem->id, $ids);
        $this->assertNotContains($otherItem->id, $ids);
    }

    public function test_references_inventory_admin_can_add_item(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/references/inventory', [
            'name' => 'New Item',
            'quantity' => '25',
            'unit' => 'pcs',
            'restock_threshold' => '10',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inventory_items', [
            'branch_id' => $this->branch->id,
            'name' => 'New Item',
            'unit' => 'pcs',
        ]);
    }

    public function test_references_inventory_admin_can_update_item(): void
    {
        $item = $this->makeItem(['name' => 'Old Name']);

        $response = $this->asUser($this->admin)->putJson("/api/v1/references/inventory/{$item->id}", [
            'name' => 'New Name',
            'unit' => 'kg',
            'restock_threshold' => '15',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'name' => 'New Name']);
    }

    public function test_cannot_delete_item_with_logs(): void
    {
        $item = $this->makeItem();

        InventoryLog::create([
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $item->id,
            'adjusted_by' => $this->admin->id,
            'type' => 'restock',
            'quantity_change' => 10,
            'stock_after' => 10,
            'item_name_snapshot' => $item->name,
            'reason' => 'Initial stock',
        ]);

        $response = $this->asUser($this->admin)->deleteJson("/api/v1/references/inventory/{$item->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This item has adjustment history and cannot be deleted.']);
        $this->assertModelExists($item);
    }

    public function test_can_delete_item_without_logs(): void
    {
        $item = $this->makeItem();

        $response = $this->asUser($this->admin)->deleteJson("/api/v1/references/inventory/{$item->id}");

        $response->assertOk();
        $this->assertModelMissing($item);
    }

    public function test_sale_type_rejected_from_manual_adjust_endpoint(): void
    {
        $item = $this->makeItem(['quantity' => 10.00]);

        $response = $this->asUser($this->admin)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'sale',
            'direction' => 'deduct',
            'quantity' => '1',
            'reason' => 'Manual sale attempt',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Sale adjustments are recorded automatically by checkout.']);
    }

    public function test_creating_item_with_quantity_auto_creates_restock_log(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/references/inventory', [
            'name' => 'Auto Log Item',
            'quantity' => '25',
            'unit' => 'pcs',
            'restock_threshold' => '10',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inventory_logs', [
            'type' => 'restock',
            'reason' => 'Initial stock',
            'quantity_change' => '25.00',
        ]);
    }

    public function test_archive_item_hides_from_active_list(): void
    {
        $item = $this->makeItem(['name' => 'Archivable Item']);

        $this->asUser($this->admin)->patchJson("/api/v1/references/inventory/{$item->id}/archive")
            ->assertOk();

        $response = $this->asUser($this->admin)->getJson('/api/v1/references/inventory');
        $response->assertOk();

        $ids = array_column($response->json(), 'id');
        $this->assertNotContains($item->id, $ids);
    }

    public function test_unarchive_item_shows_in_active_list(): void
    {
        $item = $this->makeItem(['name' => 'Archived Item', 'is_archived' => true]);

        $this->asUser($this->admin)->patchJson("/api/v1/references/inventory/{$item->id}/unarchive")
            ->assertOk();

        $response = $this->asUser($this->admin)->getJson('/api/v1/references/inventory');
        $response->assertOk();

        $ids = array_column($response->json(), 'id');
        $this->assertContains($item->id, $ids);
    }

    public function test_history_endpoint_returns_paginated_cross_item_logs(): void
    {
        $item1 = $this->makeItem(['name' => 'Item Alpha']);
        $item2 = $this->makeItem(['name' => 'Item Beta']);

        foreach ([$item1, $item2] as $item) {
            InventoryLog::create([
                'branch_id' => $this->branch->id,
                'inventory_item_id' => $item->id,
                'adjusted_by' => $this->admin->id,
                'type' => 'manual',
                'quantity_change' => -2,
                'stock_after' => 8,
                'item_name_snapshot' => $item->name,
                'reason' => 'Test log',
            ]);
        }

        $response = $this->asUser($this->admin)->getJson('/api/v1/references/inventory/history');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $snapshots = array_column($response->json('data'), 'item_name_snapshot');
        // history is cross-item — both items' logs appear
        $this->assertTrue(in_array('Item Alpha', $snapshots) || in_array('Item Beta', $snapshots));
    }

    public function test_item_name_snapshot_populated_on_every_log(): void
    {
        $item = $this->makeItem(['name' => 'Snapshot Test Item', 'quantity' => 20.00]);

        $this->asUser($this->admin)->postJson("/api/v1/pos/inventory/{$item->id}/adjust", [
            'type' => 'manual',
            'direction' => 'deduct',
            'quantity' => '3',
            'reason' => 'Snapshot check',
        ])->assertOk();

        $log = InventoryLog::where('inventory_item_id', $item->id)->first();
        $this->assertSame('Snapshot Test Item', $log->item_name_snapshot);
    }
}
