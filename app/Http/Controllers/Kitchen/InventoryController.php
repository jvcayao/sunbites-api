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
        $items = InventoryItem::active()
            ->with('menuItems')
            ->orderBy('name')
            ->get();

        return response()->json($items->map(fn (InventoryItem $item) => $this->formatItem($item)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:50'],
            'restock_threshold' => ['required', 'numeric', 'min:0'],
            'overstock_threshold' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = InventoryItem::create($validated);

        if ((float) $item->quantity > 0) {
            InventoryLog::create([
                'branch_id' => app('active_branch')->id,
                'inventory_item_id' => $item->id,
                'adjusted_by' => $request->user()->id,
                'type' => InventoryLogType::Restock,
                'quantity_change' => $item->quantity,
                'stock_after' => $item->quantity,
                'item_name_snapshot' => $item->name,
                'reason' => 'Initial stock',
            ]);
        }

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['name' => $item->name, 'quantity' => $item->quantity])
            ->log('inventory.created');

        return response()->json($this->formatItem($item->load('menuItems')), 201);
    }

    public function update(Request $request, InventoryItem $item): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'restock_threshold' => ['required', 'numeric', 'min:0'],
            'overstock_threshold' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item->update($validated);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->log('inventory.updated');

        return response()->json($this->formatItem($item->load('menuItems')));
    }

    public function adjust(Request $request, InventoryItem $item): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', new Enum(InventoryLogType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:add,deduct'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($validated['type'] === InventoryLogType::Sale->value) {
            return response()->json(['message' => 'Sale adjustments are recorded automatically by checkout.'], 422);
        }

        $delta = abs((float) $validated['quantity']);
        $change = $validated['direction'] === 'add' ? $delta : -$delta;

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
            'item_name_snapshot' => $item->name,
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
            'item' => $this->formatItem($item->load('menuItems')),
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

    public function archive(Request $request, InventoryItem $item): JsonResponse
    {
        $item->update(['is_archived' => true]);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['name' => $item->name])
            ->log('inventory.archived');

        return response()->json(['message' => 'Item archived.']);
    }

    public function unarchive(Request $request, int $item): JsonResponse
    {
        $inventoryItem = InventoryItem::withoutBranch()
            ->where('id', $item)
            ->where('is_archived', true)
            ->firstOrFail();

        $inventoryItem->update(['is_archived' => false]);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($inventoryItem)
            ->withProperties(['name' => $inventoryItem->name])
            ->log('inventory.unarchived');

        return response()->json(['message' => 'Item unarchived.']);
    }

    public function logs(InventoryItem $item): JsonResponse
    {
        $logs = $item->logs()
            ->with('adjustedBy:id,first_name,last_name')
            ->latest('created_at')
            ->get();

        return response()->json($logs->map(fn (InventoryLog $log) => $this->formatLog($log)));
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', new Enum(InventoryLogType::class)],
            'item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
        ]);

        $branchId = app('active_branch')->id;

        $query = InventoryLog::query()
            ->join('inventory_items', 'inventory_logs.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.branch_id', $branchId)
            ->where('inventory_items.is_archived', false)
            ->with('adjustedBy:id,first_name,last_name')
            ->select('inventory_logs.*')
            ->latest('inventory_logs.created_at');

        if (! empty($validated['from'])) {
            $query->whereDate('inventory_logs.created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('inventory_logs.created_at', '<=', $validated['to']);
        }

        if (! empty($validated['type'])) {
            $query->where('inventory_logs.type', $validated['type']);
        }

        if (! empty($validated['item_id'])) {
            $query->where('inventory_logs.inventory_item_id', $validated['item_id']);
        }

        $paginated = $query->paginate(25);

        return response()->json([
            'data' => collect($paginated->items())->map(fn (InventoryLog $log) => $this->formatLog($log)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatItem(InventoryItem $item): array
    {
        $inventoryItems = $item->relationLoaded('menuItems') ? $item->menuItems : collect();

        return [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'restock_threshold' => $item->restock_threshold,
            'overstock_threshold' => $item->overstock_threshold,
            'cost_per_unit' => $item->cost_per_unit,
            'is_archived' => $item->is_archived,
            'status' => $item->status,
            'has_inventory_mapping' => $inventoryItems->isNotEmpty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLog(InventoryLog $log): array
    {
        return [
            'id' => $log->id,
            'item_name_snapshot' => $log->item_name_snapshot,
            'type' => $log->type->value,
            'type_label' => $log->type->label(),
            'quantity_change' => $log->quantity_change,
            'stock_after' => $log->stock_after,
            'reason' => $log->reason,
            'adjusted_by' => $log->adjustedBy?->full_name,
            'order_id' => $log->order_id,
            'created_at' => $log->created_at,
        ];
    }
}
