<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditSettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_admin_can_settle_credit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->asUser($admin)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'type' => CreditTransactionType::Settled->value,
        ]);
    }

    public function test_manager_can_settle_credit(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->asUser($manager)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
    }

    public function test_credit_balance_is_zeroed_atomically(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->asUser($admin)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $this->assertEquals(0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_credit_transaction_record_is_created_on_settlement(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->asUser($admin)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $this->assertDatabaseCount('credit_transactions', 1);
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'amount' => '150.00',
            'type' => CreditTransactionType::Settled->value,
            'performed_by' => $admin->id,
        ]);
    }

    public function test_supervisor_cannot_settle_credit(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->asUser($supervisor)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $response->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 150.00]);
    }

    public function test_settling_zero_credit_balance_returns_error(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $this->student->update(['credit_balance' => 0]);

        $response = $this->asUser($admin)->postJson("/api/v1/students/{$this->student->id}/credit/settle");

        $response->assertStatus(422);
        $response->assertJson(['message' => 'No outstanding credit to settle.']);
    }
}
