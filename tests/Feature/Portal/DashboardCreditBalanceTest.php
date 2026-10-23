<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The portal dashboard sends each student's credit owed, which its "Credit owed" badge reads
 * (Spec 16 B-21).
 */
class DashboardCreditBalanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_student_who_owes_credit_shows_the_amount_owed(): void
    {
        $parent = $this->parentOf(Student::factory()->create(['branch_id' => Branch::factory()->create()->id, 'credit_balance' => 37.50]));

        $response = $this->asParent($parent)->getJson('/api/v1/portal/dashboard');

        $response->assertOk()->assertJsonPath('students.0.credit_balance', 37.5);
    }

    public function test_a_student_with_no_credit_shows_zero(): void
    {
        $parent = $this->parentOf(Student::factory()->create(['branch_id' => Branch::factory()->create()->id]));

        $response = $this->asParent($parent)->getJson('/api/v1/portal/dashboard');

        $response->assertOk()->assertJsonPath('students.0.credit_balance', 0);
    }

    private function parentOf(Student $student): ParentUser
    {
        $parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $parent->students()->attach($student->id, [
            'linked_at' => now(),
            'linked_by' => User::factory()->create()->id,
            'wallet_alert_threshold' => 0,
        ]);

        return $parent;
    }

    private function asParent(ParentUser $parent): static
    {
        return $this->withToken($parent->createToken('portal-token', ['parent'])->plainTextToken);
    }
}
