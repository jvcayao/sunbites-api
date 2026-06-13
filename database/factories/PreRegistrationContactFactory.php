<?php

namespace Database\Factories;

use App\Models\PreRegistrationContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreRegistrationContact>
 */
class PreRegistrationContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'relationship' => fake()->randomElement(['Mother', 'Father', 'Guardian']),
            'phone' => '09'.fake()->numerify('#########'),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->address(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true, 'email' => fake()->safeEmail()]);
    }
}
