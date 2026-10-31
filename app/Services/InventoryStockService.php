<?php

namespace App\Services;

use App\Enums\InventoryLogType;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every stock write and its inventory log row.
 *
 * Callers pass the branch explicitly; the item lookups also go through `HasBranch`, so they
 * only see items in the active branch. Each method keeps its original write style: a sale
 * uses the query builder, so it writes no activity row, while restock and adjust use Eloquent,
 * so `InventoryItem` logs `inventory.updated` (hazard H5).
 *
 * Every write works from rows locked with `lockForUpdate()`, locked in ascending id order so
 * concurrent checkouts, voids and adjustments queue instead of losing updates (B-03).
 */
class InventoryStockService
{
    /**
     * Lock the inventory rows a sale needs, in id order, and refuse the sale when a locked row
     * no longer has enough stock. Must be called inside the checkout transaction.
     *
     * `withoutBranch()` is safe here: the ids come from branch-scoped menu items, and attaching
     * an ingredient enforces the same branch.
     *
     * @param  array<int, float|int>  $deductions  units to deduct, keyed by inventory item id
     * @return Collection<int, InventoryItem> the locked rows, keyed by id
     */
    public function lockForSale(array $deductions): Collection
    {
        $lockedItems = InventoryItem::withoutBranch()
            ->whereIn('id', array_keys($deductions))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($deductions as $invItemId => $unitsToDeduct) {
            $lockedItem = $lockedItems[$invItemId];
            if ((float) $lockedItem->quantity - $unitsToDeduct < 0) {
                abort(422, "{$lockedItem->name} is out of stock.");
            }
        }

        return $lockedItems;
    }

    /**
     * Deduct stock for a completed checkout. Must be called inside the checkout transaction.
     *
     * Each item is written once, from its locked quantity minus everything the cart takes, so
     * two cart lines that share an item both count (hazard H10, fixed by B-03). There is still
     * one Sale log per cart line and mapping, whose `stock_after` is the quantity left after
     * that line. Pass the rows the caller locked with lockForSale(); without them they are
     * locked here.
     *
     * @param  Collection<int, PosMenuItem>  $menuItems  keyed by id, with inventoryItems loaded
     * @param  array<int, array{pos_menu_item_id: int, quantity: int}>  $cartItems
     * @param  Collection<int, InventoryItem>|null  $lockedItems  keyed by id
     */
    public function deductForSale(Order $order, Collection $menuItems, array $cartItems, User $by, int $branchId, ?Collection $lockedItems = null): void
    {
        $lockedItems ??= $this->lockForSale($this->deductionsFor($menuItems, $cartItems));
        $remaining = $lockedItems->map(fn (InventoryItem $item): float => (float) $item->quantity)->all();

        foreach ($cartItems as $cartItem) {
            /** @var PosMenuItem $menuItem */
            $menuItem = $menuItems[$cartItem['pos_menu_item_id']];
            foreach ($menuItem->inventoryItems as $invItem) {
                $deduction = $invItem->pivot->quantity_used * $cartItem['quantity'];
                $remaining[$invItem->id] = max(0, $remaining[$invItem->id] - $deduction);

                InventoryLog::create([
                    'branch_id' => $branchId,
                    'inventory_item_id' => $invItem->id,
                    'order_id' => $order->id,
                    'adjusted_by' => $by->id,
                    'type' => InventoryLogType::Sale,
                    'quantity_change' => -$deduction,
                    'stock_after' => $remaining[$invItem->id],
                    'item_name_snapshot' => $invItem->name,
                    'reason' => 'Order #'.$order->receipt_number,
                ]);
            }
        }

        foreach ($remaining as $invItemId => $newQty) {
            InventoryItem::where('id', $invItemId)->update(['quantity' => $newQty]);
        }
    }

