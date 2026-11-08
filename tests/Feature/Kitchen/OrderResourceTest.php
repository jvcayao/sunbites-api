<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Spec 16 B-07: the receipt shows who rang up the order.
 */
class OrderResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_checkout_response_names_the_cashier(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $branch->id]);
        $menuItem->inventoryItems()->attach(
            InventoryItem::factory()->create(['branch_id' => $branch->id, 'quantity' => 100])->id,
            ['quantity_used' => 1],
        );
        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $menuItem->id, 'quantity' => 1]],
            'payment_method' => 'gcash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.cashier', ['id' => $cashier->id, 'name' => 'Maria Santos']);
    }
}
