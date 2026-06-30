<?php

namespace Database\Factories;

use App\Enums\PreRegistrationStatus;
use App\Models\Branch;
use App\Models\PreRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreRegistration>
 */
class PreRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branchId = Branch::first()?->id ?? Branch::factory()->create()->id;

        return [
            'branch_id' => $branchId,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'student_number' => null,
            'grade_level' => fake()->randomElement(['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6']),
            'section' => null,
            'birthday' => fake()->date('Y-m-d', '-5 years'),
            'enrollment_type' => 'non_subscription',
            'allergies' => null,
            'notes' => null,
            'signatory_name' => fake()->name(),
            'acknowledged_at' => now(),
            'status' => PreRegistrationStatus::Pending,
            'recaptcha_score' => 0.9,
            'submitter_ip' => '127.0.0.1',
            'expires_at' => now()->addDays(30),
        ];
    }

    public function subscription(): static
    {
        return $this->state(fn () => [
            'enrollment_type' => 'subscription',
            'subscription_start_month' => 'june',
            'subscription_start_year' => (int) date('Y'),
            'subscription_end_month' => 'march',
            'subscription_end_year' => (int) date('Y') + 1,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => PreRegistrationStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PreRegistrationStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => PreRegistrationStatus::Approved]);
    }
}
