<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\InventoryLogType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(): Response
    {
        $items = InventoryItem::orderBy('name')->get();

        return Inertia::render('kitchen/pos/index', [
            'inventoryItems' => $items->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'restock_threshold' => $item->restock_threshold,
                'status' => $item->status,
            ]),
        ]);
    }

    public function adjust(Request $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', new Enum(InventoryLogType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:add,deduct'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $change = $validated['direction'] === 'add'
            ? abs((float) $validated['quantity'])
            : -abs((float) $validated['quantity']);

        $oldQuantity = (float) $item->quantity;
        $newQuantity = max(0, $oldQuantity + $change);

        $item->update(['quantity' => $newQuantity]);

        InventoryLog::create([
            'branch_id' => app('active_branch')->id,
            'inventory_item_id' => $item->id,
            'adjusted_by' => $request->user()->id,
            'type' => $validated['type'],
            'quantity_change' => $change,
            'stock_after' => $newQuantity,
            'reason' => $validated['reason'],
        ]);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties([
                'item' => $item->name,
                'old_qty' => $oldQuantity,
                'change' => $change,
                'new_qty' => $newQuantity,
                'reason' => $validated['reason'],
            ])
            ->log('inventory.adjusted');

        return back()->with('success', 'Stock adjusted.');
    }
}
