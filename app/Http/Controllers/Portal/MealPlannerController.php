<?php

namespace App\Http\Controllers\Portal;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
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

        $branchId = $validated['branch_id'] ?? null;

        if ($branchId === null) {
            $firstStudent = $parent->students()->first();

            if ($firstStudent === null) {
                return response()->json(['message' => 'No linked students found.'], 422);
            }

            $branchId = $firstStudent->branch_id;
        } else {
            $hasStudentInBranch = $parent->students()->where('branch_id', $branchId)->exists();

            if (! $hasStudentInBranch) {
                return response()->json(['message' => 'You do not have a student in this branch.'], 403);
            }
        }

        $month = $validated['month'] ?? SchoolMonth::June->value;
        $week = (int) ($validated['week'] ?? 1);

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
            ];
        });

        return response()->json(['grid' => $grid]);
    }
}
