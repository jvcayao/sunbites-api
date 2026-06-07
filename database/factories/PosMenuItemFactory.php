<?php

namespace Database\Factories;

use App\Enums\MenuCategory;
use App\Models\PosMenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosMenuItem>
 */
class PosMenuItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->randomFloat(2, 10, 200),
            'category' => $this->faker->randomElement(MenuCategory::cases())->value,
            'is_available' => true,
            'sort_order' => 0,
            'is_subscription_item' => false,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }

    public function subscriptionEligible(): static
    {
        return $this->state(['is_subscription_item' => true]);
    }
}
