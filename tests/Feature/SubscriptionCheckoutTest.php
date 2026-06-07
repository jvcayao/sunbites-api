<?php

namespace Tests\Feature;

use App\Enums\MenuCategory;
use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private PosMenuItem $mealItem;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'sub']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $this->mealItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Meal->value,
            'price' => 135.00,
            'is_available' => true,
        ]);

        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 9999,
        ]);
        $this->mealItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        // Default config: meal limit = 1
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function subscriptionCartPayload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student->id,
            'items' => [['pos_menu_item_id' => $this->mealItem->id, 'quantity' => 1]],
            'payment_method' => 'subscription',
        ], $overrides);
    }

    public function test_subscription_checkout_blocked_when_item_is_not_subscription_eligible(): void
    {
        $ineligibleItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Snack->value,
            'is_available' => true,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $ineligibleItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload([
            'items' => [['pos_menu_item_id' => $ineligibleItem->id, 'quantity' => 1]],
        ]));

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Item "'.$ineligibleItem->name.'" is not eligible for subscription payment.']);
    }

    public function test_subscription_checkout_blocked_when_item_is_unconfigured(): void
    {
        $unconfiguredItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Snack->value,
            'is_available' => true,
            'is_subscription_item' => false,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $unconfiguredItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload([
            'items' => [['pos_menu_item_id' => $unconfiguredItem->id, 'quantity' => 1]],
        ]));

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Item "'.$unconfiguredItem->name.'" is not eligible for subscription payment.']);
    }

    public function test_subscription_checkout_succeeds_when_all_items_eligible_and_within_limits(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'student_id' => $this->student->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
        ]);
    }

    public function test_subscription_checkout_blocked_when_daily_category_limit_met(): void
    {
        // Simulate an existing completed subscription order with a meal item today
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $this->student->id,
            'cashier_id' => $this->cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->mealItem->id,
            'quantity' => 1,
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload());

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Daily meal limit of 1 reached for this student.']);
    }

    public function test_subscription_checkout_allowed_when_different_category_is_not_yet_used(): void
    {
        // Meal limit is met
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $this->student->id,
            'cashier_id' => $this->cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->mealItem->id,
            'quantity' => 1,
        ]);

        // Request a snack instead
        $snackItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Snack->value,
            'is_available' => true,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $snackItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload([
            'items' => [['pos_menu_item_id' => $snackItem->id, 'quantity' => 1]],
        ]));

        $response->assertCreated();
    }

    public function test_voided_subscription_order_is_excluded_from_daily_limit_count(): void
    {
        // A voided subscription order should NOT count toward the daily limit
        $order = Order::factory()->voided()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $this->student->id,
            'cashier_id' => $this->cashier->id,
            'payment_method' => 'subscription',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->mealItem->id,
            'quantity' => 1,
        ]);

        // Should succeed — voided order doesn't consume the allowance
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload());

        $response->assertCreated();
    }

    public function test_daily_limits_are_independent_across_categories(): void
    {
        BranchSubscriptionConfig::where('branch_id', $this->branch->id)->update([
            'meal_daily_limit' => 2,
            'snack_daily_limit' => 1,
        ]);

        // Use up snack limit (1)
        $snackItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Snack->value,
            'is_available' => true,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $snackItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $this->student->id,
            'cashier_id' => $this->cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $snackItem->id,
            'quantity' => 1,
        ]);

        // Meal (limit=2, used=0) should still be allowed
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->subscriptionCartPayload());

        $response->assertCreated();
    }
}
