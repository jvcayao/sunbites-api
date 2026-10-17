<?php

namespace App\Services;

use App\Enums\InventoryLogType;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every stock write and its inventory log row.
 *
 * Callers pass the branch explicitly; the item lookups also go through `HasBranch`, so they
 * only see items in the active branch. Each method keeps its original write style: a sale
 * uses the query builder, so it writes no activity row, while restock and adjust use Eloquent,
 * so `InventoryItem` logs `inventory.updated` (hazard H5).
 */
class InventoryStockService
{
    /**
     * Deduct stock for a completed checkout. Must be called inside the checkout transaction.
     *
     * The new quantity is computed from the pre-loaded item, so two cart lines that share an
     * inventory item write the same stale value and the second write wins (hazard H10).
     *
     * @param  Collection<int, PosMenuItem>  $menuItems  keyed by id, with inventoryItems loaded
     * @param  array<int, array{pos_menu_item_id: int, quantity: int}>  $cartItems
     */
    public function deductForSale(Order $order, Collection $menuItems, array $cartItems, User $by, int $branchId): void
    {
        foreach ($cartItems as $cartItem) {
            /** @var PosMenuItem $menuItem */
            $menuItem = $menuItems[$cartItem['pos_menu_item_id']];
            foreach ($menuItem->inventoryItems as $invItem) {
                $deduction = $invItem->pivot->quantity_used * $cartItem['quantity'];
                $newQty = max(0, (float) $invItem->quantity - $deduction);

                InventoryItem::where('id', $invItem->id)->update(['quantity' => $newQty]);

                InventoryLog::create([
                    'branch_id' => $branchId,
                    'inventory_item_id' => $invItem->id,
                    'order_id' => $order->id,
                    'adjusted_by' => $by->id,
                    'type' => InventoryLogType::Sale,
                    'quantity_change' => -$deduction,
                    'stock_after' => $newQty,
                    'item_name_snapshot' => $invItem->name,
                    'reason' => 'Order #'.$order->receipt_number,
                ]);
            }
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

        foreach ($saleLogs as $saleLog) {
            $invItem = InventoryItem::lockForUpdate()->find($saleLog->inventory_item_id);
            if ($invItem) {
                $restoredQty = (float) $invItem->quantity + abs((float) $saleLog->quantity_change);
                $invItem->update(['quantity' => $restoredQty]);

                InventoryLog::create([
                    'branch_id' => $order->branch_id,
                    'inventory_item_id' => $invItem->id,
                    'order_id' => $order->id,
                    'adjusted_by' => $by->id,
                    'type' => InventoryLogType::Restock,
                    'quantity_change' => abs((float) $saleLog->quantity_change),
                    'stock_after' => $restoredQty,
                    'item_name_snapshot' => $saleLog->item_name_snapshot,
                    'reason' => 'Void: Order #'.$order->receipt_number,
                ]);
            }
        }
    }

    /**
     * Apply a manual stock adjustment. The quantity never goes below 0.
     *
     * @return array{0: float, 1: float, 2: float|int} the old quantity, the signed change and the new quantity (int 0 when clamped)
     */
    public function adjust(InventoryItem $item, InventoryLogType $type, float $quantity, string $direction, string $reason, User $by, int $branchId): array
    {
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
    }

    /**
     * Log the opening stock of a newly created item. An item created with no stock gets no log.
     */
    public function recordInitialStock(InventoryItem $item, User $by, int $branchId): void
    {
        if ((float) $item->quantity > 0) {
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
    }
}
