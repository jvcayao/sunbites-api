<?php

namespace App\Models;

use App\Enums\InventoryLogType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
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
    ];

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

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
