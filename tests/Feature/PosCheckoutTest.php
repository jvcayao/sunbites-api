<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'ant']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->menuItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'price' => 135.00,
            'is_available' => true,
        ]);

        // All checkout tests require at least one inventory mapping per spec
        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 9999,
        ]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function cartPayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ], $overrides);
    }

    public function test_cash_checkout_creates_order_with_correct_receipt_number(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $response->assertCreated()
            ->assertJsonStructure(['order' => ['id', 'receipt_number', 'total', 'status']]);

        $receiptNumber = $response->json('order.receipt_number');
        $this->assertStringStartsWith('ANT-'.now()->year.'-', $receiptNumber);
        $this->assertDatabaseHas('orders', ['receipt_number' => $receiptNumber, 'status' => 'completed']);
    }

    public function test_cash_checkout_creates_order_items_with_name_price_snapshots(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $response->assertCreated();

        $orderId = $response->json('order.id');
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'pos_menu_item_id' => $this->menuItem->id,
            'name' => $this->menuItem->name,
            'price' => '135.00',
            'quantity' => 1,
            'line_total' => '135.00',
        ]);
    }

    public function test_cash_checkout_walk_in_has_null_student_id(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'receipt_number' => $response->json('order.receipt_number'),
            'student_id' => null,
        ]);
    }

    public function test_gcash_checkout_with_reference_number(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'payment_method' => 'gcash',
            'reference_number' => 'GCASH12345',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'payment_method' => 'gcash',
            'reference_number' => 'GCASH12345',
        ]);
    }

    public function test_gcash_checkout_without_reference_number_is_allowed(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'payment_method' => 'gcash',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
    }

    public function test_wallet_checkout_with_sufficient_balance_succeeds(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(20000); // ₱200.00

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'wallet',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
        $student->load('wallet');
        $this->assertEquals(65.0, $student->wallet->balanceFloat); // ₱200 - ₱135
    }

    public function test_points_are_earned_when_crossing_threshold(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'total_spent' => 900, // just below 1000 threshold
            'points' => 0,
        ]);
        $student->deposit(20000); // ₱200 wallet

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'wallet',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
        $this->assertEquals(1, $response->json('order.points_earned'));
        $student->refresh();
        $this->assertEquals(1, $student->points);
        $this->assertEquals(1035.00, (float) $student->total_spent);
    }

    public function test_checkout_fails_with_empty_cart(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'items' => [],
            'payment_method' => 'cash',
        ]);

        $response->assertUnprocessable();
    }

    public function test_wallet_payment_is_blocked_for_walk_in(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'payment_method' => 'wallet',
            'amount_tendered' => null,
        ]));

        $response->assertUnprocessable();
    }

    public function test_discount_can_only_be_applied_by_admin_or_manager(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'discount_reason' => 'Student discount',
        ]));

        $response->assertForbidden();
    }

    public function test_admin_can_apply_discount(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($admin, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/pos/checkout', $this->cartPayload([
                'discount_type' => 'fixed',
                'discount_value' => 20,
                'discount_reason' => 'Staff discount',
            ]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['discount_amount' => '20.00', 'total' => '115.00']);
    }

    public function test_order_notes_are_sanitized_with_strip_tags(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'notes' => '<b>No onions</b><script>alert(1)</script>',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['notes' => 'No onionsalert(1)']);
    }

    public function test_receipt_numbers_are_sequential(): void
    {
        $first = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());
        $second = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $first->assertCreated();
        $second->assertCreated();

        $firstSeq = (int) substr($first->json('order.receipt_number'), -6);
        $secondSeq = (int) substr($second->json('order.receipt_number'), -6);

        $this->assertEquals(1, $secondSeq - $firstSeq);
    }

    public function test_subscription_checkout_succeeds_for_subscription_student(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'student_id' => $student->id,
            'payment_method' => 'subscription',
            'is_credit' => false,
            'credit_amount' => '0.00',
        ]);
    }

    public function test_subscription_checkout_updates_total_spent_and_points(): void
    {
        $student = Student::factory()->subscription()->create([
            'branch_id' => $this->branch->id,
            'total_spent' => 900,
            'points' => 0,
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
            'amount_tendered' => null,
        ]));

        $response->assertCreated();
        $student->refresh();
        $this->assertEquals(1035.00, (float) $student->total_spent);
        $this->assertEquals(1, $student->points);
    }

    public function test_subscription_checkout_does_not_touch_wallet_balance(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $student->deposit(20000); // ₱200.00

        $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
            'amount_tendered' => null,
        ]));

        $student->load('wallet');
        $this->assertEquals(200.0, $student->wallet->balanceFloat); // Wallet untouched
    }

    public function test_subscription_payment_is_blocked_for_non_subscription_student(): void
    {
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
            'amount_tendered' => null,
        ]));

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'This payment method is only available for subscription students.']);
    }

    public function test_subscription_payment_is_blocked_for_walk_in(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'payment_method' => 'subscription',
            'amount_tendered' => null,
        ]));

        $response->assertUnprocessable();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $response->assertUnauthorized();
    }

    public function test_checkout_blocked_when_cart_item_has_no_inventory_mapping(): void
    {
        // Create a fresh menu item with no inventory mapping
        $unmappedItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 50.00,
            'is_available' => true,
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $unmappedItem->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'One or more items are not configured for inventory tracking. Please contact your administrator.',
        ]);
    }

    public function test_checkout_blocked_when_linked_inventory_item_is_out_of_stock(): void
    {
        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 0,
        ]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('out of stock', $response->json('message'));
    }

    public function test_successful_checkout_deducts_inventory_and_creates_sale_log(): void
    {
        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 48,
            'name' => 'Juice Tetra Pack',
        ]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cartPayload([
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 2]],
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $invItem->id,
            'quantity' => '46.00',
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'inventory_item_id' => $invItem->id,
            'type' => 'sale',
            'quantity_change' => '-2.00',
            'item_name_snapshot' => 'Juice Tetra Pack',
        ]);
    }
}
