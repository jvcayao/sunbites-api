<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListBranchMonthlyAmountsRequest;
use App\Http\Requests\Kitchen\StoreBranchMonthlyAmountRequest;
use App\Http\Requests\Kitchen\UpdateBranchMonthlyAmountRequest;
use App\Models\BranchMonthlyAmount;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;

class BranchMonthlyAmountController extends Controller
{
    public function index(ListBranchMonthlyAmountsRequest $request): JsonResponse
    {
        $year = $request->integer('year') ?: now()->year;
        $branchId = app('active_branch')->id;

        $configured = BranchMonthlyAmount::where('branch_id', $branchId)
            ->where('year', $year)
            ->get()
            ->keyBy(fn ($r) => $r->school_month->value);

        $configMonths = config('sunbites.school_months');
        $dailyRate = SystemConfiguration::getValue('daily_meal_rate', 135);

        $months = collect(SchoolMonth::cases())->map(fn ($m) => [
            'id' => $configured->get($m->value)?->id,
            'school_month' => $m->value,
            'label' => $m->label(),
            'year' => $year,
            'days' => $configured->get($m->value)?->days ?? ($configMonths[$m->value]['days'] ?? 0),
            'amount' => $configured->get($m->value)?->amount ?? (($configMonths[$m->value]['days'] ?? 0) * $dailyRate),
            'is_configured' => $configured->has($m->value),
        ]);

        return response()->json($months);
    }

    public function store(StoreBranchMonthlyAmountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;
        $amount = isset($validated['amount'])
            ? (float) $validated['amount']
            : $validated['days'] * SystemConfiguration::getValue('daily_meal_rate', 135);

        $record = BranchMonthlyAmount::updateOrCreate(
            [
                'branch_id' => $branchId,
                'school_month' => $validated['school_month'],
                'year' => $validated['year'],
            ],
            [
                'days' => $validated['days'],
                'amount' => $amount,
            ]
        );

        return response()->json($record, $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateBranchMonthlyAmountRequest $request, BranchMonthlyAmount $branchMonthlyAmount): JsonResponse
    {
        $validated = $request->validated();

        $amount = isset($validated['amount'])
            ? (float) $validated['amount']
            : $validated['days'] * SystemConfiguration::getValue('daily_meal_rate', 135);

        $branchMonthlyAmount->update(['days' => $validated['days'], 'amount' => $amount]);

        return response()->json($branchMonthlyAmount);
    }

    public function destroy(BranchMonthlyAmount $branchMonthlyAmount): JsonResponse
    {
        $branchMonthlyAmount->delete();

        return response()->json(['message' => 'Month config deleted.']);
    }
}
