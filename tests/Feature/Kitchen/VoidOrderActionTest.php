<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditTransactionType;
use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Voiding an order keeps every side effect and the already-voided guard's precedence
 * (Spec 16 T-3.4, design §4.3.3 and §4.2.2).
 */
class VoidOrderActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'vo']);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_voiding_refunds_the_wallet_without_the_credit_portion(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 40]);
        $order = $this->creditOrder(['student_id' => $student->id, 'total' => 100, 'credit_amount' => 40]);

        $response = $this->voidAsManager($order, 'Wrong student scanned.');

        $response->assertOk();
        $refund = $student->fresh()->walletTransactions()->where('type', 'deposit')->sole();
        $this->assertSame(6000, (int) $refund->amount);
        $this->assertSame(collect([
            'source' => 'void_refund',
            'order_id' => $order->id,
            'receipt_number' => $order->receipt_number,
            'voided_by' => $this->manager->id,
        ])->sortKeys()->all(), collect($refund->meta)->sortKeys()->all());
    }

    public function test_voiding_a_credit_order_reverses_the_credit_through_the_ledger(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 85]);
        $order = $this->creditOrder(['student_id' => $student->id, 'total' => 85, 'credit_amount' => 85]);

        $response = $this->voidAsManager($order, 'Credit order voided.');

        $response->assertOk();
        $this->assertSame('0.00', $student->fresh()->credit_balance);
        $entry = CreditTransaction::where('order_id', $order->id)->sole();
        $this->assertSame(CreditTransactionType::Voided, $entry->type);
        $this->assertSame('85.00', $entry->amount);
    }

    public function test_voiding_restocks_each_item_from_its_sale_log(): void
    {
        $bread = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bread Roll', 'quantity' => 44]);
        $order = $this->order();
        InventoryLog::create([
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $bread->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->manager->id,
            'type' => InventoryLogType::Sale,
            'quantity_change' => -4,
            'stock_after' => 44,
            'item_name_snapshot' => 'Bread Roll',
            'reason' => 'Order #'.$order->receipt_number,
        ]);

        $response = $this->voidAsManager($order, 'Wrong order.');

        $response->assertOk();
        $this->assertSame('48.00', $bread->fresh()->quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $bread->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->manager->id,
            'type' => InventoryLogType::Restock->value,
            'quantity_change' => '4.00',
            'stock_after' => '48.00',
            'item_name_snapshot' => 'Bread Roll',
            'reason' => 'Void: Order #'.$order->receipt_number,
        ]);
    }

    public function test_voiding_removes_the_loyalty_points_the_order_earned(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'total_spent' => 1085, 'points' => 1]);
        $order = $this->order(['student_id' => $student->id, 'total' => 135]);

        $response = $this->voidAsManager($order, 'Refund request.');

        $response->assertOk();
        $student->refresh();
        $this->assertSame('950.00', $student->total_spent);
        $this->assertSame(0, $student->points);
    }

    public function test_voiding_logs_the_order_voided_activity(): void
    {
        $order = $this->order(['total' => 135]);

        $response = $this->voidAsManager($order, 'Customer cancelled.');

        $response->assertOk();
        $activity = Activity::where('log_name', 'pos')->where('description', 'pos.order_voided')->sole();
        $this->assertTrue($activity->causer->is($this->manager));
        $this->assertTrue($activity->subject->is($order));
        $this->assertSame(collect([
            'receipt_number' => $order->receipt_number,
            'amount' => '135.00',
            'void_reason' => 'Customer cancelled.',
            'voided_by' => $this->manager->id,
            'payment_method' => 'cash',
        ])->sortKeys()->all(), $activity->properties->sortKeys()->all());
    }

    /**
     * The already-voided check runs before validation, so it wins over a missing reason.
     * Debug mode is on: the exact body also proves the guard is a JSON response, not `abort()` (H3).
     */
    public function test_voiding_an_already_voided_order_without_a_reason_returns_422_already_voided(): void
    {
        config(['app.debug' => true]);
        $order = $this->order(['status' => OrderStatus::Voided->value, 'void_reason' => 'First void.']);

        $response = $this->asManager()->postJson("/api/v1/pos/transactions/{$order->id}/void");

        $response->assertUnprocessable()->assertExactJson(['message' => 'Order is already voided.']);
        $this->assertSame('First void.', $order->fresh()->void_reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create(['branch_id' => $this->branch->id, 'cashier_id' => $this->manager->id, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function creditOrder(array $attributes): Order
    {
        return Order::factory()->credit()->create(['branch_id' => $this->branch->id, 'cashier_id' => $this->manager->id, ...$attributes]);
    }

    private function voidAsManager(Order $order, string $reason): TestResponse
    {
        return $this->asManager()->postJson("/api/v1/pos/transactions/{$order->id}/void", ['void_reason' => $reason]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
