<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pos_menu_item_id' => $this->pos_menu_item_id,
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'line_total' => $this->line_total,
        ];
    }
}
