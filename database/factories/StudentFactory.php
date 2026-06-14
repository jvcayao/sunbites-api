<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $branchId = Branch::first()?->id ?? Branch::factory()->create()->id;

        return [
            'branch_id' => $branchId,
            'student_number' => strtoupper(Str::random(3)).'-'.date('Y').'-'.str_pad(fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'grade_level' => fake()->randomElement(['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6']),
            'section' => fake()->optional()->word(),
            'birthday' => fake()->date('Y-m-d', '-5 years'),
            'photo_path' => null,
            'allergies' => null,
            'notes' => null,
            'qr_code' => 'SB-'.Str::random(12),
            'student_type' => fake()->randomElement(StudentType::cases())->value,
            'enrollment_status' => EnrollmentStatus::Enrolled->value,
            'enrollment_date' => now()->toDateString(),
            'points' => 0,
            'total_spent' => 0,
            'credit_balance' => 0,
        ];
    }

    public function subscription(): static
    {
        return $this->state(fn () => ['student_type' => StudentType::Subscription->value]);
    }

    public function nonSubscription(): static
    {
        return $this->state(fn () => ['student_type' => StudentType::NonSubscription->value]);
    }

    public function enrolled(): static
    {
        return $this->state(fn () => ['enrollment_status' => EnrollmentStatus::Enrolled->value]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['enrollment_status' => EnrollmentStatus::Banned->value]);
    }

    public function withoutStudentNumber(): static
    {
        return $this->state(['student_number' => null]);
    }
}
