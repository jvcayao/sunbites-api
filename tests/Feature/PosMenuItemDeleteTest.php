<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A menu item that was ever sold keeps its sales history (Spec 16 B-20).
 */
class PosMenuItemDeleteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    /**
     * Debug mode is off, as in production and CI, so the body has no exception details.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_deleting_a_sold_menu_item_returns_422_and_keeps_it(): void
    {
        $item = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        OrderItem::factory()->create(['pos_menu_item_id' => $item->id]);

        $response = $this->asManager()->deleteJson("/api/v1/pos/menu-items/{$item->id}");

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'This menu item has sales history and cannot be deleted. Mark it as unavailable instead.',
        ]);
        $this->assertModelExists($item);
        $this->assertFalse(Activity::whereMorphedTo('subject', $item)->where('description', 'menu.item_deleted')->exists());
    }

    public function test_deleting_an_unsold_menu_item_removes_it(): void
    {
        $item = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->deleteJson("/api/v1/pos/menu-items/{$item->id}");

        $response->assertOk()->assertExactJson(['message' => 'Menu item deleted.']);
        $this->assertModelMissing($item);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
