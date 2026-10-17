<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\SavePosMenuItemRequest;
use App\Http\Resources\PosMenuItemResource;
use App\Models\PosMenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\Activity;

class PosMenuItemController extends Controller
{
    public function index(): JsonResponse
    {
        $items = PosMenuItem::with('inventoryItems')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(PosMenuItemResource::collection($items));
    }

    public function store(SavePosMenuItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $item = PosMenuItem::create($validated);

        return response()->json(new PosMenuItemResource($item), 201);
    }

    public function update(SavePosMenuItemRequest $request, PosMenuItem $item): JsonResponse
    {
        $validated = $request->validated();

        $item->update($validated);

        return response()->json(new PosMenuItemResource($item));
    }

    public function toggleAvailability(Request $request, PosMenuItem $item): JsonResponse
    {
        Activity::withoutLogging(function () use ($item) {
            $item->update(['is_available' => ! $item->is_available]);
        });

        activity('menu')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['is_available' => $item->is_available])
            ->log('menu.item_toggled');

        return response()->json(['is_available' => $item->is_available]);
    }

    public function destroy(PosMenuItem $item): JsonResponse
    {
        if ($item->orderItems()->exists()) {
            return response()->json(['message' => 'This menu item has sales history and cannot be deleted. Mark it as unavailable instead.'], 422);
        }

        $item->delete();

        return response()->json(['message' => 'Menu item deleted.']);
    }
}
