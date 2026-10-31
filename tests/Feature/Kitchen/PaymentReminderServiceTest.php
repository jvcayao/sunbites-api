<?php

namespace Tests\Feature\Kitchen;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use App\Notifications\PaymentReminderNotification;
use App\Services\PaymentReminderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spec 16 T-9.1, design §4.3.7. A school year runs June to March. The clock is frozen at
 * 15 October 2026.
 */
class PaymentReminderServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $sender;

    private PaymentReminderService $reminders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 09:00:00');

        $this->branch = Branch::factory()->create(['is_active' => true]);
        app()->instance('active_branch', $this->branch);
        $this->sender = User::factory()->create();
        $this->reminders = app(PaymentReminderService::class);
    }

    public function test_unpaid_periods_leave_out_months_that_have_not_started(): void
    {
        $this->parentOwing([['june', 2026], ['october', 2026], ['november', 2026], ['january', 2027]]);
        $parent = $this->reminders->parentsWithUnpaidPayments($this->branch)->sole();

        $periods = $this->reminders->unpaidPeriods($parent);

        $this->assertSame(['june_2026', 'october_2026'], $periods->map(fn (array $period): string => $period['school_month'].'_'.$period['year'])->sort()->values()->all());
    }

    public function test_due_date_is_the_end_of_the_payment_month(): void
    {
        $june = $this->reminders->dueDate(SchoolMonth::June, 2026);
        $january = $this->reminders->dueDate(SchoolMonth::January, 2027);

        $this->assertSame(['2026-06-30', '2027-01-31'], [$june->toDateString(), $january->toDateString()]);
    }

    public function test_bell_count_counts_only_parents_with_a_period_not_yet_reminded(): void
    {
        $this->parentOwing([['june', 2026]]);
        $reminded = $this->parentOwing([['june', 2026]]);
        $this->reminderFor($reminded, 'june', 2026);

        $count = $this->reminders->bellCount($this->branch);

        $this->assertSame(1, $count);
    }

    public function test_send_skips_a_parent_whose_periods_were_all_reminded(): void
    {
        Notification::fake([PaymentReminderNotification::class]);
        $parent = $this->parentOwing([['june', 2026]]);
        $reminder = $this->reminderFor($parent, 'june', 2026);

        $result = $this->reminders->send($this->branch, [$parent->id], false, $this->sender);

        $this->assertSame(['sent' => 0, 'skipped' => 1, 'skipped_names' => [$parent->full_name]], $result);
        Notification::assertNothingSent();
        $this->assertSame(1, $reminder->fresh()->send_count);
    }

    public function test_forced_send_reminds_again_and_counts_the_send(): void
    {
        Notification::fake([PaymentReminderNotification::class]);
        $parent = $this->parentOwing([['june', 2026]]);
        $reminder = $this->reminderFor($parent, 'june', 2026);

        $result = $this->reminders->send($this->branch, [$parent->id], true, $this->sender);

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'skipped_names' => []], $result);
        Notification::assertSentTo($parent, PaymentReminderNotification::class);
        $reminder->refresh();
        $this->assertSame(2, $reminder->send_count);
        $this->assertSame($this->sender->id, $reminder->sent_by_user_id);
    }

    /**
     * Req 7.4: existing reminders for every requested parent load in one query.
     */
    public function test_send_looks_up_reminders_once_for_one_or_five_parents(): void
    {
        Notification::fake([PaymentReminderNotification::class]);
        $one = [$this->parentOwing([['june', 2026]])->id];
        $five = collect(range(1, 5))->map(fn (): int => $this->parentOwing([['july', 2026]])->id)->all();

        $lookupsForOne = $this->reminderLookups(fn () => $this->reminders->send($this->branch, $one, false, $this->sender));
        $lookupsForFive = $this->reminderLookups(fn () => $this->reminders->send($this->branch, $five, false, $this->sender));

        $this->assertSame(1, $lookupsForOne);
        $this->assertSame(1, $lookupsForFive);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $periods  school month and school year of each unpaid payment
     */
    private function parentOwing(array $periods): ParentUser
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        foreach ($periods as [$schoolMonth, $year]) {
            StudentMonthlyPayment::factory()->unpaid()->create([
                'student_id' => $student->id,
                'school_month' => $schoolMonth,
                'year' => $year,
                'amount' => 2970,
            ]);
        }

        $parent = ParentUser::factory()->create();
        $parent->students()->attach($student->id, ['linked_at' => now(), 'linked_by' => $this->sender->id, 'wallet_alert_threshold' => 0]);

        return $parent;
    }

    private function reminderFor(ParentUser $parent, string $schoolMonth, int $schoolYear): ParentPaymentReminder
    {
        return ParentPaymentReminder::create([
            'parent_user_id' => $parent->id,
            'branch_id' => $this->branch->id,
            'school_month' => $schoolMonth,
            'school_year' => $schoolYear,
            'sent_at' => now()->subDay(),
            'sent_by_user_id' => $this->sender->id,
            'send_count' => 1,
        ]);
    }

    /**
     * How many queries read the reminders table while the callback runs.
     */
    private function reminderLookups(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->map(fn (array $query): string => str_replace(['`', '"'], '', $query['query']))
            ->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from parent_payment_reminders'))
            ->count();
    }
}
