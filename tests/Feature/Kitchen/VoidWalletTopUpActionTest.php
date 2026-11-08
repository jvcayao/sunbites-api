<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTopupVoid;
use Bavix\Wallet\Enums\TransactionType;
use Bavix\Wallet\Models\Transaction;
use Database\Seeders\PermissionSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Each top-up void guard keeps its message, status and mechanism, and the refund and credit
 * split is unchanged (Spec 16 T-4.2, design §4.3.4). Debug mode is on so the mechanism is
 * visible (hazard H3): every guard is an `abort_if()`, which adds the exception details.
 */
class VoidWalletTopUpActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = $this->staff('admin');
        $this->manager = $this->staff('manager');
    }

    public function test_student_without_a_wallet_returns_404_not_found(): void
    {
        $student = $this->student();

        $response = $this->void($this->admin, $student, 1);

        $this->assertAbortGuard($response->assertNotFound(), NotFoundHttpException::class, 'Top-up transaction not found.');
    }

    public function test_transaction_from_another_wallet_returns_404_not_found(): void
    {
        $student = $this->student();
        $this->topUp($student, 10, $this->manager);
        $otherDeposit = $this->topUp($this->student(), 100, $this->manager);

        $response = $this->void($this->admin, $student, $otherDeposit->id);

        $this->assertAbortGuard($response->assertNotFound(), NotFoundHttpException::class, 'Top-up transaction not found.');
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_top_up_that_is_already_voided_returns_422(): void
    {
        $student = $this->student();
        $deposit = $this->topUp($student, 100, $this->manager);
        $this->void($this->admin, $student, $deposit->id)->assertOk();

        $response = $this->void($this->admin, $student, $deposit->id);

        $this->assertAbortGuard($response->assertUnprocessable(), HttpException::class, 'This top-up has already been voided.');
        $this->assertDatabaseCount('wallet_topup_voids', 1);
    }

    public function test_voiding_your_own_top_up_returns_403(): void
    {
        $student = $this->student();
        $deposit = $this->topUp($student, 100, $this->manager);

        $response = $this->void($this->manager, $student, $deposit->id);

        $this->assertAbortGuard(
            $response->assertForbidden(),
            HttpException::class,
            'You cannot void a top-up you performed yourself. Ask another admin, manager, or supervisor to void it.',
        );
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_non_admin_voiding_a_top_up_from_before_today_returns_403(): void
    {
        $this->freezeTime();
        $student = $this->student();
        $deposit = $this->topUp($student, 100, $this->admin);
        $this->stampCreatedAt($deposit, now()->subDay());

        $response = $this->void($this->manager, $student, $deposit->id);

        $this->assertAbortGuard($response->assertForbidden(), HttpException::class, 'This top-up is outside today and can only be voided by an admin.');
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_admin_may_void_a_top_up_from_before_today(): void
    {
        $this->freezeTime();
        $student = $this->student();
        $deposit = $this->topUp($student, 100, $this->manager);
        $this->stampCreatedAt($deposit, now()->subDay());

        $response = $this->void($this->admin, $student, $deposit->id);

        $response->assertOk();
        $this->assertDatabaseHas('wallet_topup_voids', ['wallet_transaction_id' => $deposit->id]);
    }

    public function test_unspent_top_up_is_refunded_in_full(): void
    {
        $student = $this->student();
        $deposit = $this->topUp($student, 100.5, $this->manager);

        $response = $this->void($this->admin, $student, $deposit->id, 'Wrong amount.');

        $response->assertOk()->assertExactJson([
            'message' => 'Top-up voided successfully.',
            'voided_amount' => 100.5,
            'shortfall_amount' => 0.0,
            'new_wallet_balance' => 0.0,
            'new_credit_balance' => 0.0,
        ]);
        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $refund = Transaction::findOrFail($void->refund_wallet_transaction_id);
        $this->assertSame(TransactionType::Withdraw, $refund->type);
        $this->assertSame(-10050, (int) $refund->amount);
        $this->assertSame(collect([
            'source' => 'topup_void_refund',
            'note' => 'Wrong amount.',
            'performed_by' => $this->admin->id,
            'original_wallet_transaction_id' => $deposit->id,
        ])->sortKeys()->all(), collect($refund->meta)->sortKeys()->all());
        $this->assertNull($void->credit_transaction_id);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_partly_spent_top_up_refunds_the_balance_and_charges_the_rest_as_credit(): void
    {
        $student = $this->student();
        $deposit = $this->topUp($student, 100.5, $this->manager);
        $student->withdraw(3025);

        $response = $this->void($this->admin, $student, $deposit->id, 'Wrong amount.');

        $response->assertOk()->assertExactJson([
            'message' => 'Top-up voided successfully.',
            'voided_amount' => 70.25,
            'shortfall_amount' => 30.25,
            'new_wallet_balance' => 0.0,
            'new_credit_balance' => 30.25,
        ]);
        $credit = CreditTransaction::where('student_id', $student->id)->sole();
        $this->assertSame(CreditTransactionType::Charged, $credit->type);
        $this->assertSame('30.25', $credit->amount);
        $this->assertSame("Credit used for voided top-up #{$deposit->id}.", $credit->notes);
        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertSame($credit->id, $void->credit_transaction_id);
        $this->assertNotNull($void->refund_wallet_transaction_id);
        $activity = Activity::where('log_name', 'wallet')->where('description', 'wallet.topup_voided')->sole();
        $this->assertSame(collect([
            'original_amount' => 100.5,
            'voided_amount' => 70.25,
            'shortfall_amount' => 30.25,
            'void_reason' => 'Wrong amount.',
            'original_performer_id' => $this->manager->id,
            'voided_by' => $this->admin->id,
        ])->sortKeys()->all(), $activity->properties->sortKeys()->all());
    }

    public function test_fully_spent_top_up_charges_the_whole_amount_as_credit_without_a_refund(): void
    {
        $student = $this->student();
        $deposit = $this->topUp($student, 100.5, $this->manager);
        $student->withdraw(10050);

        $response = $this->void($this->admin, $student, $deposit->id);

        $response->assertOk()->assertJsonPath('shortfall_amount', 100.5)->assertJsonPath('new_credit_balance', 100.5);
        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertNull($void->refund_wallet_transaction_id);
        $this->assertSame('100.50', CreditTransaction::findOrFail($void->credit_transaction_id)->amount);
    }

    private function assertAbortGuard(TestResponse $response, string $exception, string $message): void
    {
        $response->assertJsonPath('message', $message)->assertJsonPath('exception', $exception);
    }

    /**
     * Same meta shape as WalletController::topUp().
     */
    private function topUp(Student $student, float $amount, User $performer): Transaction
    {
        return $student->deposit((int) round($amount * 100), [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $performer->id,
        ]);
    }

    private function stampCreatedAt(Transaction $transaction, DateTimeInterface $at): void
    {
        DB::table('transactions')->where('id', $transaction->id)->update(['created_at' => $at]);
    }

    private function void(User $user, Student $student, int $transactionId, string $reason = 'Entered twice.'): TestResponse
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$student->id}/wallet/top-ups/{$transactionId}/void", ['reason' => $reason]);
    }

    private function student(): Student
    {
        return Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }
}
