<?php

namespace App\Models;

use App\Enums\MenuCategory;
use App\Models\Concerns\HasBranch;
use Database\Factories\PosMenuItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PosMenuItem extends Model
{
    /** @use HasFactory<PosMenuItemFactory> */
    use HasBranch, HasFactory, LogsActivity;

    protected $fillable = [
        'branch_id',
        'name',
        'price',
        'category',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => MenuCategory::class,
            'is_available' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'category', 'is_available', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "menu.item_{$eventName}");
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'pos_menu_item_inventory')
            ->withPivot('quantity_used');
    }
}
