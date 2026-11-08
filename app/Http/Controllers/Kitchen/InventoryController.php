<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\InventoryLogType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\AdjustInventoryRequest;
use App\Http\Requests\Kitchen\ListInventoryHistoryRequest;
use App\Http\Requests\Kitchen\StoreInventoryItemRequest;
use App\Http\Requests\Kitchen\UpdateInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryLogResource;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Services\InventoryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryStockService $stock) {}

    public function index(): JsonResponse
    {
        $items = InventoryItem::active()
            ->with('menuItems')
            ->orderBy('name')
            ->get();

        return response()->json(InventoryItemResource::collection($items));
    }

    /**
     * The management list: every item of the branch, archived ones included.
     */
    public function indexForManagement(): JsonResponse
    {
        $items = InventoryItem::query()
            ->with('menuItems')
            ->orderBy('name')
            ->get();

        return response()->json(InventoryItemResource::collection($items));
    }

    public function store(StoreInventoryItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $item = InventoryItem::create($validated);

        $this->stock->recordInitialStock($item, $request->user(), app('active_branch')->id);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['name' => $item->name, 'quantity' => $item->quantity])
            ->log('inventory.created');

        return response()->json(new InventoryItemResource($item->load('menuItems')), 201);
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $item): JsonResponse
    {
        $validated = $request->validated();

        $item->update($validated);

        activity('inventory')
            ->causedBy($request->user())
            ->performedOn($item)
            ->log('inventory.updated');

        return response()->json(new InventoryItemResource($item->load('menuItems')));
    }

    public function adjust(AdjustInventoryRequest $request, InventoryItem $item): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['type'] === InventoryLogType::Sale->value) {
            return response()->json(['message' => 'Sale adjustments are recorded automatically by checkout.'], 422);
        }

        [$oldQuantity, $change, $newQuantity] = $this->stock->adjust(
            $item,
            InventoryLogType::from($validated['type']),
            (float) $validated['quantity'],
            $validated['direction'],
            $validated['reason'],
            $request->user(),
            app('active_branch')->id,
        );

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
            'item' => new InventoryItemResource($item->load('menuItems')),
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
        $inventoryItem = InventoryItem::query()
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

        return response()->json(InventoryLogResource::collection($logs));
    }

    public function history(ListInventoryHistoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;

        $query = InventoryLog::forActiveItemsInBranch($branchId)
            ->with('adjustedBy:id,first_name,last_name')
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
            'data' => InventoryLogResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }
}
