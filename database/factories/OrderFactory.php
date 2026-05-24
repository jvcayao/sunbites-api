<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $branchId = Branch::first()?->id ?? Branch::factory()->create()->id;
        $cashierId = User::first()?->id ?? User::factory()->create()->id;
        $subtotal = $this->faker->randomFloat(2, 50, 500);
        $year = now()->year;

        return [
            'branch_id' => $branchId,
            'student_id' => null,
            'cashier_id' => $cashierId,
            'receipt_number' => 'TEST-'.$year.'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 6, '0', STR_PAD_LEFT),
            'payment_method' => PaymentMethod::Cash->value,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'discount_reason' => null,
            'total' => $subtotal,
            'amount_tendered' => $subtotal,
            'change_amount' => 0,
            'reference_number' => null,
            'notes' => null,
            'is_credit' => false,
            'credit_amount' => 0,
            'points_earned' => 0,
            'status' => OrderStatus::Completed->value,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }

    public function wallet(): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethod::Wallet->value,
            'amount_tendered' => null,
            'change_amount' => null,
        ]);
    }

    public function gcash(): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethod::Gcash->value,
            'amount_tendered' => null,
            'change_amount' => null,
        ]);
    }

    public function credit(): static
    {
        return $this->state(fn (array $attrs) => [
            'payment_method' => PaymentMethod::Wallet->value,
            'is_credit' => true,
            'credit_amount' => 50,
            'amount_tendered' => null,
            'change_amount' => null,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Voided->value,
            'voided_at' => now(),
            'void_reason' => 'Test void reason.',
        ]);
    }
}
