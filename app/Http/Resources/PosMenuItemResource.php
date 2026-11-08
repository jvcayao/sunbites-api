<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class PosMenuItemResource extends JsonResource
{
    /**
     * The mapping fields are false/null unless the caller loaded `inventoryItems`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inventoryItems = $this->relationLoaded('inventoryItems') ? $this->inventoryItems : collect();
        $hasMappings = $inventoryItems->isNotEmpty();

        $inventoryStatus = $this->resolveInventoryStatus($inventoryItems, $hasMappings);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'category' => $this->category->value,
            'is_available' => $this->is_available,
            'sort_order' => $this->sort_order,
            'has_inventory_mapping' => $hasMappings,
            'inventory_status' => $inventoryStatus,
            'is_subscription_item' => $this->is_subscription_item,
        ];
    }

    /**
     * Returns the worst inventory status among linked items: OUT > LOW > OVER > OK.
     * Returns null when there is no mapping.
     *
     * @param  Collection<int, InventoryItem>  $inventoryItems
     */
    private function resolveInventoryStatus(Collection $inventoryItems, bool $hasMappings): ?string
    {
        if (! $hasMappings) {
            return null;
        }

        $statusPriority = ['OUT' => 4, 'LOW' => 3, 'OVER' => 2, 'OK' => 1];
        $worst = 'OK';

        foreach ($inventoryItems as $invItem) {
            $status = $invItem->status;
            if (($statusPriority[$status] ?? 0) > ($statusPriority[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
