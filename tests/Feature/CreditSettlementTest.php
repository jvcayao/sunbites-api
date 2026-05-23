<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $this->branch = Branch::factory()->create();
        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);
    }

    private function actingAsUser(User $user): static
    {
        return $this->actingAs($user)->withSession(['active_branch_id' => $this->branch->id]);
    }

    public function test_admin_can_settle_credit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAsUser($admin)->post(route('kitchen.students.credit.settle', $this->student));

        $response->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 0]);
        $this->assertDatabaseHas('credit_transactions', [
            'student_id' => $this->student->id,
            'type' => CreditTransactionType::Settled->value,
        ]);
    }

    public function test_credit_balance_is_zeroed_atomically(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAsUser($admin)->post(route('kitchen.students.credit.settle', $this->student));

        $this->assertEquals(0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_credit_transaction_record_is_created_on_settlement(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAsUser($admin)->post(route('kitchen.students.credit.settle', $this->student));

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

        $response = $this->actingAsUser($supervisor)->post(route('kitchen.students.credit.settle', $this->student));

        $response->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'credit_balance' => 150.00]);
    }

    public function test_settling_zero_credit_balance_returns_error(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->student->update(['credit_balance' => 0]);

        $response = $this->actingAsUser($admin)->post(route('kitchen.students.credit.settle', $this->student));

        $response->assertSessionHasErrors('credit');
    }
}
