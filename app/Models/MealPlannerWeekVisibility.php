<?php

namespace App\Models;

use App\Enums\SchoolMonth;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('meal_planner_week_visibility')]
#[Fillable([
    'branch_id',
    'school_month',
    'week_number',
    'visible_to_parents',
])]
class MealPlannerWeekVisibility extends Model
{
    use HasBranch;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'visible_to_parents' => 'boolean',
        ];
    }
}
