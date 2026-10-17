<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_name_snapshot' => $this->item_name_snapshot,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity_change' => $this->quantity_change,
            'stock_after' => $this->stock_after,
            'reason' => $this->reason,
            'adjusted_by' => $this->adjustedBy?->full_name,
            'order_id' => $this->order_id,
            'created_at' => $this->created_at,
        ];
    }
}
