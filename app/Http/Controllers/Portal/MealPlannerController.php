<?php

namespace App\Http\Controllers\Portal;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\MealPlannerWeekVisibility;
use App\Models\WeeklyMealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealPlannerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'month' => ['nullable', 'string'],
            'week' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $parent = $request->user();

        if (! $parent->students()->where('student_type', StudentType::Subscription)->exists()) {
            abort(403, 'Meal plan access requires a subscription student.');
        }

        if (isset($validated['branch_id'])) {
            abort_unless(
                $parent->students()->where('branch_id', $validated['branch_id'])->exists(),
                403,
                'You do not have a student in this branch.'
            );

            $branchId = $validated['branch_id'];
        } else {
            $firstStudent = $parent->students()->first();

            if ($firstStudent === null) {
                return response()->json(['message' => 'No linked students found.'], 422);
            }

            $branchId = $firstStudent->branch_id;
        }

        $month = $validated['month'] ?? SchoolMonth::June->value;
        $week = (int) ($validated['week'] ?? 1);

        $visibleToParents = MealPlannerWeekVisibility::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('school_month', $month)
            ->where('week_number', $week)
            ->value('visible_to_parents') ?? true;

        if (! $visibleToParents) {
            return response()->json(['visible_to_parents' => false, 'days' => []]);
        }

        $plans = WeeklyMealPlan::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('school_month', $month)
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

        return response()->json(['visible_to_parents' => true, 'days' => $grid]);
    }
}
