<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * `has_inventory_mapping` is false unless the caller loaded `menuItems`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $menuItems = $this->relationLoaded('menuItems') ? $this->menuItems : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'restock_threshold' => $this->restock_threshold,
            'overstock_threshold' => $this->overstock_threshold,
            'cost_per_unit' => $this->cost_per_unit,
            'is_archived' => $this->is_archived,
            'status' => $this->status,
            'has_inventory_mapping' => $menuItems->isNotEmpty(),
        ];
    }
}
