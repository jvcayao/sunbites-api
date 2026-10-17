<?php

namespace Tests\Feature\Kitchen;

use App\Enums\InventoryLogType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Spec 16 T-3.1, design §4.3.1. The active branch is bound as `SetActiveBranch` binds it.
 */
class InventoryStockServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staff;

    private InventoryStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        app()->instance('active_branch', $this->branch);
        $this->staff = User::factory()->create();
        $this->stock = app(InventoryStockService::class);
    }

    /**
     * Reproduces hazard H10: both lines compute from the stale start quantity, so the second
     * write overwrites the first. B-03 fixes this and deletes this test.
     */
    public function test_sale_with_two_lines_sharing_an_item_ends_one_unit_down(): void
    {
        $milk = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 10]);
        $latte = $this->menuItemUsing($milk, 1);
        $cocoa = $this->menuItemUsing($milk, 1);
        $order = $this->order();

        $this->stock->deductForSale($order, $this->loadMenuItems($latte, $cocoa), [
            ['pos_menu_item_id' => $latte->id, 'quantity' => 1],
            ['pos_menu_item_id' => $cocoa->id, 'quantity' => 1],
        ], $this->staff, $this->branch->id);

        $this->assertSame('9.00', $milk->fresh()->quantity);
        $this->assertSame(['9.00', '9.00'], InventoryLog::where('order_id', $order->id)->orderBy('id')->pluck('stock_after')->all());
    }

    public function test_sale_writes_a_sale_log_and_no_activity_row(): void
    {
        $juice = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Juice', 'quantity' => 48]);
        $combo = $this->menuItemUsing($juice, 2);
        $order = $this->order();

        $this->stock->deductForSale($order, $this->loadMenuItems($combo), [
            ['pos_menu_item_id' => $combo->id, 'quantity' => 3],
        ], $this->staff, $this->branch->id);

        $this->assertSame('42.00', $juice->fresh()->quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $juice->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->staff->id,
            'type' => InventoryLogType::Sale->value,
            'quantity_change' => '-6.00',
            'stock_after' => '42.00',
            'item_name_snapshot' => 'Juice',
            'reason' => 'Order #'.$order->receipt_number,
        ]);
        $this->assertSame(0, $this->updatedActivityCount($juice));
    }

    public function test_restock_for_void_writes_a_restock_log_and_an_inventory_updated_activity(): void
    {
        $bread = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bread Roll', 'quantity' => 44]);
        $order = $this->order();
        InventoryLog::create([
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $bread->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->staff->id,
            'type' => InventoryLogType::Sale,
            'quantity_change' => -4,
            'stock_after' => 44,
            'item_name_snapshot' => 'Bread Roll',
            'reason' => 'Order #'.$order->receipt_number,
        ]);

        $this->stock->restockForVoid($order, $this->staff);

        $this->assertSame('48.00', $bread->fresh()->quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $bread->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->staff->id,
            'type' => InventoryLogType::Restock->value,
            'quantity_change' => '4.00',
            'stock_after' => '48.00',
            'item_name_snapshot' => 'Bread Roll',
            'reason' => 'Void: Order #'.$order->receipt_number,
        ]);
        $this->assertSame(1, $this->updatedActivityCount($bread));
    }

    public function test_adjust_adds_stock_and_writes_a_log(): void
    {
        $flour = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Flour', 'quantity' => 10]);

        $result = $this->stock->adjust($flour, InventoryLogType::Restock, 5, 'add', 'Delivery', $this->staff, $this->branch->id);

        $this->assertSame([10.0, 5.0, 15.0], $result);
        $this->assertSame('15.00', $flour->fresh()->quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $flour->id,
            'order_id' => null,
            'adjusted_by' => $this->staff->id,
            'type' => InventoryLogType::Restock->value,
            'quantity_change' => '5.00',
            'stock_after' => '15.00',
            'item_name_snapshot' => 'Flour',
            'reason' => 'Delivery',
        ]);
        $this->assertSame(1, $this->updatedActivityCount($flour));
    }

    public function test_adjust_deducting_more_than_the_stock_clamps_at_zero(): void
    {
        $eggs = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Eggs', 'quantity' => 3]);

        $result = $this->stock->adjust($eggs, InventoryLogType::Waste, 5, 'deduct', 'Cracked', $this->staff, $this->branch->id);

        $this->assertSame([3.0, -5.0, 0], $result);
        $this->assertSame('0.00', $eggs->fresh()->quantity);
        $this->assertDatabaseHas('inventory_logs', [
            'inventory_item_id' => $eggs->id,
            'type' => InventoryLogType::Waste->value,
            'quantity_change' => '-5.00',
            'stock_after' => '0.00',
            'reason' => 'Cracked',
        ]);
    }

    public function test_initial_stock_writes_an_initial_stock_log(): void
    {
        $sugar = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Sugar', 'quantity' => 12]);

        $this->stock->recordInitialStock($sugar, $this->staff, $this->branch->id);

        $this->assertDatabaseHas('inventory_logs', [
            'branch_id' => $this->branch->id,
            'inventory_item_id' => $sugar->id,
            'order_id' => null,
            'adjusted_by' => $this->staff->id,
            'type' => InventoryLogType::Restock->value,
            'quantity_change' => '12.00',
            'stock_after' => '12.00',
            'item_name_snapshot' => 'Sugar',
            'reason' => 'Initial stock',
        ]);
    }

    public function test_initial_stock_of_zero_writes_no_log(): void
    {
        $salt = InventoryItem::factory()->outOfStock()->create(['branch_id' => $this->branch->id]);

        $this->stock->recordInitialStock($salt, $this->staff, $this->branch->id);

        $this->assertDatabaseMissing('inventory_logs', ['inventory_item_id' => $salt->id]);
    }

    private function menuItemUsing(InventoryItem $inventoryItem, int $quantityUsed): PosMenuItem
    {
        $menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $menuItem->inventoryItems()->attach($inventoryItem->id, ['quantity_used' => $quantityUsed]);

        return $menuItem;
    }

    /**
     * Loaded as checkout loads them: keyed by id, with inventoryItems.
     *
     * @return Collection<int, PosMenuItem>
     */
    private function loadMenuItems(PosMenuItem ...$menuItems): Collection
    {
        return PosMenuItem::with('inventoryItems')->findMany(collect($menuItems)->pluck('id'))->keyBy('id');
    }

    private function order(): Order
    {
        return Order::factory()->create(['branch_id' => $this->branch->id, 'cashier_id' => $this->staff->id]);
    }

    private function updatedActivityCount(InventoryItem $item): int
    {
        return Activity::where('description', 'inventory.updated')->whereMorphedTo('subject', $item)->count();
    }
}
