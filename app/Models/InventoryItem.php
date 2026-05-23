<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'restock_threshold' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'unit', 'restock_threshold'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "inventory.{$eventName}");
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->quantity == 0 => 'OUT',
                $this->quantity <= $this->restock_threshold => 'LOW',
                default => 'OK',
            }
        );
    }
}
