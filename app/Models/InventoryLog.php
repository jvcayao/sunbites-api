<?php

namespace App\Models;

use App\Enums\InventoryLogType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Fillable([
    'branch_id',
    'inventory_item_id',
    'adjusted_by',
    'type',
    'quantity_change',
    'stock_after',
    'reason',
    'item_name_snapshot',
    'order_id',
    'created_at',
])]
class InventoryLog extends Model
{
    protected function casts(): array
    {
        return [
            'type' => InventoryLogType::class,
            'quantity_change' => 'decimal:2',
            'stock_after' => 'decimal:2',
            'order_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Logs of the branch's items that are not archived. `InventoryLog` has no branch scope of
     * its own, so the branch comes from the joined item.
     *
     * @param  Builder<InventoryLog>  $query
     */
    #[Scope]
    protected function forActiveItemsInBranch(Builder $query, int $branchId): void
    {
        $query->join('inventory_items', 'inventory_logs.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.branch_id', $branchId)
            ->where('inventory_items.is_archived', false)
            ->select('inventory_logs.*');
    }
}
