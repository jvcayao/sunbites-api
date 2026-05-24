<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\MenuCategory;
use App\Http\Controllers\Controller;
use App\Models\PosMenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Spatie\Activitylog\Facades\Activity;

class PosMenuItemController extends Controller
{
    public function index(): JsonResponse
    {
        $items = PosMenuItem::orderBy('sort_order')->orderBy('name')->get();

        return response()->json($items->map(fn (PosMenuItem $item) => $this->formatItem($item)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'category' => ['required', new Enum(MenuCategory::class)],
        ]);

        $item = PosMenuItem::create($validated);

        return response()->json($this->formatItem($item), 201);
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
        $item->delete();

        return response()->json(['message' => 'Menu item deleted.']);
    }

    /**
     * @return array{id: int, name: string, price: string, category: string, is_available: bool, sort_order: int}
     */
    private function formatItem(PosMenuItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'price' => $item->price,
            'category' => $item->category->value,
            'is_available' => $item->is_available,
            'sort_order' => $item->sort_order,
        ];
    }
}
