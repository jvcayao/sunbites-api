<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CreditWaiveTest extends TestCase
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

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function waiveUrl(): string
    {
        return "/api/v1/students/{$this->student->id}/credit/waive";
    }

    private const REASON = 'Student graduated; balance written off per admin approval.';

    public function test_admin_can_waive_the_full_outstanding_credit(): void
    {
        $admin = $this->staffWithRole('admin');

        $response = $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => self::REASON,
        ]);

        $response->assertOk()->assertJson([
            'message' => 'Credit waived.',
            'amount_waived' => 150.0,
            'credit_balance' => 0.0,
        ]);

        $entry = CreditTransaction::where('student_id', $this->student->id)->sole();

        $this->assertSame(CreditTransactionType::Waived, $entry->type);
        $this->assertNull($entry->payment_method, 'A waive collects no money, so it must record no payment method.');
        $this->assertSame(self::REASON, $entry->notes);
        $this->assertSame($this->branch->id, $entry->branch_id);
        $this->assertSame($admin->id, $entry->performed_by);
    }

    public function test_admin_can_waive_part_of_the_outstanding_credit(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 50.00,
            'reason' => 'Goodwill adjustment for a disputed charge.',
        ])->assertOk();

        $this->assertEquals(100.0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_manager_cannot_waive_credit(): void
    {
        $manager = $this->staffWithRole('manager');

        $this->asUser($manager)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => self::REASON,
        ])->assertForbidden();

        $this->assertEquals(150.0, (float) $this->student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_supervisor_cannot_waive_credit(): void
    {
        $supervisor = $this->staffWithRole('supervisor');

        $this->asUser($supervisor)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => self::REASON,
        ])->assertForbidden();
    }

    public function test_cashier_cannot_waive_credit(): void
    {
        $cashier = $this->staffWithRole('cashier');

        $this->asUser($cashier)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => self::REASON,
        ])->assertForbidden();
    }

    public function test_a_reason_is_required(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), ['amount' => 150.00])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_a_reason_shorter_than_five_characters_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_reason_longer_than_a_thousand_characters_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => str_repeat('a', 1001),
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_thousand_character_reason_is_accepted(): void
    {
        $admin = $this->staffWithRole('admin');
        $reason = str_repeat('a', 1000);

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => $reason,
        ])->assertOk();

        $this->assertSame($reason, CreditTransaction::sole()->notes);
    }

    public function test_waiving_more_than_outstanding_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.01,
            'reason' => self::REASON,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertEquals(150.0, (float) $this->student->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_waiving_with_no_outstanding_credit_is_rejected(): void
    {
        $admin = $this->staffWithRole('admin');
        $this->student->update(['credit_balance' => 0]);

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 10.00,
            'reason' => self::REASON,
        ])->assertStatus(422)->assertJson(['message' => 'No outstanding credit to waive.']);
    }

    public function test_a_waive_writes_an_activity_log_entry_with_the_reason(): void
    {
        $admin = $this->staffWithRole('admin');

        $this->asUser($admin)->postJson($this->waiveUrl(), [
            'amount' => 150.00,
            'reason' => self::REASON,
        ])->assertOk();

        $activity = Activity::where('description', 'wallet.credit_waived')->sole();

        $this->assertSame('wallet', $activity->log_name);
        $this->assertSame($this->student->id, $activity->subject_id);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertEquals(150.0, $activity->properties['amount_waived']);
        $this->assertSame(self::REASON, $activity->properties['reason']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson($this->waiveUrl(), ['amount' => 150.00, 'reason' => self::REASON])
            ->assertStatus(401);
    }

    public function test_a_student_outside_the_active_branch_cannot_be_waived(): void
    {
        $admin = $this->staffWithRole('admin');

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create([
            'branch_id' => $otherBranch->id,
            'credit_balance' => 100.00,
        ]);

        $this->asUser($admin)
            ->postJson("/api/v1/students/{$otherStudent->id}/credit/waive", [
                'amount' => 100.00,
                'reason' => self::REASON,
            ])
            ->assertStatus(404);

        $this->assertEquals(100.0, (float) $otherStudent->fresh()->credit_balance);
    }
}
