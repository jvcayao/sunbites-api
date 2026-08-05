<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTopupVoid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTopupVoid>
 */
class WalletTopupVoidFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $student = Student::withoutBranch()->first() ?? Student::factory()->create();
        $voidedById = User::first()?->id ?? User::factory()->create()->id;
        $originalAmount = $this->faker->randomFloat(2, 10, 500);

        return [
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
            'wallet_transaction_id' => $this->faker->unique()->numberBetween(1, 999999),
            'refund_wallet_transaction_id' => null,
            'credit_transaction_id' => null,
            'original_amount' => $originalAmount,
            'voided_amount' => $originalAmount,
            'shortfall_amount' => 0,
            'void_reason' => $this->faker->sentence(),
            'voided_by' => $voidedById,
            'created_at' => now(),
        ];
    }

    /**
     * A void where the full original amount was recoverable from the wallet.
     */
    public function fullyRecovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_amount' => $attributes['original_amount'],
            'shortfall_amount' => 0,
            'credit_transaction_id' => null,
        ]);
    }

    /**
     * A void where part of the original amount had already been spent, so the
     * unrecoverable remainder was charged to the student's credit ledger.
     */
    public function withShortfall(): static
    {
        return $this->state(function (array $attributes) {
            $originalAmount = $attributes['original_amount'];
            $voidedAmount = round($originalAmount / 2, 2);
            $shortfallAmount = round($originalAmount - $voidedAmount, 2);

            return [
                'voided_amount' => $voidedAmount,
                'shortfall_amount' => $shortfallAmount,
                'credit_transaction_id' => CreditTransaction::factory()->charged()->create([
                    'student_id' => $attributes['student_id'],
                    'branch_id' => $attributes['branch_id'],
                    'amount' => $shortfallAmount,
                    'performed_by' => $attributes['voided_by'],
                ])->id,
            ];
        });
    }
}
