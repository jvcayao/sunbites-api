<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosVoidTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $cashier;

    private Branch $branch;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'vd']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 135.00,
        ]);

        // Checkout requires inventory mapping — attach a well-stocked item
        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 9999,
        ]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function createCashOrder(array $attributes = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash->value,
            'total' => 135.00,
            'status' => OrderStatus::Completed->value,
        ], $attributes));

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->menuItem->id,
            'name' => $this->menuItem->name,
            'price' => 135.00,
            'quantity' => 1,
            'line_total' => 135.00,
        ]);

        return $order;
    }

    public function test_admin_can_void_a_cash_order(): void
    {
        $order = $this->createCashOrder();

        $response = $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Customer cancelled.',
        ]);

        $response->assertOk()->assertJsonPath('order.status', 'voided');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'voided',
            'void_reason' => 'Customer cancelled.',
        ]);
    }

    public function test_cashier_cannot_void_orders(): void
    {
        $order = $this->createCashOrder();

        $response = $this->asCashier()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Attempted void.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }

    public function test_voiding_wallet_order_refunds_student_wallet(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(13500); // ₱135

        // Check out with wallet first
        Sanctum::actingAs($this->admin, ['staff']);
        $checkout = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/pos/checkout', [
                'student_id' => $student->id,
                'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
                'payment_method' => 'wallet',
            ]);

        $checkout->assertCreated();
        $orderId = $checkout->json('order.id');
        $student->load('wallet');
        $this->assertEquals(0.0, $student->wallet->balanceFloat);

        // Now void
        $void = $this->asAdmin()->postJson("/api/v1/pos/transactions/{$orderId}/void", [
            'void_reason' => 'Wrong student scanned.',
        ]);

        $void->assertOk();
        $student->load('wallet');
        $this->assertEquals(135.0, $student->wallet->balanceFloat);
    }

    public function test_voiding_credit_order_decrements_credit_balance(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 85.00,
        ]);

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'student_id' => $student->id,
            'payment_method' => PaymentMethod::Wallet->value,
            'is_credit' => true,
            'credit_amount' => 85.00,
            'total' => 85.00,
            'status' => OrderStatus::Completed->value,
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Credit order voided.',
        ]);

        $response->assertOk();

        $student->refresh();
        $this->assertEquals(0.0, (float) $student->credit_balance);

        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $student->id,
            'order_id' => $order->id,
            'type' => CreditTransactionType::Voided->value,
            'amount' => '85.00',
        ]);
    }

    public function test_voiding_restores_total_spent_and_recalculates_points(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'total_spent' => 1135.00, // 1 point earned
            'points' => 1,
        ]);

        $order = $this->createCashOrder(['student_id' => $student->id, 'total' => 135.00]);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Refund request.',
        ])->assertOk();

        $student->refresh();
        $this->assertEquals(1000.0, (float) $student->total_spent);
        // Still 1 point since 1000 still >= threshold
        $this->assertEquals(1, $student->points);
    }

    public function test_already_voided_order_cannot_be_voided_again(): void
    {
        $order = $this->createCashOrder(['status' => OrderStatus::Voided->value]);

        $response = $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Second void attempt.',
        ]);

        $response->assertUnprocessable();
    }

    public function test_void_reason_is_required(): void
    {
        $order = $this->createCashOrder();

        $response = $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", []);

        $response->assertUnprocessable()->assertJsonValidationErrors(['void_reason']);
    }

    public function test_void_restores_inventory_stock(): void
    {
        $invItem = InventoryItem::factory()->create([
            'branch_id' => $this->branch->id,
            'quantity' => 44,
            'name' => 'Bread Roll',
        ]);

        $order = $this->createCashOrder();

        // Simulate the sale log that checkout would have created
        InventoryLog::create([
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $invItem->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->admin->id,
            'type' => InventoryLogType::Sale->value,
            'quantity_change' => '-4.00',
            'stock_after' => '44.00',
            'item_name_snapshot' => 'Bread Roll',
            'reason' => 'Order #'.$order->receipt_number,
        ]);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Wrong order.',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $invItem->id,
            'quantity' => '48.00',
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'inventory_item_id' => $invItem->id,
            'type' => 'restock',
            'quantity_change' => '4.00',
            'item_name_snapshot' => 'Bread Roll',
        ]);
    }
}
