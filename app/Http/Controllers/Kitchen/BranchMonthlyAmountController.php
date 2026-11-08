<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListBranchMonthlyAmountsRequest;
use App\Http\Requests\Kitchen\StoreBranchMonthlyAmountRequest;
use App\Http\Requests\Kitchen\UpdateBranchMonthlyAmountRequest;
use App\Models\BranchMonthlyAmount;
use App\Models\SystemConfiguration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $months = collect(SchoolMonth::cases())->map(function (SchoolMonth $m) use ($configured, $configMonths, $dailyRate, $year) {
            $record = $configured->get($m->value);
            $defaultDays = $configMonths[$m->value]['days'] ?? 0;

            return [
                'id' => $record?->id,
                'school_month' => $m->value,
                'label' => $m->label(),
                'year' => $year,
                'days' => $record?->days ?? $defaultDays,
                'amount' => $record?->amount ?? ($defaultDays * $dailyRate),
                'is_configured' => $configured->has($m->value),
            ];
        });

        return response()->json($months);
    }

    public function store(StoreBranchMonthlyAmountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = BranchMonthlyAmount::updateOrCreate(
            [
                'branch_id' => app('active_branch')->id,
                'school_month' => $validated['school_month'],
                'year' => $validated['year'],
            ],
            [
                'days' => $validated['days'],
                'amount' => $this->resolveAmount($validated),
            ]
        );

        return response()->json($record, $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateBranchMonthlyAmountRequest $request, BranchMonthlyAmount $branchMonthlyAmount): JsonResponse
    {
        $validated = $request->validated();

        $branchMonthlyAmount->update(['days' => $validated['days'], 'amount' => $this->resolveAmount($validated)]);

        return response()->json($branchMonthlyAmount);
    }

    public function destroy(BranchMonthlyAmount $branchMonthlyAmount): JsonResponse
    {
        if ($branchMonthlyAmount->branch_id !== app('active_branch')->id) {
            throw (new ModelNotFoundException)->setModel(BranchMonthlyAmount::class);
        }

        $branchMonthlyAmount->delete();

        return response()->json(['message' => 'Month config deleted.']);
    }

    /**
     * The explicit amount when one was sent, otherwise the days times the daily meal rate.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveAmount(array $validated): int|float
    {
        return isset($validated['amount'])
            ? (float) $validated['amount']
            : $validated['days'] * SystemConfiguration::getValue('daily_meal_rate', 135);
    }
}
