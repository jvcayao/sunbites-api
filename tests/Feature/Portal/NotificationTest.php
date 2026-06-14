<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $staff = User::factory()->create();

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($student->id, [
            'linked_at' => now(),
            'linked_by' => $staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    private function seedNotification(array $data = [], bool $read = false): string
    {
        $id = Str::uuid()->toString();

        $this->parent->notifications()->create([
            'id' => $id,
            'type' => 'App\Notifications\PaymentReminderNotification',
            'notifiable_type' => ParentUser::class,
            'notifiable_id' => $this->parent->id,
            'data' => array_merge([
                'school_month' => 'august',
                'school_year' => 2026,
                'due_date' => '2026-08-31',
                'students' => [],
                'total_amount' => 2970,
            ], $data),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_parent_can_list_notifications(): void
    {
        $this->seedNotification();
        $this->seedNotification(['school_month' => 'september']);

        $response = $this->asParent()->getJson('/api/v1/portal/notifications');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_returns_only_this_parents_notifications(): void
    {
        $other = ParentUser::create([
            'first_name' => 'Other',
            'last_name' => 'Parent',
            'email' => 'other@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->seedNotification();

        $other->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\PaymentReminderNotification',
            'notifiable_type' => ParentUser::class,
            'notifiable_id' => $other->id,
            'data' => ['school_month' => 'july'],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asParent()->getJson('/api/v1/portal/notifications');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unread_count_reflects_unread_state(): void
    {
        $this->seedNotification();
        $this->seedNotification(['school_month' => 'september'], read: true);

        $response = $this->asParent()->getJson('/api/v1/portal/notifications/unread-count');

        $response->assertOk()->assertJson(['count' => 1]);
    }

    public function test_parent_can_mark_notification_as_read(): void
    {
        $id = $this->seedNotification();

        $response = $this->asParent()->patchJson("/api/v1/portal/notifications/{$id}/read");

        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'id' => $id,
        ]);

        $notification = $this->parent->notifications()->find($id);
        $this->assertNotNull($notification->read_at);
    }

    public function test_parent_cannot_mark_another_parents_notification_as_read(): void
    {
        $other = ParentUser::create([
            'first_name' => 'Other',
            'last_name' => 'Parent',
            'email' => 'other@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $id = Str::uuid()->toString();
        $other->notifications()->create([
            'id' => $id,
            'type' => 'App\Notifications\PaymentReminderNotification',
            'notifiable_type' => ParentUser::class,
            'notifiable_id' => $other->id,
            'data' => [],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asParent()->patchJson("/api/v1/portal/notifications/{$id}/read");

        $response->assertNotFound();
    }

    public function test_parent_can_delete_notification(): void
    {
        $id = $this->seedNotification();

        $response = $this->asParent()->deleteJson("/api/v1/portal/notifications/{$id}");

        $response->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_parent_can_mark_all_notifications_as_read(): void
    {
        $this->seedNotification();
        $this->seedNotification(['school_month' => 'september']);

        $response = $this->asParent()->postJson('/api/v1/portal/notifications/mark-all-read');

        $response->assertOk();
        $this->assertSame(0, $this->parent->unreadNotifications()->count());
    }

    public function test_parent_can_clear_all_notifications(): void
    {
        $this->seedNotification();
        $this->seedNotification(['school_month' => 'september']);

        $response = $this->asParent()->deleteJson('/api/v1/portal/notifications');

        $response->assertOk();
        $this->assertSame(0, $this->parent->notifications()->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/portal/notifications');

        $response->assertUnauthorized();
    }
}
