<?php

namespace Database\Seeders;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\MealPlannerWeekVisibility;
use Illuminate\Database\Seeder;

class MealPlannerWeekVisibilitySeeder extends Seeder
{
    public function run(): void
    {
        Branch::all()->each(function (Branch $branch): void {
            foreach (SchoolMonth::cases() as $month) {
                foreach (range(1, 4) as $week) {
                    MealPlannerWeekVisibility::withoutBranch()->updateOrCreate(
                        ['branch_id' => $branch->id, 'school_month' => $month->value, 'week_number' => $week],
                        ['visible_to_parents' => true],
                    );
                }
            }
        });
    }
}
