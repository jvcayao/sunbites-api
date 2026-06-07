<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchSubscriptionConfig>
 */
class BranchSubscriptionConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ];
    }
}
