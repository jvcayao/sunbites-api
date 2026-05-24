<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_manager_can_top_up_wallet(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 100,
            'payment_method' => 'cash',
        ]);

        $response->assertOk();
        $response->assertJsonPath('new_balance', fn ($b) => $b > 0);
    }

    public function test_gcash_top_up_with_valid_reference_number(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 200,
            'payment_method' => 'gcash',
            'reference_number' => 'ABC123456',
        ]);

        $response->assertOk();
    }

    public function test_reference_number_must_be_alphanumeric(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 100,
            'payment_method' => 'gcash',
            'reference_number' => 'ABC-123!@#',
        ]);

        $response->assertJsonValidationErrors(['reference_number']);
    }

    public function test_reference_number_max_50_chars(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 100,
            'payment_method' => 'gcash',
            'reference_number' => str_repeat('A', 51),
        ]);

        $response->assertJsonValidationErrors(['reference_number']);
    }

    public function test_cashier_cannot_top_up_wallet(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
                'amount' => 100,
                'payment_method' => 'cash',
            ]);

        $response->assertForbidden();
    }

    public function test_amount_must_be_at_least_1(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
            'amount' => 0,
            'payment_method' => 'cash',
        ]);

        $response->assertJsonValidationErrors(['amount']);
    }
}
