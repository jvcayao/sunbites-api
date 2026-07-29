<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\CreditChargedNotification;
use App\Notifications\CreditSettledNotification;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    private User $staff;

    private ParentUser $firstParent;

    private ParentUser $secondParent;

    private CreditLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'credit_balance' => 0,
        ]);

        $this->firstParent = $this->makeParent('first@example.com');
        $this->secondParent = $this->makeParent('second@example.com');

        foreach ([$this->firstParent, $this->secondParent] as $parent) {
            $parent->students()->attach($this->student->id, [
                'linked_at' => now(),
                'linked_by' => $this->staff->id,
                'wallet_alert_threshold' => 0,
            ]);
        }

        $this->ledger = app(CreditLedgerService::class);
    }

    private function makeParent(string $email): ParentUser
    {
        return ParentUser::create([
            'first_name' => 'Parent',
            'last_name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_a_credit_charge_notifies_every_linked_parent(): void
    {
        Notification::fake();

        $this->ledger->charge($this->student, 25.00, 'R-N1', $this->staff);

        Notification::assertSentTo($this->firstParent, CreditChargedNotification::class);
        Notification::assertSentTo($this->secondParent, CreditChargedNotification::class);
        Notification::assertCount(2);
    }

    public function test_the_charge_payload_carries_the_student_amount_and_outstanding_balance(): void
    {
        Notification::fake();

        $this->ledger->charge($this->student, 25.00, 'R-N2', $this->staff);

        Notification::assertSentTo(
            $this->firstParent,
            CreditChargedNotification::class,
            function (CreditChargedNotification $notification) {
                $payload = $notification->toDatabase($this->firstParent);

                return $payload['student_id'] === $this->student->id
                    && $payload['student_name'] === $this->student->full_name
                    && $payload['amount'] === 25.0
                    && $payload['outstanding_balance'] === 25.0;
            }
        );
    }

    public function test_a_second_charge_on_the_same_day_is_debounced(): void
    {
        $this->ledger->charge($this->student, 25.00, 'R-D1', $this->staff);
        $this->ledger->charge($this->student, 30.00, 'R-D2', $this->staff);
        $this->ledger->charge($this->student, 10.00, 'R-D3', $this->staff);

        $this->assertSame(
            1,
            $this->firstParent->notifications()->where('type', CreditChargedNotification::class)->count(),
            'A student can borrow several times a day; parents get one message stating the running total.'
        );
    }

    public function test_a_charge_for_a_different_student_still_notifies_on_the_same_day(): void
    {
        $sibling = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 0]);
        $this->firstParent->students()->attach($sibling->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);

        $this->ledger->charge($this->student, 25.00, 'R-S1', $this->staff);
        $this->ledger->charge($sibling, 15.00, 'R-S2', $this->staff);

        $this->assertSame(
            2,
            $this->firstParent->notifications()->where('type', CreditChargedNotification::class)->count(),
            'The debounce is per student, not per parent.'
        );
    }

    public function test_a_settlement_notifies_every_linked_parent(): void
    {
        $this->ledger->charge($this->student, 100.00, 'R-SET', $this->staff);

        Notification::fake();

        $this->ledger->settleWithPayment($this->student, 100.00, CreditSettlementMethod::Cash, null, null, $this->staff);

        Notification::assertSentTo($this->firstParent, CreditSettledNotification::class);
        Notification::assertSentTo($this->secondParent, CreditSettledNotification::class);
    }

    public function test_settlements_are_not_debounced(): void
    {
        $this->ledger->charge($this->student, 100.00, 'R-ND', $this->staff);

        $this->ledger->settleWithPayment($this->student, 40.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        $this->ledger->settleWithPayment($this->student, 60.00, CreditSettlementMethod::Cash, null, null, $this->staff);

        $this->assertSame(
            2,
            $this->firstParent->notifications()->where('type', CreditSettledNotification::class)->count(),
            'Every payment deserves its own confirmation.'
        );
    }

    public function test_a_waive_notifies_with_the_waived_flag_set(): void
    {
        $this->ledger->charge($this->student, 100.00, 'R-W', $this->staff);

        Notification::fake();

        $this->ledger->waive($this->student, 100.00, 'Written off after graduation.', $this->staff);

        Notification::assertSentTo(
            $this->firstParent,
            CreditSettledNotification::class,
            fn (CreditSettledNotification $notification) => $notification->wasWaived === true
        );
    }

    public function test_a_waive_notification_never_carries_the_reason(): void
    {
        $reason = 'Family hardship; confidential staff note.';

        $this->ledger->charge($this->student, 100.00, 'R-WR', $this->staff);
        $this->ledger->waive($this->student, 100.00, $reason, $this->staff);

        $notification = $this->firstParent->notifications()
            ->where('type', CreditSettledNotification::class)
            ->sole();

        $this->assertStringNotContainsString($reason, json_encode($notification->data));
    }

    public function test_a_settlement_payload_reports_the_remaining_balance(): void
    {
        $this->ledger->charge($this->student, 100.00, 'R-REM', $this->staff);
        $this->ledger->settleWithPayment($this->student, 40.00, CreditSettlementMethod::Cash, null, null, $this->staff);

        $notification = $this->firstParent->notifications()
            ->where('type', CreditSettledNotification::class)
            ->latest()
            ->first();

        $this->assertEquals(40.0, $notification->data['amount']);
        $this->assertEquals(60.0, $notification->data['outstanding_balance']);
    }

    public function test_a_rejected_settlement_notifies_nobody(): void
    {
        $this->student->update(['credit_balance' => 50.00]);

        Notification::fake();

        try {
            $this->ledger->settleWithPayment($this->student, 500.00, CreditSettlementMethod::Cash, null, null, $this->staff);
        } catch (HttpException) {
            // expected
        }

        Notification::assertNothingSent();
    }

    public function test_an_insufficient_wallet_settlement_notifies_nobody(): void
    {
        $this->student->update(['credit_balance' => 150.00]);
        $this->student->deposit(1000);

        Notification::fake();

        try {
            $this->ledger->settleFromWallet($this->student, 150.00, null, $this->staff);
        } catch (HttpException) {
            // expected
        }

        Notification::assertNothingSent();
    }

    public function test_a_student_with_no_linked_parents_notifies_nobody(): void
    {
        $orphan = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 0]);

        Notification::fake();

        $this->ledger->charge($orphan, 20.00, 'R-ORPHAN', $this->staff);

        Notification::assertNothingSent();
    }

    public function test_notifications_are_persisted_to_the_database_channel(): void
    {
        $this->ledger->charge($this->student, 25.00, 'R-DB', $this->staff);

        $this->assertDatabaseHas('notifications', [
            'type' => CreditChargedNotification::class,
            'notifiable_type' => ParentUser::class,
            'notifiable_id' => $this->firstParent->id,
        ]);
    }
}
