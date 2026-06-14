<?php

namespace Database\Factories;

use App\Enums\SchoolMonth;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentMonthlyPayment>
 */
class StudentMonthlyPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'school_month' => fake()->randomElement(SchoolMonth::cases())->value,
            'year' => now()->year,
            'status' => fake()->randomElement(['paid', 'unpaid']),
            'amount' => 2970,
            'recorded_at' => null,
            'recorded_by' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'recorded_at' => now()]);
    }

    public function unpaid(): static
    {
        return $this->state(fn () => ['status' => 'unpaid', 'recorded_at' => null]);
    }
}
