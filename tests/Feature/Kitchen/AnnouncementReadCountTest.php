<?php

namespace Tests\Feature\Kitchen;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\CreditChargedNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Read counts and recipients come from the notifications' JSON `announcement_id`, which MySQL
 * and SQLite extract differently, so the values are pinned here (Spec 16 T-9 follow-up).
 */
class AnnouncementReadCountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    private Announcement $fieldTrip;

    private Announcement $holiday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $ana = $this->parentNamed('Ana', 'Reyes');
        $ben = $this->parentNamed('Ben', 'Santos');
        $carla = $this->parentNamed('Carla', 'Lim');
        $dina = $this->parentNamed('Dina', 'Cruz');
        $eli = $this->parentNamed('Eli', 'Tan');

        $this->fieldTrip = $this->announcementTo([$ana, $ben, $carla]);
        $this->holiday = $this->announcementTo([$dina, $eli]);
        foreach ([$ana, $ben] as $reader) {
            $reader->notifications()->where('type', AnnouncementNotification::class)->update(['read_at' => now()]);
        }
        $this->readCreditChargeNotification($dina);
    }

    public function test_listing_counts_only_the_read_notifications_of_each_announcement(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/announcements');

        $response->assertOk();
        $this->assertSame(
            [$this->fieldTrip->id => 2, $this->holiday->id => 0],
            collect($response->json('data'))->pluck('read_count', 'id')->sortKeys()->all(),
        );
    }

    public function test_detail_lists_each_recipient_of_the_announcement_with_their_read_state(): void
    {
        $response = $this->asAdmin()->getJson("/api/v1/announcements/{$this->fieldTrip->id}");

        $response->assertOk();
        $this->assertSame(
            ['Ana Reyes' => true, 'Ben Santos' => true, 'Carla Lim' => false],
            collect($response->json('data.recipients'))
                ->mapWithKeys(fn (array $recipient): array => [$recipient['name'] => $recipient['read_at'] !== null])
                ->sortKeys()
                ->all(),
        );
    }

    private function parentNamed(string $firstName, string $lastName): ParentUser
    {
        $parent = ParentUser::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);
        $parent->students()->attach(Student::factory()->create(['branch_id' => $this->branch->id])->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        return $parent;
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

        return $announcement;
    }

    /**
     * A read notification with no `announcement_id`, which no announcement may count.
     */
    private function readCreditChargeNotification(ParentUser $parent): void
    {
        $parent->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => CreditChargedNotification::class,
            'data' => ['student_id' => $parent->students()->value('students.id')],
            'read_at' => now(),
        ]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
