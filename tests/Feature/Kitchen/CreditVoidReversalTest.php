<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditVoidReversalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'cv']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 100.00,
        ]);

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

    private function creditOrder(Student $student, float $total, float $creditAmount): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'student_id' => $student->id,
            'payment_method' => PaymentMethod::Wallet->value,
            'is_credit' => true,
            'credit_amount' => $creditAmount,
            'total' => $total,
            'status' => OrderStatus::Completed->value,
        ]);
    }

    public function test_checkout_still_charges_credit_through_the_ledger_service(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(4000);

        $response = $this->asAdmin()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => PaymentMethod::Wallet->value,
            'use_credit' => true,
        ]);

        $response->assertCreated();

        $student->refresh();
        $this->assertEquals(60.0, (float) $student->credit_balance);

        $entry = CreditTransaction::where('student_id', $student->id)->sole();
        $this->assertSame(CreditTransactionType::Charged, $entry->type);
        $this->assertSame('60.00', $entry->amount);
        $this->assertSame($this->branch->id, $entry->branch_id);
        $this->assertSame($this->admin->id, $entry->performed_by);
        $this->assertNull($entry->order_id, 'Charge rows carry a null order_id; the order does not exist yet at charge time.');
        $this->assertNull($entry->payment_method);
    }

    public function test_voiding_a_credit_order_reverses_the_full_amount(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 85.00,
        ]);

        $order = $this->creditOrder($student, 85.00, 85.00);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Credit order voided.',
        ])->assertOk();

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);

        $entry = CreditTransaction::where('order_id', $order->id)->sole();
        $this->assertSame(CreditTransactionType::Voided, $entry->type);
        $this->assertSame('85.00', $entry->amount);
        $this->assertSame($this->branch->id, $entry->branch_id);
        $this->assertSame($this->admin->id, $entry->performed_by);
    }

    public function test_voiding_a_wallet_order_that_used_credit_refunds_only_the_wallet_portion(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 60.00,
        ]);

        $order = $this->creditOrder($student, 100.00, 60.00);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Refund check.',
        ])->assertOk();

        $student->refresh()->load('wallet');

        $this->assertEquals(40.0, (float) $student->wallet->balanceFloat, 'Only total − credit_amount may be refunded.');
        $this->assertEquals(0.0, (float) $student->credit_balance);
    }

    public function test_voiding_reverses_only_the_outstanding_remainder_when_credit_was_already_settled(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 25.00,
        ]);

        $order = $this->creditOrder($student, 25.00, 25.00);

        app(CreditLedgerService::class)->settleWithPayment(
            $student,
            25.00,
            CreditSettlementMethod::Cash,
            null,
            'Paid at counter before the void.',
            $this->admin,
        );

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Voided after settlement.',
        ])->assertOk();

        $voidEntry = CreditTransaction::where('order_id', $order->id)
            ->where('type', CreditTransactionType::Voided->value)
            ->sole();

        $this->assertSame('0.00', $voidEntry->amount, 'Nothing remained outstanding, so nothing may be reversed.');
        $this->assertStringContainsString('25.00 of credit was already settled', $voidEntry->notes);
        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance, 'The balance must never be driven negative.');
    }

    public function test_voiding_reverses_a_partial_remainder_when_credit_was_partly_settled(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 100.00,
        ]);

        $order = $this->creditOrder($student, 100.00, 100.00);

        app(CreditLedgerService::class)->settleWithPayment(
            $student,
            40.00,
            CreditSettlementMethod::Cash,
            null,
            null,
            $this->admin,
        );

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Voided after partial settlement.',
        ])->assertOk();

        $voidEntry = CreditTransaction::where('order_id', $order->id)
            ->where('type', CreditTransactionType::Voided->value)
            ->sole();

        $this->assertSame('60.00', $voidEntry->amount);
        $this->assertStringContainsString('40.00 of credit was already settled', $voidEntry->notes);
        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
    }

    public function test_voiding_a_non_credit_order_writes_no_credit_entry(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(10000);

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'student_id' => $student->id,
            'payment_method' => PaymentMethod::Wallet->value,
            'is_credit' => false,
            'credit_amount' => 0,
            'total' => 50.00,
            'status' => OrderStatus::Completed->value,
        ]);

        $this->asAdmin()->postJson("/api/v1/pos/transactions/{$order->id}/void", [
            'void_reason' => 'Plain wallet void.',
        ])->assertOk();

        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
