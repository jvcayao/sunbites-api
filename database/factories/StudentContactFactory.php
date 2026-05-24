<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentContact>
 */
class StudentContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'full_name' => fake()->name(),
            'relationship' => fake()->randomElement(['Mother', 'Father', 'Guardian', 'Aunt', 'Uncle']),
            'phone' => fake()->numerify('091########'),
            'address' => fake()->address(),
            'email' => null,
            'is_primary' => false,
        ];
    }
}
