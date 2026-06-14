<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\MealPlannerWeekVisibility;
use App\Models\WeeklyMealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class MealPlannerController extends Controller
{
    /**
     * @var array<string, array{ulam: string, vegetables: string, fruit: string, soup: string, snacks: string}>
     */
    private const DEFAULT_WEEK = [
        'monday' => ['ulam' => '', 'vegetables' => '', 'fruit' => '', 'soup' => '', 'snacks' => ''],
        'tuesday' => ['ulam' => '', 'vegetables' => '', 'fruit' => '', 'soup' => '', 'snacks' => ''],
        'wednesday' => ['ulam' => '', 'vegetables' => '', 'fruit' => '', 'soup' => '', 'snacks' => ''],
        'thursday' => ['ulam' => '', 'vegetables' => '', 'fruit' => '', 'soup' => '', 'snacks' => ''],
        'friday' => ['ulam' => '', 'vegetables' => '', 'fruit' => '', 'soup' => '', 'snacks' => ''],
    ];

    public function show(Request $request): JsonResponse
    {
        $month = $request->input('month', SchoolMonth::June->value);
        $week = (int) $request->input('week', 1);

        $plans = WeeklyMealPlan::where('school_month', $month)
            ->where('week_number', $week)
            ->get()
            ->keyBy(fn (WeeklyMealPlan $plan) => $plan->day_of_week->value);

        $grid = collect(DayOfWeek::cases())->map(function (DayOfWeek $day) use ($plans) {
            $plan = $plans->get($day->value);

            return [
                'day' => $day->value,
                'day_label' => $day->label(),
                'ulam' => $plan?->ulam ?? '',
                'vegetables' => $plan?->vegetables ?? '',
                'fruit' => $plan?->fruit ?? '',
                'soup' => $plan?->soup ?? '',
                'snacks' => $plan?->snacks ?? '',
            ];
        });

        return response()->json([
            'days' => $grid,
            'visible_to_parents' => $this->isWeekVisibleToParents($month, $week),
        ]);
    }

    public function update(Request $request): JsonResponse
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
            'rows.*.snacks' => ['nullable', 'string', 'max:255'],
        ]);

        if (! app()->bound('active_branch')) {
            return response()->json(['message' => 'No active branch selected. Please set a branch first.'], 422);
        }

        $branchId = app('active_branch')->id;

        foreach ($validated['rows'] as $row) {
            WeeklyMealPlan::withoutBranch()->updateOrCreate(
                ['branch_id' => $branchId, 'school_month' => $validated['month'], 'week_number' => $validated['week'], 'day_of_week' => $row['day']],
                ['ulam' => $row['ulam'], 'vegetables' => $row['vegetables'], 'fruit' => $row['fruit'], 'soup' => $row['soup'], 'snacks' => $row['snacks']],
            );
        }

        activity('meal_planner')
            ->causedBy($request->user())
            ->withProperties(['month' => $validated['month'], 'week' => $validated['week']])
            ->log('meal_planner.saved');

        $monthLabel = SchoolMonth::from($validated['month'])->label();

        return response()->json(['message' => "Week {$validated['week']} of {$monthLabel} menu saved."]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', new Enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        if (! app()->bound('active_branch')) {
            return response()->json(['message' => 'No active branch selected. Please set a branch first.'], 422);
        }

        $branchId = app('active_branch')->id;

        foreach (DayOfWeek::cases() as $day) {
            WeeklyMealPlan::withoutBranch()->updateOrCreate(
                ['branch_id' => $branchId, 'school_month' => $validated['month'], 'week_number' => $validated['week'], 'day_of_week' => $day->value],
                self::DEFAULT_WEEK[$day->value],
            );
        }

        activity('meal_planner')
            ->causedBy($request->user())
            ->withProperties(['month' => $validated['month'], 'week' => $validated['week']])
            ->log('meal_planner.reset');

        return response()->json(['message' => 'Week reset to default pattern.']);
    }

    public function updateWeekVisibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', new Enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
            'visible_to_parents' => ['required', 'boolean'],
        ]);

        if (! app()->bound('active_branch')) {
            return response()->json(['message' => 'No active branch selected. Please set a branch first.'], 422);
        }

        $branchId = app('active_branch')->id;

        MealPlannerWeekVisibility::withoutBranch()->updateOrCreate(
            ['branch_id' => $branchId, 'school_month' => $validated['month'], 'week_number' => $validated['week']],
            ['visible_to_parents' => $validated['visible_to_parents']],
        );

        activity('meal_planner')
            ->causedBy($request->user())
            ->withProperties([
                'month' => $validated['month'],
                'week' => $validated['week'],
                'visible_to_parents' => $validated['visible_to_parents'],
                'branch_id' => $branchId,
            ])
            ->log('meal_planner.week_visibility_changed');

        return response()->json([
            'message' => 'Week visibility updated.',
            'visible_to_parents' => $validated['visible_to_parents'],
        ]);
    }

    private function isWeekVisibleToParents(string $month, int $week): bool
    {
        if (! app()->bound('active_branch')) {
            return true;
        }

        $branchId = app('active_branch')->id;

        $record = MealPlannerWeekVisibility::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('school_month', $month)
            ->where('week_number', $week)
            ->first();

        return $record?->visible_to_parents ?? true;
    }
}
