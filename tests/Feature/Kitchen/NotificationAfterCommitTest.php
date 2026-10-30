<?php

namespace Tests\Feature\Kitchen;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Queued notifications go out only when the surrounding transaction commits (Spec 16 T-14.1,
 * design §4.7). No queue, notification or mail fakes: the real sync queue defers after-commit
 * jobs through `db.transactions`.
 */
class NotificationAfterCommitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Student $student;

    private ParentUser $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->cashier = User::factory()->create();
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $this->parent = ParentUser::factory()->create();
        $this->parent->students()->attach($this->student->id, ['linked_at' => now(), 'linked_by' => $this->cashier->id, 'wallet_alert_threshold' => 0]);
    }

    /**
     * NotificationSent is observed rather than the `notifications` table: the sync queue runs a
     * job that is not deferred inside the open transaction, so its database row would roll back
     * with it either way. Events are not rolled back.
     */
    public function test_a_rolled_back_announcement_sends_no_notification(): void
    {
        Event::fake([NotificationSent::class]);

        $this->rollBack(fn () => $this->parent->notify(new AnnouncementNotification($this->announcement())));

        Event::assertNotDispatched(NotificationSent::class);
    }

    public function test_a_committed_announcement_is_sent_to_the_parent(): void
    {
        Event::fake([NotificationSent::class]);

        DB::transaction(fn () => $this->parent->notify(new AnnouncementNotification($this->announcement())));

        Event::assertDispatched(
            NotificationSent::class,
            fn (NotificationSent $event): bool => $event->notification instanceof AnnouncementNotification && $event->notifiable->is($this->parent),
        );
    }

    /**
     * Pin: CreditChargedNotification (like CreditSettledNotification) already sets
     * `$this->afterCommit = true` in its constructor, so this held before T-14 as well.
     */
    public function test_a_rolled_back_credit_charge_sends_no_notification(): void
    {
        $this->rollBack(fn () => app(CreditLedgerService::class)->charge($this->student, 25.0, 'order R-1', $this->cashier));

        $this->assertSame(0, $this->parentNotificationCount());
    }

    /**
     * Pin: the committed charge writes its database notification.
     */
    public function test_a_committed_credit_charge_sends_one_notification(): void
    {
        DB::transaction(fn () => app(CreditLedgerService::class)->charge($this->student, 25.0, 'order R-2', $this->cashier));

        $this->assertSame(1, $this->parentNotificationCount());
    }

    private function announcement(): Announcement
    {
        return Announcement::create([
            'title' => 'Field trip',
            'message' => 'No canteen service on Friday.',
            'sender_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
            'recipient_type' => 'parents',
            'recipient_count' => 1,
        ]);
    }

    /**
     * Runs the callback inside a transaction that is then rolled back by an exception.
     */
    private function rollBack(callable $callback): void
    {
        try {
            DB::transaction(function () use ($callback): void {
                $callback();

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            // The rollback is the point of the test.
        }
    }

    private function parentNotificationCount(): int
    {
        return DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('notifiable_type', ParentUser::class)->count();
    }
}
