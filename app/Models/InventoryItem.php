<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasBranch, HasFactory, LogsActivity;

    protected $fillable = [
        'branch_id',
        'name',
        'quantity',
        'unit',
        'restock_threshold',
        'overstock_threshold',
        'cost_per_unit',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'restock_threshold' => 'decimal:2',
            'overstock_threshold' => 'decimal:2',
            'cost_per_unit' => 'decimal:2',
            'is_archived' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'unit', 'restock_threshold', 'overstock_threshold', 'cost_per_unit', 'is_archived'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "inventory.{$eventName}");
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(PosMenuItem::class, 'pos_menu_item_inventory')
            ->withPivot('quantity_used');
    }

    /** @param  Builder<InventoryItem>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    /** @param  Builder<InventoryItem>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->where('is_archived', true);
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->quantity == 0 => 'OUT',
                $this->quantity <= $this->restock_threshold => 'LOW',
                $this->overstock_threshold !== null && $this->quantity > $this->overstock_threshold => 'OVER',
                default => 'OK',
            }
        );
    }
}
