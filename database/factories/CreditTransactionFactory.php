<?php

namespace Database\Factories;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    public function definition(): array
    {
        $student = Student::withoutBranch()->first() ?? Student::factory()->create();
        $performerId = User::first()?->id ?? User::factory()->create()->id;

        return [
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
            'order_id' => null,
            'type' => CreditTransactionType::Charged->value,
            'amount' => $this->faker->randomFloat(2, 10, 200),
            'payment_method' => null,
            'reference_number' => null,
            'wallet_transaction_id' => null,
            'notes' => null,
            'performed_by' => $performerId,
            'created_at' => now(),
        ];
    }

    public function charged(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::Charged->value,
            'payment_method' => null,
            'reference_number' => null,
        ]);
    }

    public function settled(CreditSettlementMethod $method = CreditSettlementMethod::Cash): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::Settled->value,
            'payment_method' => $method->value,
            'reference_number' => $method->requiresReferenceNumber() ? strtoupper($this->faker->bothify('??####')) : null,
        ]);
    }

    public function waived(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::Waived->value,
            'payment_method' => null,
            'reference_number' => null,
            'notes' => 'Written off for testing purposes.',
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::Voided->value,
            'payment_method' => null,
            'reference_number' => null,
        ]);
    }
}
