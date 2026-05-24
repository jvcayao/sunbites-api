<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosMenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosMenuItemTest extends TestCase
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

    public function test_admin_can_view_menu_items(): void
    {
        PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asUser($this->admin)->getJson('/api/v1/pos/menu-items');

        $response->assertOk();
        $response->assertJsonStructure([['id', 'name', 'price', 'category', 'is_available', 'sort_order']]);
    }

    public function test_admin_can_create_menu_item(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/pos/menu-items', [
            'name' => 'Test Meal',
            'price' => '50.00',
            'category' => 'meal',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pos_menu_items', [
            'branch_id' => $this->branch->id,
            'name' => 'Test Meal',
            'category' => 'meal',
        ]);
    }

    public function test_manager_can_create_menu_item(): void
    {
        $response = $this->asUser($this->manager)->postJson('/api/v1/pos/menu-items', [
            'name' => 'Manager Snack',
            'price' => '25.00',
            'category' => 'snack',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pos_menu_items', ['name' => 'Manager Snack']);
    }

    public function test_supervisor_cannot_create_menu_item(): void
    {
        $response = $this->asUser($this->supervisor)->postJson('/api/v1/pos/menu-items', [
            'name' => 'Supervisor Item',
            'price' => '10.00',
            'category' => 'extra',
        ]);

        $response->assertForbidden();
    }

    public function test_cashier_cannot_create_menu_item(): void
    {
        $response = $this->asUser($this->cashier)->postJson('/api/v1/pos/menu-items', [
            'name' => 'Cashier Item',
            'price' => '10.00',
            'category' => 'extra',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_toggle_availability(): void
    {
        $item = PosMenuItem::factory()->create(['branch_id' => $this->branch->id, 'is_available' => true]);

        $response = $this->asUser($this->admin)->postJson("/api/v1/pos/menu-items/{$item->id}/toggle");

        $response->assertOk();
        $response->assertJson(['is_available' => false]);
        $this->assertDatabaseHas('pos_menu_items', ['id' => $item->id, 'is_available' => false]);
    }

    public function test_admin_can_delete_menu_item(): void
    {
        $item = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asUser($this->admin)->deleteJson("/api/v1/pos/menu-items/{$item->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('pos_menu_items', ['id' => $item->id]);
    }

    public function test_menu_items_are_branch_scoped(): void
    {
        $ownItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherItem = PosMenuItem::withoutBranch()->create([
            'branch_id' => $otherBranch->id,
            'name' => 'Other Branch Item',
            'price' => '10.00',
            'category' => 'snack',
        ]);

        $response = $this->asUser($this->admin)->getJson('/api/v1/pos/menu-items');

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertContains($ownItem->id, $ids);
        $this->assertNotContains($otherItem->id, $ids);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/pos/menu-items', []);

        $response->assertJsonValidationErrors(['name', 'price', 'category']);
    }

    public function test_store_validates_invalid_category(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/pos/menu-items', [
            'name' => 'Test',
            'price' => '10.00',
            'category' => 'invalid_category',
        ]);

        $response->assertJsonValidationErrors(['category']);
    }
}
