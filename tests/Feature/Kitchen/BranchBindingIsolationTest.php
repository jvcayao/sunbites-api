<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PreRegistration;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression guard for the branch-isolation fix in bootstrap/app.php.
 *
 * `SetActiveBranch` MUST run before `SubstituteBindings`. It originally used
 * `$middleware->api(append: ...)`, which placed it after — so route model binding resolved
 * `{student}`, `{order}` and friends before `active_branch` was bound, and
 * `BranchScope::apply()` returns early when the container has no `active_branch`. The scope
 * silently no-opped and any record resolved by id regardless of branch.
 *
 * Every test here calls `forgetActiveBranch()` first. That matters: within a single PHPUnit
 * process `app()->instance('active_branch', ...)` survives between requests, so the second
 * and later requests in a test appear correctly scoped even when the middleware order is
 * wrong. Without explicitly forgetting it, these tests would pass against the broken
 * ordering and prove nothing. Forgetting it reproduces a cold request, which is what every
 * request looks like in production.
 */
class BranchBindingIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $homeBranch;

    private Branch $foreignBranch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->homeBranch = Branch::factory()->create(['is_active' => true, 'slug' => 'home']);
        $this->foreignBranch = Branch::factory()->create(['is_active' => true, 'slug' => 'frgn']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->homeBranch->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
    }

    /**
     * Simulate a cold container, as every production request has.
     */
    private function forgetActiveBranch(): void
    {
        app()->forgetInstance('active_branch');
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->homeBranch->id]);
    }

    private function foreignStudent(float $credit = 100.00): Student
    {
        return Student::factory()->create([
            'branch_id' => $this->foreignBranch->id,
            'credit_balance' => $credit,
        ]);
    }

    public function test_a_foreign_student_cannot_be_read(): void
    {
        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        $this->asManager()->getJson("/api/v1/students/{$student->id}")->assertStatus(404);
    }

    public function test_a_foreign_student_ledger_cannot_be_read(): void
    {
        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        $this->asManager()->getJson("/api/v1/students/{$student->id}/ledger")->assertStatus(404);
    }

    public function test_a_foreign_student_credit_cannot_be_settled(): void
    {
        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        $this->asManager()
            ->postJson("/api/v1/students/{$student->id}/credit/settle", [
                'amount' => 100.00,
                'payment_method' => 'cash',
            ])
            ->assertStatus(404);

        $this->assertEquals(
            100.0,
            (float) $student->fresh()->credit_balance,
            'A cross-branch settlement must not move money.'
        );
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_a_foreign_student_credit_cannot_be_waived(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->homeBranch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        Sanctum::actingAs($admin, ['staff']);

        $this->withHeaders(['X-Branch-Id' => $this->homeBranch->id])
            ->postJson("/api/v1/students/{$student->id}/credit/waive", [
                'amount' => 100.00,
                'reason' => 'Attempted cross-branch write-off.',
            ])
            ->assertStatus(404);

        $this->assertEquals(100.0, (float) $student->fresh()->credit_balance);
    }

    public function test_a_foreign_student_wallet_cannot_be_topped_up(): void
    {
        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        $this->asManager()
            ->postJson("/api/v1/students/{$student->id}/wallet/top-up", [
                'amount' => 500.00,
                'payment_method' => 'cash',
            ])
            ->assertStatus(404);

        $this->assertEquals(
            0.0,
            (float) ($student->fresh()->load('wallet')->wallet?->balanceFloat ?? 0),
            'A cross-branch top-up must not add funds.'
        );
    }

    public function test_a_foreign_student_cannot_be_updated(): void
    {
        $student = $this->foreignStudent();
        $original = $student->first_name;
        $this->forgetActiveBranch();

        $this->asManager()
            ->putJson("/api/v1/students/{$student->id}", ['first_name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame($original, $student->fresh()->first_name);
    }

    public function test_a_foreign_student_cannot_be_deleted(): void
    {
        $student = $this->foreignStudent();
        $this->forgetActiveBranch();

        $this->asManager()
            ->deleteJson("/api/v1/students/{$student->id}")
            ->assertStatus(404);

        $this->assertNull($student->fresh()->deleted_at);
    }

    public function test_a_foreign_order_cannot_be_voided(): void
    {
        $student = $this->foreignStudent();

        $order = Order::factory()->create([
            'branch_id' => $this->foreignBranch->id,
            'cashier_id' => $this->manager->id,
            'student_id' => $student->id,
            'total' => 50.00,
        ]);

        $this->forgetActiveBranch();

        $this->asManager()
            ->postJson("/api/v1/pos/transactions/{$order->id}/void", [
                'void_reason' => 'Attempted cross-branch void.',
            ])
            ->assertStatus(404);

        $this->assertNull($order->fresh()->voided_at);
    }

    public function test_a_students_own_branch_still_resolves_normally(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->homeBranch->id,
            'credit_balance' => 100.00,
        ]);

        $this->forgetActiveBranch();

        $this->asManager()->getJson("/api/v1/students/{$student->id}")->assertOk();
    }

    public function test_settling_credit_in_the_users_own_branch_still_works_on_a_cold_container(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->homeBranch->id,
            'credit_balance' => 100.00,
        ]);

        $this->forgetActiveBranch();

        $this->asManager()
            ->postJson("/api/v1/students/{$student->id}/credit/settle", [
                'amount' => 100.00,
                'payment_method' => 'cash',
            ])
            ->assertOk();

        $this->assertEquals(0.0, (float) $student->fresh()->credit_balance);
    }

    public function test_pre_registration_remains_deliberately_cross_branch(): void
    {
        $this->forgetActiveBranch();

        // Documented exception: PreRegistrationController::approve() resolves with
        // withoutBranch() so staff can approve a pre-registration from another branch they
        // have access to. AppServiceProvider binds {preRegistration} accordingly. If this
        // test starts failing, that binding was removed and the approval flow is broken.
        $this->assertTrue(
            app()->bound('active_branch') === false,
            'Sanity check: the active branch really was forgotten.'
        );

        $preRegistration = PreRegistration::factory()->create([
            'branch_id' => $this->foreignBranch->id,
        ]);

        $this->asManager()
            ->getJson("/api/v1/pre-registrations/{$preRegistration->id}")
            ->assertOk();
    }
}
