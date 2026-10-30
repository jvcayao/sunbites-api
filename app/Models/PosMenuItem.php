<?php

namespace App\Models;

use App\Enums\MenuCategory;
use App\Models\Concerns\HasBranch;
use Database\Factories\PosMenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'branch_id',
    'name',
    'price',
    'category',
    'is_available',
    'sort_order',
    'is_subscription_item',
])]
class PosMenuItem extends Model
{
    /** @use HasFactory<PosMenuItemFactory> */
    use HasBranch, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'category' => MenuCategory::class,
            'is_available' => 'boolean',
            'is_subscription_item' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'category', 'is_available', 'sort_order', 'is_subscription_item'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "menu.item_{$eventName}");
    }

    /** @return BelongsToMany<InventoryItem, $this> */
    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'pos_menu_item_inventory')
            ->withPivot('quantity_used');
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'pos_menu_item_id');
    }
}
