<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\WeeklyMealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class MealPlannerController extends Controller
{
    /**
     * @var array<string, array{ulam: string, vegetables: string, fruit: string, soup: string}>
     */
    private array $defaultWeek = [
        'monday' => ['ulam' => 'Chicken Adobo', 'vegetables' => 'Chopsuey', 'fruit' => 'Mango', 'soup' => 'Nilaga Soup'],
        'tuesday' => ['ulam' => 'Pork Sinigang', 'vegetables' => 'Pinakbet', 'fruit' => 'Banana', 'soup' => 'Miso Soup'],
        'wednesday' => ['ulam' => 'Fish Tinola', 'vegetables' => 'Laing', 'fruit' => 'Apple', 'soup' => 'Sinigang Broth'],
        'thursday' => ['ulam' => 'Beef Kaldereta', 'vegetables' => 'Ginisang Gulay', 'fruit' => 'Orange', 'soup' => 'Chicken Broth'],
        'friday' => ['ulam' => 'Chicken Inasal', 'vegetables' => 'Ampalaya', 'fruit' => 'Watermelon', 'soup' => 'Corn Soup'],
    ];

    public function show(Request $request): Response
    {
        $month = $request->input('month', SchoolMonth::June->value);
        $week = (int) $request->input('week', 1);

        $plans = WeeklyMealPlan::where('school_month', $month)
            ->where('week_number', $week)
            ->get()
            ->keyBy(fn (WeeklyMealPlan $plan) => $plan->day_of_week->value);

        $grid = collect(DayOfWeek::cases())->map(fn (DayOfWeek $day) => [
            'day' => $day->value,
            'day_label' => $day->label(),
            'ulam' => $plans->get($day->value)?->ulam ?? '',
            'vegetables' => $plans->get($day->value)?->vegetables ?? '',
            'fruit' => $plans->get($day->value)?->fruit ?? '',
            'soup' => $plans->get($day->value)?->soup ?? '',
        ]);

        return Inertia::render('kitchen/references/meal-planner/index', [
            'grid' => $grid,
            'months' => collect(SchoolMonth::cases())->map(fn (SchoolMonth $m) => ['value' => $m->value, 'label' => $m->label()]),
            'currentMonth' => $month,
            'currentWeek' => $week,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', new Enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
            'rows' => ['required', 'array', 'size:5'],
            'rows.*.day' => ['required', new Enum(DayOfWeek::class)],
            'rows.*.ulam' => ['nullable', 'string', 'max:255'],
            'rows.*.vegetables' => ['nullable', 'string', 'max:255'],
            'rows.*.fruit' => ['nullable', 'string', 'max:255'],
            'rows.*.soup' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = app('active_branch')->id;

        foreach ($validated['rows'] as $row) {
            WeeklyMealPlan::withoutBranch()->updateOrCreate(
                ['branch_id' => $branchId, 'school_month' => $validated['month'], 'week_number' => $validated['week'], 'day_of_week' => $row['day']],
                ['ulam' => $row['ulam'], 'vegetables' => $row['vegetables'], 'fruit' => $row['fruit'], 'soup' => $row['soup']],
            );
        }

        activity('meal_planner')
            ->causedBy($request->user())
            ->withProperties(['month' => $validated['month'], 'week' => $validated['week']])
            ->log('meal_planner.saved');

        return back()->with('success', "Week {$validated['week']} of ".SchoolMonth::from($validated['month'])->label().' menu saved.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', new Enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $branchId = app('active_branch')->id;

        foreach (DayOfWeek::cases() as $day) {
            $defaults = $this->defaultWeek[$day->value];
            WeeklyMealPlan::withoutBranch()->updateOrCreate(
                ['branch_id' => $branchId, 'school_month' => $validated['month'], 'week_number' => $validated['week'], 'day_of_week' => $day->value],
                $defaults,
            );
        }

        activity('meal_planner')
            ->causedBy($request->user())
            ->withProperties(['month' => $validated['month'], 'week' => $validated['week']])
            ->log('meal_planner.reset');

        return back()->with('success', 'Week reset to default pattern.');
    }
}
