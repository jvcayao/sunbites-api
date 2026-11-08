<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inventory item seen through a menu item's `inventoryItems` relation, so `pivot` is set.
 */
class InventoryIngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'inventory_item_id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity_used' => $this->pivot->quantity_used,
        ];
    }
}
