<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\PosMenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The checkout rules moved into CheckoutRequest unchanged (Spec 16 T-3.2).
 */
class CheckoutRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_unknown_payment_method_returns_422_with_the_enum_message(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $menuItem->id, 'quantity' => 1]],
            'payment_method' => 'bitcoin',
        ]);

        $response->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Validation failed.',
                'errors' => ['payment_method' => ['The selected payment method is invalid.']],
            ]);
        $this->assertDatabaseCount('orders', 0);
    }
}
