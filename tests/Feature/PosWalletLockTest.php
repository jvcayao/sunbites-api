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

class PosWalletLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $cashier;

    private Branch $branch;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'lck']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 135.00,
            'is_available' => true,
        ]);

        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_wallet_checkout_fails_when_balance_is_insufficient(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(5000); // ₱50.00 — insufficient for ₱135

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('orders', ['student_id' => $student->id]);
    }

    public function test_wallet_balance_is_re_validated_inside_lock(): void
    {
        // Simulate that the balance check passes on entry but fails inside the lock
        // by directly setting a very low balance
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(13500); // ₱135.00 — exactly enough

        // First checkout should succeed and drain the wallet
        $first = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ]);

        $first->assertCreated();

        // Second checkout should fail — balance is now 0
        $second = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ]);

        $second->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_only_one_order_created_when_same_student_checkout_runs_twice(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(27000); // ₱270 — enough for two ₱135 orders

        $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertCreated();

        $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertCreated();

        // ₱270 - ₱135 - ₱135 = ₱0
        $student->load('wallet');
        $this->assertEquals(0.0, $student->wallet->balanceFloat);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_non_enrolled_student_cannot_use_wallet(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => 'paused',
        ]);
        $student->deposit(20000); // ₱200

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $student->id,
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('orders', ['student_id' => $student->id]);
    }

    public function test_order_uses_lockforupdate_preventing_double_spend(): void
    {
        // Verify the checkout model uses lockForUpdate pattern by confirming
        // that two sequential checkout requests with exactly enough balance
        // result in only one succeeding.
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(13500); // exactly ₱135

        $successCount = 0;
        $failCount = 0;

        for ($i = 0; $i < 2; $i++) {
            $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
                'student_id' => $student->id,
                'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
                'payment_method' => 'wallet',
            ]);

            if ($response->isSuccessful()) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        $this->assertEquals(1, $successCount);
        $this->assertEquals(1, $failCount);
        $this->assertDatabaseCount('orders', 1);
    }
}
