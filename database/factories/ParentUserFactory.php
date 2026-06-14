<?php

namespace Database\Factories;

use App\Models\ParentUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParentUser>
 */
class ParentUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Password1'),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'profile_photo_path' => null,
            'email_verified_at' => now(),
            'disabled_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function unactivated(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => now(),
            'disabled_at' => now(),
        ]);
    }
}
