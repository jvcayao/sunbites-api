<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(2, 5, 100);

        return [
            'name' => $this->faker->word(),
            'quantity' => $quantity,
            'unit' => $this->faker->randomElement(['kg', 'pcs', 'boxes', 'liters']),
            'restock_threshold' => $this->faker->randomFloat(2, 5, 20),
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['quantity' => 0]);
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $attributes['restock_threshold'] - 1,
        ]);
    }
}
