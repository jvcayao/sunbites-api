<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'branch_id',
    'school_month',
    'week_number',
    'day_of_week',
    'ulam',
    'vegetables',
    'fruit',
    'soup',
    'snacks',
])]
class WeeklyMealPlan extends Model
{
    use HasBranch;

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'day_of_week' => DayOfWeek::class,
        ];
    }
}
