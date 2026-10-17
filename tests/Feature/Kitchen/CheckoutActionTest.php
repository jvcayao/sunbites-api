<?php

namespace Tests\Feature\Kitchen;

use App\Enums\MenuCategory;
use App\Jobs\WalletAlertJob;
use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Each checkout guard keeps its message, status and mechanism (Spec 16 T-3.3, design §4.3.2).
 * Debug mode is on so the mechanism is visible (hazard H3): a guard that returns a JSON
 * response gives exactly `{"message": …}`, while an `abort()` guard adds the exception details.
 */
class CheckoutActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'ca']);
        $this->cashier = $this->staff('cashier');

        $this->menuItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Chicken Rice',
            'category' => MenuCategory::Meal->value,
            'price' => 135.00,
        ]);
        $this->mapToInventory($this->menuItem, InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]));
    }

    public function test_walk_in_wallet_checkout_returns_422_with_its_message(): void
    {
        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'wallet']));

        $response->assertUnprocessable()->assertExactJson(['message' => 'Walk-in customers cannot use the student wallet.']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_walk_in_subscription_checkout_returns_422_with_its_message(): void
    {
        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'subscription']));

        $response->assertUnprocessable()->assertExactJson(['message' => 'Walk-in customers cannot use the subscription payment method.']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Validation checks the id exists in any branch; the menu load only finds the active branch (B-24).
     */
    public function test_menu_item_from_another_branch_returns_422_with_its_message(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherBranchItem = PosMenuItem::factory()->create(['branch_id' => $otherBranch->id]);
        $this->mapToInventory($otherBranchItem, InventoryItem::factory()->create(['branch_id' => $otherBranch->id, 'quantity' => 50]));

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'cash', 'amount_tendered' => 500], $otherBranchItem));

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'One or more items are not available in this branch. Clear the cart and add them again.',
        ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_logs', 0);
    }

    public function test_item_without_inventory_mapping_returns_422_with_its_message(): void
    {
        $unmapped = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'cash', 'amount_tendered' => 500], $unmapped));

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'One or more items are not configured for inventory tracking. Please contact your administrator.',
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_out_of_stock_item_returns_422_with_its_message(): void
    {
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $rice = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Rice', 'quantity' => 1]);
        $this->mapToInventory($menuItem, $rice);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'cash', 'amount_tendered' => 500], $menuItem, quantity: 2));

        $response->assertUnprocessable()->assertExactJson(['message' => 'Rice is out of stock.']);
        $this->assertSame('1.00', $rice->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cashier_applying_a_discount_returns_403_with_its_message(): void
    {
        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'payment_method' => 'cash',
            'amount_tendered' => 200,
            'discount_type' => 'fixed',
            'discount_value' => 20,
        ]));

        $response->assertForbidden()
            ->assertJsonPath('message', 'Only admin and manager roles can apply discounts.')
            ->assertJsonPath('exception', HttpException::class);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cashier_checkout_without_a_discount_succeeds(): void
    {
        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'cash', 'amount_tendered' => 200]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $response->json('order.id'), 'discount_amount' => '0.00', 'total' => '135.00']);
        $this->assertSame(0, Activity::where('description', 'pos.discount_applied')->count());
    }

    public function test_student_who_is_not_enrolled_returns_422_with_its_message(): void
    {
        $student = Student::factory()->banned()->create(['branch_id' => $this->branch->id]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ]));

        $this->assertAbortGuard($response->assertUnprocessable(), 'Student is not enrolled and cannot make purchases.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_subscription_payment_for_a_non_subscription_student_returns_422_with_its_message(): void
    {
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
        ]));

        $this->assertAbortGuard($response->assertUnprocessable(), 'This payment method is only available for subscription students.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_subscription_payment_for_an_ineligible_item_returns_422_with_its_message(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $chocolate = PosMenuItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Chocolate Bar']);
        $this->mapToInventory($chocolate, InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 50]));

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
        ], $chocolate));

        $this->assertAbortGuard($response->assertUnprocessable(), 'Item "Chocolate Bar" is not eligible for subscription payment.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_subscription_payment_beyond_the_daily_limit_returns_422_with_its_message(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        BranchSubscriptionConfig::factory()->create(['branch_id' => $this->branch->id, 'meal_daily_limit' => 1]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'subscription',
        ], quantity: 2));

        $this->assertAbortGuard($response->assertUnprocessable(), 'Daily meal limit of 1 reached for this student.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_wallet_payment_without_enough_balance_returns_422_with_its_message(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(5000);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'wallet',
        ]));

        $this->assertAbortGuard($response->assertUnprocessable(), 'Insufficient wallet balance.');
        $this->assertSame(50.0, $student->fresh()->wallet->balanceFloatNum);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_wallet_payment_beyond_the_credit_limit_returns_422_with_its_message(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 300]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'wallet',
            'use_credit' => true,
        ]));

        $this->assertAbortGuard($response->assertUnprocessable(), 'Credit limit exceeded.');
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_earns_a_loyalty_point_when_total_spent_crosses_the_threshold(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'total_spent' => 900, 'points' => 0]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $response->json('order.id'), 'points_earned' => 1]);
        $student->refresh();
        $this->assertSame('1035.00', $student->total_spent);
        $this->assertSame(1, $student->points);
    }

    public function test_wallet_checkout_dispatches_the_wallet_alert_with_the_new_balance(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student->deposit(20000);
        Queue::fake([WalletAlertJob::class]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'wallet',
        ]));

        $response->assertCreated();
        Queue::assertPushed(WalletAlertJob::class, ['studentId' => $student->id, 'currentBalance' => 65.0]);
    }

    public function test_cash_checkout_for_a_student_does_not_dispatch_the_wallet_alert(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        Queue::fake([WalletAlertJob::class]);

        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $student->id,
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ]));

        $response->assertCreated();
        Queue::assertNotPushed(WalletAlertJob::class);
    }

    public function test_checkout_logs_the_order_created_activity(): void
    {
        $response = $this->asStaff($this->cashier)->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'cash', 'amount_tendered' => 200]));

        $response->assertCreated();
        $activity = Activity::where('log_name', 'pos')->where('description', 'pos.order_created')->sole();
        $this->assertTrue($activity->causer->is($this->cashier));
        $this->assertSame($response->json('order.id'), $activity->subject_id);
        $this->assertActivityProperties([
            'receipt_number' => $response->json('order.receipt_number'),
            'total' => '135.00',
            'payment_method' => 'cash',
            'student_id' => 'walk-in',
            'cashier_id' => $this->cashier->id,
            'is_credit' => false,
        ], $activity);
    }

    public function test_discounted_checkout_logs_the_discount_applied_activity(): void
    {
        $manager = $this->staff('manager');

        $response = $this->asStaff($manager)->postJson('/api/v1/pos/checkout', $this->cart([
            'payment_method' => 'cash',
            'amount_tendered' => 200,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_reason' => 'Staff meal',
        ]));

        $response->assertCreated();
        $activity = Activity::where('log_name', 'pos')->where('description', 'pos.discount_applied')->sole();
        $this->assertTrue($activity->causer->is($manager));
        $this->assertActivityProperties([
            'discount_type' => 'percent',
            'amount' => 13.5,
            'reason' => 'Staff meal',
            'receipt_number' => $response->json('order.receipt_number'),
        ], $activity);
    }

    /**
     * The JSON column may reorder keys (MySQL does), so the comparison ignores key order.
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertActivityProperties(array $expected, Activity $activity): void
    {
        $this->assertSame(collect($expected)->sortKeys()->all(), $activity->properties->sortKeys()->all());
    }

    private function assertAbortGuard(TestResponse $response, string $message): void
    {
        $response->assertJsonPath('message', $message)->assertJsonPath('exception', HttpException::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cart(array $overrides, ?PosMenuItem $menuItem = null, int $quantity = 1): array
    {
        return [
            'items' => [['pos_menu_item_id' => ($menuItem ?? $this->menuItem)->id, 'quantity' => $quantity]],
            ...$overrides,
        ];
    }

    private function mapToInventory(PosMenuItem $menuItem, InventoryItem $inventoryItem): void
    {
        $menuItem->inventoryItems()->attach($inventoryItem->id, ['quantity_used' => 1]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function asStaff(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
