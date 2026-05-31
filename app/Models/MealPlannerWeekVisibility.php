<?php

namespace App\Models;

use App\Enums\SchoolMonth;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;

class MealPlannerWeekVisibility extends Model
{
    use HasBranch;

    public const CREATED_AT = null;

    protected $table = 'meal_planner_week_visibility';

    protected $fillable = [
        'branch_id',
        'school_month',
        'week_number',
        'visible_to_parents',
    ];

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'visible_to_parents' => 'boolean',
        ];
    }
}
