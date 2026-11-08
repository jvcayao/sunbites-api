<?php

namespace Tests\Feature\Kitchen;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The announcement list and detail run a fixed number of queries however many announcements
 * or recipients there are (Spec 16 T-9.3, Req 7.1, 7.2, 7.6).
 */
class AnnouncementQueryCountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_listing_runs_the_same_number_of_queries_for_one_or_five_announcements(): void
    {
        $this->announcementTo($this->parents(1));
        $this->queryCount(fn () => $this->asAdmin()->getJson('/api/v1/announcements')->assertOk());
        $oneAnnouncement = $this->queryCount(fn () => $this->asAdmin()->getJson('/api/v1/announcements')->assertOk());
        for ($added = 1; $added <= 4; $added++) {
            $this->announcementTo($this->parents(1));
        }

        $fiveAnnouncements = $this->queryCount(fn () => $this->asAdmin()->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(5, 'data'));

        $this->assertSame($oneAnnouncement, $fiveAnnouncements);
    }

    public function test_detail_runs_the_same_number_of_queries_for_one_or_five_recipients(): void
    {
        $oneRecipient = $this->announcementTo($this->parents(1));
        $fiveRecipients = $this->announcementTo($this->parents(5));
        $this->queryCount(fn () => $this->asAdmin()->getJson("/api/v1/announcements/{$oneRecipient->id}")->assertOk());

        $queriesForOne = $this->queryCount(fn () => $this->asAdmin()->getJson("/api/v1/announcements/{$oneRecipient->id}")->assertOk());
        $queriesForFive = $this->queryCount(fn () => $this->asAdmin()->getJson("/api/v1/announcements/{$fiveRecipients->id}")->assertOk()->assertJsonCount(5, 'data.recipients'));

        $this->assertSame($queriesForOne, $queriesForFive);
    }

    /**
     * Queries run by the callback. Callers make one warm-up request first so the permission
     * cache is filled for every measured request.
     */
    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * @return list<ParentUser>
     */
    private function parents(int $count): array
    {
        return collect(range(1, $count))->map(function (): ParentUser {
            $parent = ParentUser::factory()->create();
            $parent->students()->attach(Student::factory()->create(['branch_id' => $this->branch->id])->id, [
                'linked_at' => now(),
                'linked_by' => $this->admin->id,
                'wallet_alert_threshold' => 0,
            ]);

            return $parent;
        })->all();
    }

    /**
     * @param  list<ParentUser>  $recipients
     */
    private function announcementTo(array $recipients): Announcement
    {
        $announcement = Announcement::create([
            'title' => 'Field trip',
            'message' => 'No canteen service on Friday.',
            'sender_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
            'recipient_type' => 'parents',
            'recipient_count' => count($recipients),
        ]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new AnnouncementNotification($announcement));
        }
        $recipients[0]->unreadNotifications()->update(['read_at' => now()]);

        return $announcement;
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
