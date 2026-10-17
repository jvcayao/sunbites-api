<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\AttachIngredientRequest;
use App\Http\Resources\InventoryIngredientResource;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use Illuminate\Http\JsonResponse;

class InventoryIngredientController extends Controller
{
    public function index(PosMenuItem $item): JsonResponse
    {
        $ingredients = $item->inventoryItems()->get();

        return response()->json(InventoryIngredientResource::collection($ingredients));
    }

    public function attach(AttachIngredientRequest $request, PosMenuItem $item): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;

        $inventoryItem = InventoryItem::withoutBranch()
            ->where('id', $validated['inventory_item_id'])
            ->where('branch_id', $branchId)
            ->firstOrFail();

        $item->inventoryItems()->syncWithoutDetaching([
            $inventoryItem->id => ['quantity_used' => $validated['quantity_used']],
        ]);

        $updatedIngredients = $item->inventoryItems()->get();

        return response()->json(InventoryIngredientResource::collection($updatedIngredients), 201);
    }

    public function detach(PosMenuItem $item, InventoryItem $inventoryItem): JsonResponse
    {
        $item->inventoryItems()->detach($inventoryItem->id);

        return response()->json(['message' => 'Ingredient removed.']);
    }
}
