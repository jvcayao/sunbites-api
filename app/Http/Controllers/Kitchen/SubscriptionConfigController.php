<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\BranchSubscriptionConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $config = BranchSubscriptionConfig::firstOrNew(
            ['branch_id' => app('active_branch')->id],
            [
                'meal_daily_limit' => 1,
                'snack_daily_limit' => 1,
                'drink_daily_limit' => 1,
                'extra_daily_limit' => 1,
            ]
        );

        return response()->json($this->formatConfig($config));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meal_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'snack_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'drink_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'extra_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $config = BranchSubscriptionConfig::forBranch(app('active_branch')->id);
        $config->update($validated);

        return response()->json($this->formatConfig($config));
    }

    /**
     * @return array<string, int>
     */
    private function formatConfig(BranchSubscriptionConfig $config): array
    {
        return [
            'meal_daily_limit' => $config->meal_daily_limit,
            'snack_daily_limit' => $config->snack_daily_limit,
            'drink_daily_limit' => $config->drink_daily_limit,
            'extra_daily_limit' => $config->extra_daily_limit,
        ];
    }
}
