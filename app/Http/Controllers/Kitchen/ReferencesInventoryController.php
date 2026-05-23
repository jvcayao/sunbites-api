<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReferencesInventoryController extends Controller
{
    public function index(): Response
    {
        $items = InventoryItem::orderBy('name')->get();

        return Inertia::render('kitchen/references/inventory/index', [
            'items' => $items->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'restock_threshold' => $item->restock_threshold,
                'status' => $item->status,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:50'],
            'restock_threshold' => ['required', 'numeric', 'min:0'],
        ]);

        $item = InventoryItem::create($validated);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['name' => $item->name, 'quantity' => $item->quantity])
            ->log('inventory.created');

        return back()->with('success', 'Inventory item added.');
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'restock_threshold' => ['required', 'numeric', 'min:0'],
        ]);

        $item->update($validated);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->log('inventory.updated');

        return back()->with('success', 'Inventory item updated.');
    }

    public function destroy(Request $request, InventoryItem $item): RedirectResponse
    {
        if ($item->logs()->exists()) {
            return back()->withErrors(['item' => 'This item has adjustment history and cannot be deleted.']);
        }

        $item->delete();

        activity('inventory')
            ->causedBy($request->user())
            ->withProperties(['name' => $item->name])
            ->log('inventory.deleted');

        return back()->with('success', 'Inventory item deleted.');
    }

    public function logs(InventoryItem $item): Response
    {
        $logs = $item->logs()
            ->with('adjustedBy:id,first_name,last_name')
            ->latest('created_at')
            ->get();

        return Inertia::render('kitchen/references/inventory/index', [
            'itemLogs' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->type->value,
                'type_label' => $log->type->label(),
                'quantity_change' => $log->quantity_change,
                'stock_after' => $log->stock_after,
                'reason' => $log->reason,
                'adjusted_by' => $log->adjustedBy?->full_name,
                'created_at' => $log->created_at,
            ]),
        ]);
    }
}
