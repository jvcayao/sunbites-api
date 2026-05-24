<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\InventoryLogType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $items = InventoryItem::orderBy('name')->get();

        return response()->json($items->map(fn (InventoryItem $item) => $this->formatItem($item)));
    }

    public function store(Request $request): JsonResponse
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

        return response()->json($this->formatItem($item), 201);
    }

    public function update(Request $request, InventoryItem $item): JsonResponse
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

        return response()->json($this->formatItem($item));
    }

    public function adjust(Request $request, InventoryItem $item): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', new Enum(InventoryLogType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:add,deduct'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $delta = abs((float) $validated['quantity']);
        $change = $validated['direction'] === 'add' ? $delta : -$delta;

        $oldQuantity = (float) $item->quantity;
        $newQuantity = max(0, $oldQuantity + $change);

        $item->update(['quantity' => $newQuantity]);

        if (! app()->bound('active_branch')) {
            return response()->json(['message' => 'No active branch selected. Please set a branch first.'], 422);
        }

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

        return response()->json([
            'message' => 'Stock adjusted.',
            'item' => $this->formatItem($item),
        ]);
    }

    public function destroy(Request $request, InventoryItem $item): JsonResponse
    {
        if ($item->logs()->exists()) {
            return response()->json(['message' => 'This item has adjustment history and cannot be deleted.'], 422);
        }

        $item->delete();

        activity('inventory')
            ->causedBy($request->user())
            ->withProperties(['name' => $item->name])
            ->log('inventory.deleted');

        return response()->json(['message' => 'Inventory item deleted.']);
    }

    public function logs(InventoryItem $item): JsonResponse
    {
        $logs = $item->logs()
            ->with('adjustedBy:id,first_name,last_name')
            ->latest('created_at')
            ->get();

        return response()->json($logs->map(fn (InventoryLog $log) => [
            'id' => $log->id,
            'type' => $log->type->value,
            'type_label' => $log->type->label(),
            'quantity_change' => $log->quantity_change,
            'stock_after' => $log->stock_after,
            'reason' => $log->reason,
            'adjusted_by' => $log->adjustedBy?->full_name,
            'created_at' => $log->created_at,
        ]));
    }

    /**
     * @return array{id: int, name: string, quantity: string, unit: string, restock_threshold: string, status: string}
     */
    private function formatItem(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'restock_threshold' => $item->restock_threshold,
            'status' => $item->status,
        ];
    }
}
