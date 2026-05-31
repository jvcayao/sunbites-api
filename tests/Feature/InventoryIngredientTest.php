<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryIngredientTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    private PosMenuItem $menuItem;

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

        $this->menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function makeInventoryItem(array $attrs = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_merge(['branch_id' => $this->branch->id], $attrs));
    }

    public function test_admin_can_list_ingredients_for_menu_item(): void
    {
        $invItem = $this->makeInventoryItem();
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 2]);

        $response = $this->asUser($this->admin)
            ->getJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients");

        $response->assertOk();
        $response->assertJsonFragment(['inventory_item_id' => $invItem->id, 'quantity_used' => 2]);
    }

    public function test_supervisor_can_list_ingredients_for_menu_item(): void
    {
        $response = $this->asUser($this->supervisor)
            ->getJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients");

        $response->assertOk();
    }

    public function test_admin_can_attach_ingredient_to_menu_item(): void
    {
        $invItem = $this->makeInventoryItem();

        $response = $this->asUser($this->admin)
            ->postJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients", [
                'inventory_item_id' => $invItem->id,
                'quantity_used' => 3,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('pos_menu_item_inventory', [
            'pos_menu_item_id' => $this->menuItem->id,
            'inventory_item_id' => $invItem->id,
            'quantity_used' => 3,
        ]);
    }

    public function test_manager_can_attach_ingredient_to_menu_item(): void
    {
        $invItem = $this->makeInventoryItem();

        $response = $this->asUser($this->manager)
            ->postJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients", [
                'inventory_item_id' => $invItem->id,
                'quantity_used' => 1,
            ]);

        $response->assertStatus(201);
    }

    public function test_duplicate_attach_is_idempotent(): void
    {
        $invItem = $this->makeInventoryItem();

        $this->asUser($this->admin)
            ->postJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients", [
                'inventory_item_id' => $invItem->id,
                'quantity_used' => 1,
            ]);

        $this->asUser($this->admin)
            ->postJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients", [
                'inventory_item_id' => $invItem->id,
                'quantity_used' => 2,
            ]);

        $this->assertDatabaseCount('pos_menu_item_inventory', 1);
    }

    public function test_admin_can_detach_ingredient_from_menu_item(): void
    {
        $invItem = $this->makeInventoryItem();
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asUser($this->admin)
            ->deleteJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients/{$invItem->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('pos_menu_item_inventory', [
            'pos_menu_item_id' => $this->menuItem->id,
            'inventory_item_id' => $invItem->id,
        ]);
    }

    public function test_supervisor_cannot_attach_ingredient(): void
    {
        $invItem = $this->makeInventoryItem();

        $response = $this->asUser($this->supervisor)
            ->postJson("/api/v1/references/menu-items/{$this->menuItem->id}/ingredients", [
                'inventory_item_id' => $invItem->id,
                'quantity_used' => 1,
            ]);

        $response->assertForbidden();
    }

    public function test_has_inventory_mapping_reflects_correct_state(): void
    {
        $invItem = $this->makeInventoryItem();

        // Before mapping — should be false
        $before = $this->asUser($this->admin)->getJson('/api/v1/pos/menu-items');
        $before->assertOk();
        $itemBefore = collect($before->json())->firstWhere('id', $this->menuItem->id);
        $this->assertFalse($itemBefore['has_inventory_mapping']);

        // After mapping — should be true
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $after = $this->asUser($this->admin)->getJson('/api/v1/pos/menu-items');
        $after->assertOk();
        $itemAfter = collect($after->json())->firstWhere('id', $this->menuItem->id);
        $this->assertTrue($itemAfter['has_inventory_mapping']);
    }
}