    /**
     * Put back the stock a voided order took, one Restock log per Sale log. Must be called
     * inside the void transaction.
     */
    public function restockForVoid(Order $order, User $by): void
    {
        $saleLogs = InventoryLog::where('order_id', $order->id)
            ->where('type', InventoryLogType::Sale->value)
            ->get();

        if ($saleLogs->isEmpty()) {
            return;
        }

        $lockedItems = InventoryItem::whereIn('id', $saleLogs->pluck('inventory_item_id')->unique()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($saleLogs as $saleLog) {
            $invItem = $lockedItems->get($saleLog->inventory_item_id);
            if (! $invItem) {
                continue;
            }

            $restockedUnits = abs((float) $saleLog->quantity_change);
            $restoredQty = (float) $invItem->quantity + $restockedUnits;
            $invItem->update(['quantity' => $restoredQty]);

            InventoryLog::create([
                'branch_id' => $order->branch_id,
                'inventory_item_id' => $invItem->id,
                'order_id' => $order->id,
                'adjusted_by' => $by->id,
                'type' => InventoryLogType::Restock,
                'quantity_change' => $restockedUnits,
                'stock_after' => $restoredQty,
                'item_name_snapshot' => $saleLog->item_name_snapshot,
                'reason' => 'Void: Order #'.$order->receipt_number,
            ]);
        }
    }

    /**
     * Apply a manual stock adjustment. The quantity never goes below 0.
     *
     * @return array{0: float, 1: float, 2: float|int} the old quantity, the signed change and the new quantity (int 0 when clamped)
     */
    public function adjust(InventoryItem $item, InventoryLogType $type, float $quantity, string $direction, string $reason, User $by, int $branchId): array
    {
        return DB::transaction(function () use ($item, $type, $quantity, $direction, $reason, $by, $branchId): array {
            // Start from the locked row's current values, and keep the caller's instance in step
            // so it can still render the adjusted item.
            $item->setRawAttributes(InventoryItem::lockForUpdate()->find($item->id)->getAttributes(), true);

            $delta = abs($quantity);
            $change = $direction === 'add' ? $delta : -$delta;

            $oldQuantity = (float) $item->quantity;
            $newQuantity = max(0, $oldQuantity + $change);

            $item->update(['quantity' => $newQuantity]);

            InventoryLog::create([
                'branch_id' => $branchId,
                'inventory_item_id' => $item->id,
                'adjusted_by' => $by->id,
                'type' => $type,
                'quantity_change' => $change,
                'stock_after' => $newQuantity,
                'item_name_snapshot' => $item->name,
                'reason' => $reason,
            ]);

            return [$oldQuantity, $change, $newQuantity];
        });
    }

    /**
     * Log the opening stock of a newly created item. An item created with no stock gets no log.
     */
    public function recordInitialStock(InventoryItem $item, User $by, int $branchId): void
    {
        if ((float) $item->quantity <= 0) {
            return;
        }

        InventoryLog::create([
            'branch_id' => $branchId,
            'inventory_item_id' => $item->id,
            'adjusted_by' => $by->id,
            'type' => InventoryLogType::Restock,
            'quantity_change' => $item->quantity,
            'stock_after' => $item->quantity,
            'item_name_snapshot' => $item->name,
            'reason' => 'Initial stock',
        ]);
    }

    /**
     * Units each inventory item loses for these cart lines.
     *
     * @param  Collection<int, PosMenuItem>  $menuItems  keyed by id, with inventoryItems loaded
     * @param  array<int, array{pos_menu_item_id: int, quantity: int}>  $cartItems
     * @return array<int, float|int> keyed by inventory item id
     */
    private function deductionsFor(Collection $menuItems, array $cartItems): array
    {
        $deductions = [];
        foreach ($cartItems as $cartItem) {
            foreach ($menuItems[$cartItem['pos_menu_item_id']]->inventoryItems as $invItem) {
                $deductions[$invItem->id] = ($deductions[$invItem->id] ?? 0) + $invItem->pivot->quantity_used * $cartItem['quantity'];
            }
        }

        return $deductions;
    }
}
