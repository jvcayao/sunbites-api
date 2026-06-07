<?php

namespace App\Models;

use App\Enums\MenuCategory;
use Database\Factories\BranchSubscriptionConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchSubscriptionConfig extends Model
{
    /** @use HasFactory<BranchSubscriptionConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'meal_daily_limit',
        'snack_daily_limit',
        'drink_daily_limit',
        'extra_daily_limit',
    ];

    protected function casts(): array
    {
        return [
            'meal_daily_limit' => 'integer',
            'snack_daily_limit' => 'integer',
            'drink_daily_limit' => 'integer',
            'extra_daily_limit' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function forBranch(int $branchId): self
    {
        return static::firstOrCreate(
            ['branch_id' => $branchId],
            [
                'meal_daily_limit' => 1,
                'snack_daily_limit' => 1,
                'drink_daily_limit' => 1,
                'extra_daily_limit' => 1,
            ]
        );
    }

    public function limitForCategory(MenuCategory $category): int
    {
        return match ($category) {
            MenuCategory::Meal => $this->meal_daily_limit,
            MenuCategory::Snack => $this->snack_daily_limit,
            MenuCategory::Drink => $this->drink_daily_limit,
            MenuCategory::Extra => $this->extra_daily_limit,
        };
    }
}
