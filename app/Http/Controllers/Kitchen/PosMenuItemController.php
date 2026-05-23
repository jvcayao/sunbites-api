<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\MenuCategory;
use App\Http\Controllers\Controller;
use App\Models\PosMenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class PosMenuItemController extends Controller
{
    public function index(): Response
    {
        $items = PosMenuItem::orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('kitchen/pos/index', [
            'menuItems' => $items->map(fn (PosMenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'category' => $item->category->value,
                'is_available' => $item->is_available,
                'sort_order' => $item->sort_order,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'category' => ['required', new Enum(MenuCategory::class)],
        ]);

        PosMenuItem::create($validated);

        return back()->with('success', 'Menu item added.');
    }

    public function toggleAvailability(Request $request, PosMenuItem $item): RedirectResponse
    {
        $item->update(['is_available' => ! $item->is_available]);

        activity('menu')
            ->causedBy($request->user())
            ->performedOn($item)
            ->withProperties(['is_available' => $item->is_available])
            ->log('menu.item_toggled');

        return back()->with('success', $item->is_available ? 'Item marked as available.' : 'Item marked as unavailable.');
    }

    public function destroy(PosMenuItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', 'Menu item deleted.');
    }
}
