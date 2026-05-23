<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WalletTopUpTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function actingAsManager(): static
    {
        return $this->actingAs($this->manager)->withSession(['active_branch_id' => $this->branch->id]);
    }

    public function test_manager_can_top_up_wallet(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.students.wallet.top-up', $this->student), [
            'amount' => 100,
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();
        $this->assertGreaterThan(0, $this->student->fresh()->wallet->balance);
    }

    public function test_gcash_top_up_with_valid_reference_number(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.students.wallet.top-up', $this->student), [
            'amount' => 200,
            'payment_method' => 'gcash',
            'reference_number' => 'ABC123456',
        ]);

        $response->assertRedirect();
    }

    public function test_reference_number_must_be_alphanumeric(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.students.wallet.top-up', $this->student), [
            'amount' => 100,
            'payment_method' => 'gcash',
            'reference_number' => 'ABC-123!@#',
        ]);

        $response->assertSessionHasErrors('reference_number');
    }

    public function test_reference_number_max_50_chars(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.students.wallet.top-up', $this->student), [
            'amount' => 100,
            'payment_method' => 'gcash',
            'reference_number' => str_repeat('A', 51),
        ]);

        $response->assertSessionHasErrors('reference_number');
    }

    public function test_cashier_cannot_top_up_wallet(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('kitchen.students.wallet.top-up', $this->student), [
                'amount' => 100,
                'payment_method' => 'cash',
            ]);

        $response->assertForbidden();
    }

    public function test_amount_must_be_at_least_1(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.students.wallet.top-up', $this->student), [
            'amount' => 0,
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHasErrors('amount');
    }
}
