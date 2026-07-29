<?php

namespace Tests\Feature\Portal;

use App\Enums\CreditSettlementMethod;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalCreditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $student;

    private User $staff;

    private CreditLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create(['first_name' => 'Maris', 'last_name' => 'Cayao']);
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 150.00,
        ]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);

        $this->ledger = app(CreditLedgerService::class);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_the_linked_students_list_exposes_the_credit_balance(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk();
        $this->assertEquals(150.0, $response->json('data.0.credit_balance'));
    }

    public function test_the_wallet_response_exposes_the_credit_balance(): void
    {
        $response = $this->asParent()->getJson("/api/v1/portal/students/{$this->student->id}/wallet");

        $response->assertOk();
        $this->assertEquals(150.0, $response->json('credit_balance'));
    }

    public function test_a_student_with_no_credit_reports_zero(): void
    {
        $this->student->update(['credit_balance' => 0]);

        $this->asParent()->getJson('/api/v1/portal/students')
            ->assertOk()
            ->assertJsonPath('data.0.credit_balance', fn ($v) => (float) $v === 0.0);
    }

    public function test_the_portal_ledger_returns_the_same_contract_as_the_staff_ledger(): void
    {
        $this->student->deposit(20000);
        $this->ledger->charge($this->student, 25.00, 'R-P1', $this->staff);

        $response = $this->asParent()->getJson("/api/v1/portal/students/{$this->student->id}/ledger");

        $response->assertOk()->assertJsonStructure([
            'student' => ['id', 'full_name'],
            'balance',
            'credit_balance',
            'data' => [['id', 'date', 'entry_type', 'entry_label', 'direction', 'amount', 'payment_method', 'reference_number', 'note', 'performed_by']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        $types = collect($response->json('data'))->pluck('entry_type')->all();

        $this->assertContains('deposit', $types);
        $this->assertContains('credit_charged', $types);
    }

    public function test_the_portal_credit_filter_returns_only_credit_entries(): void
    {
        $this->student->deposit(20000);
        $this->student->withdraw(5000);
        $this->ledger->charge($this->student, 25.00, 'R-P2', $this->staff);

        $types = collect(
            $this->asParent()
                ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?entry_type=credit")
                ->json('data')
        )->pluck('entry_type')->unique()->all();

        $this->assertSame(['credit_charged'], $types);
    }

    public function test_a_settled_credit_entry_is_visible_to_the_parent(): void
    {
        $this->ledger->settleWithPayment(
            $this->student, 150.00, CreditSettlementMethod::Cash, null, 'Paid at the counter.', $this->staff
        );

        $row = collect(
            $this->asParent()
                ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?entry_type=credit")
                ->json('data')
        )->firstWhere('entry_type', 'credit_settled');

        $this->assertNotNull($row, 'Parents must be able to see that their payment was recorded.');
        $this->assertSame('Credit Paid', $row['entry_label']);
        $this->assertEquals(150.0, $row['amount']);
        $this->assertSame('cash', $row['payment_method']);
    }

    public function test_a_parent_cannot_read_the_ledger_of_an_unlinked_student(): void
    {
        $otherStudent = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 90.00,
        ]);

        $this->asParent()
            ->getJson("/api/v1/portal/students/{$otherStudent->id}/ledger")
            ->assertForbidden();
    }

    public function test_a_parent_cannot_read_the_wallet_of_an_unlinked_student(): void
    {
        $otherStudent = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->asParent()
            ->getJson("/api/v1/portal/students/{$otherStudent->id}/wallet")
            ->assertForbidden();
    }

    public function test_a_staff_token_cannot_reach_the_portal_ledger(): void
    {
        Sanctum::actingAs($this->staff, ['staff']);

        $this->getJson("/api/v1/portal/students/{$this->student->id}/ledger")
            ->assertStatus(401);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson("/api/v1/portal/students/{$this->student->id}/ledger")
            ->assertStatus(401);
    }

    public function test_waive_reasons_are_never_exposed_to_parents(): void
    {
        $reason = 'Family hardship; confidential staff note.';

        $this->ledger->waive($this->student, 150.00, $reason, $this->staff);

        $ledgerResponse = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?entry_type=credit");

        $ledgerResponse->assertOk()->assertDontSee($reason);

        $row = collect($ledgerResponse->json('data'))->firstWhere('entry_type', 'credit_waived');

        $this->assertNotNull($row, 'The waive itself should be visible so the balance change is explained.');
        $this->assertNull($row['note'], 'The staff-only reason must be withheld from parents.');
    }

    public function test_the_portal_ledger_paginates(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ledger->charge($this->student, 5.00, "R-PG{$i}", $this->staff);
        }

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_an_invalid_entry_type_is_rejected(): void
    {
        $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?entry_type=bogus")
            ->assertStatus(422)
            ->assertJsonValidationErrors('entry_type');
    }
}
