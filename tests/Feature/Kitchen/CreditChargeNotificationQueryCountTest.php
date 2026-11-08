<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\CreditChargedNotification;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The daily credit-charge debounce asks the database one yes/no question, however many
 * notifications the parent already has today (Spec 16 T-9.5, Req 7.3, 7.6).
 */
class CreditChargeNotificationQueryCountTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_charging_runs_the_same_number_of_queries_with_one_or_five_earlier_notifications(): void
    {
        Notification::fake([CreditChargedNotification::class]);
        $branch = Branch::factory()->create(['is_active' => true]);
        app()->instance('active_branch', $branch);
        $cashier = User::factory()->create();
        $parent = ParentUser::factory()->create();
        $student = Student::factory()->create(['branch_id' => $branch->id]);
        $parent->students()->attach($student->id, ['linked_at' => now(), 'linked_by' => $cashier->id, 'wallet_alert_threshold' => 0]);
        $ledger = app(CreditLedgerService::class);
        $this->earlierChargeNotifications($parent, 1);
        $oneEarlier = $this->queryCount(fn () => $ledger->charge($student, 10, 'order A', $cashier));
        $this->earlierChargeNotifications($parent, 4);

        $fiveEarlier = $this->queryCount(fn () => $ledger->charge($student, 10, 'order B', $cashier));

        $this->assertSame($oneEarlier, $fiveEarlier);
        Notification::assertSentToTimes($parent, CreditChargedNotification::class, 2);
    }

    /**
     * Charge notifications sent today for other students, so they never debounce this one.
     */
    private function earlierChargeNotifications(ParentUser $parent, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $parent->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => CreditChargedNotification::class,
                'data' => ['student_id' => 999000 + $i],
            ]);
        }
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
