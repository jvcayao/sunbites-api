<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        return $this->actingAs($user)->withSession(['active_branch_id' => $this->branch->id]);
    }

    private function makeItem(array $attrs = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge(['branch_id' => $this->branch->id], $attrs));
    }

    public function test_admin_can_view_inventory(): void
    {
        $this->makeItem();

        $response = $this->asUser($this->admin)->get(route('kitchen.pos.inventory.index'));

        $response->assertOk();
    }

    public function test_supervisor_can_view_inventory(): void
    {
        $this->makeItem();

        $response = $this->asUser($this->supervisor)->get(route('kitchen.pos.inventory.index'));

        $response->assertOk();
    }

    public function test_cashier_cannot_view_inventory(): void
    {
        $response = $this->asUser($this->cashier)->get(route('kitchen.pos.inventory.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00, 'restock_threshold' => 5.00]);

        $response = $this->asUser($this->admin)->post(route('kitchen.pos.inventory.adjust', $item), [
            'type' => 'restock',
            'direction' => 'add',
            'quantity' => '5',
            'reason' => 'Test restock',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'quantity' => '15.00']);
    }

    public function test_supervisor_can_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00]);

        $response = $this->asUser($this->supervisor)->post(route('kitchen.pos.inventory.adjust', $item), [
            'type' => 'waste',
            'direction' => 'deduct',
            'quantity' => '3',
            'reason' => 'Spoiled goods',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'quantity' => '7.00']);
    }

    public function test_cashier_cannot_adjust_stock(): void
    {
        $item = $this->makeItem(['quantity' => 10.00]);

        $response = $this->asUser($this->cashier)->post(route('kitchen.pos.inventory.adjust', $item), [
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

        $this->asUser($this->admin)->post(route('kitchen.pos.inventory.adjust', $item), [
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

        $this->asUser($this->admin)->post(route('kitchen.pos.inventory.adjust', $item), [
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

        $response = $this->asUser($this->admin)->get(route('kitchen.pos.inventory.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('inventoryItems')
            ->where('inventoryItems', fn ($items) => collect($items)->contains('id', $ownItem->id) &&
                ! collect($items)->contains('id', $otherItem->id))
        );
    }

    public function test_references_inventory_admin_can_add_item(): void
    {
        $response = $this->asUser($this->admin)->post(route('kitchen.references.inventory.store'), [
            'name' => 'New Item',
            'quantity' => '25',
            'unit' => 'pcs',
            'restock_threshold' => '10',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', [
            'branch_id' => $this->branch->id,
            'name' => 'New Item',
            'unit' => 'pcs',
        ]);
    }

    public function test_references_inventory_admin_can_update_item(): void
    {
        $item = $this->makeItem(['name' => 'Old Name']);

        $response = $this->asUser($this->admin)->put(route('kitchen.references.inventory.update', $item), [
            'name' => 'New Name',
            'unit' => 'kg',
            'restock_threshold' => '15',
        ]);

        $response->assertRedirect();
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
            'reason' => 'Initial stock',
        ]);

        $response = $this->asUser($this->admin)->delete(route('kitchen.references.inventory.destroy', $item));

        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id]);
    }

    public function test_can_delete_item_without_logs(): void
    {
        $item = $this->makeItem();

        $response = $this->asUser($this->admin)->delete(route('kitchen.references.inventory.destroy', $item));

        $response->assertRedirect();
        $this->assertDatabaseMissing('inventory_items', ['id' => $item->id]);
    }
}
