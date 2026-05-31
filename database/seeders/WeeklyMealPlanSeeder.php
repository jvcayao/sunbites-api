<?php

namespace Database\Seeders;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\WeeklyMealPlan;
use Illuminate\Database\Seeder;

class WeeklyMealPlanSeeder extends Seeder
{
    /**
     * @var array<string, array{ulam: string, vegetables: string, fruit: string, soup: string, snacks: string}>
     */
    private array $defaultWeek = [
        'monday' => ['ulam' => 'Chicken Adobo', 'vegetables' => 'Chopsuey', 'fruit' => 'Mango', 'soup' => 'Nilaga Soup', 'snacks' => 'Graham Crackers'],
        'tuesday' => ['ulam' => 'Pork Sinigang', 'vegetables' => 'Pinakbet', 'fruit' => 'Banana', 'soup' => 'Miso Soup', 'snacks' => 'Bread Roll'],
        'wednesday' => ['ulam' => 'Fish Tinola', 'vegetables' => 'Laing', 'fruit' => 'Apple', 'soup' => 'Sinigang Broth', 'snacks' => 'Biscuit'],
        'thursday' => ['ulam' => 'Beef Kaldereta', 'vegetables' => 'Ginisang Gulay', 'fruit' => 'Orange', 'soup' => 'Chicken Broth', 'snacks' => 'Banana Cue'],
        'friday' => ['ulam' => 'Chicken Inasal', 'vegetables' => 'Ampalaya', 'fruit' => 'Watermelon', 'soup' => 'Corn Soup', 'snacks' => 'Puto'],
    ];

    public function run(): void
    {
        Branch::all()->each(function (Branch $branch): void {
            foreach (SchoolMonth::cases() as $month) {
                for ($week = 1; $week <= 4; $week++) {
                    foreach (DayOfWeek::cases() as $day) {
                        $defaults = $this->defaultWeek[$day->value];
                        WeeklyMealPlan::withoutBranch()->updateOrCreate(
                            [
                                'branch_id' => $branch->id,
                                'school_month' => $month->value,
                                'week_number' => $week,
                                'day_of_week' => $day->value,
                            ],
                            $defaults,
                        );
                    }
                }
            }
        });
    }
}
