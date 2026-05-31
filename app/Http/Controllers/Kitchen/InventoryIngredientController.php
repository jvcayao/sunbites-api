<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryIngredientController extends Controller
{
    public function index(PosMenuItem $item): JsonResponse
    {
        $ingredients = $item->inventoryItems()->get();

        return response()->json($ingredients->map(fn (InventoryItem $invItem) => [
            'inventory_item_id' => $invItem->id,
            'name' => $invItem->name,
            'unit' => $invItem->unit,
            'quantity_used' => $invItem->pivot->quantity_used,
        ]));
    }

    public function attach(Request $request, PosMenuItem $item): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity_used' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;

        $inventoryItem = InventoryItem::withoutBranch()
            ->where('id', $validated['inventory_item_id'])
            ->where('branch_id', $branchId)
            ->firstOrFail();

        $item->inventoryItems()->syncWithoutDetaching([
            $inventoryItem->id => ['quantity_used' => $validated['quantity_used']],
        ]);

        $updatedIngredients = $item->inventoryItems()->get();

        return response()->json(
            $updatedIngredients->map(fn (InventoryItem $invItem) => [
                'inventory_item_id' => $invItem->id,
                'name' => $invItem->name,
                'unit' => $invItem->unit,
                'quantity_used' => $invItem->pivot->quantity_used,
            ]),
            201
        );
    }

    public function detach(PosMenuItem $item, InventoryItem $inventoryItem): JsonResponse
    {
        $item->inventoryItems()->detach($inventoryItem->id);

        return response()->json(['message' => 'Ingredient removed.']);
    }
}
