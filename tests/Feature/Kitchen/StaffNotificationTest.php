<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function seedNotification(User $user, array $data = [], ?string $readAt = null): string
    {
        $id = Str::uuid()->toString();

        $user->notifications()->create([
            'id' => $id,
            'type' => AnnouncementNotification::class,
            'data' => array_merge([
                'announcement_id' => 1,
                'title' => 'Test notice',
                'message' => 'Test message.',
                'sender_name' => 'Admin User',
                'sent_at' => now(),
            ], $data),
            'read_at' => $readAt,
        ]);

        return $id;
    }

    public function test_unread_count_returns_correct_number(): void
    {
        $this->seedNotification($this->admin);
        $this->seedNotification($this->admin, [], now()->toDateTimeString());

        $response = $this->asAdmin()->getJson('/api/v1/staff/notifications/unread-count');

        $response->assertOk();
        $response->assertJsonPath('count', 1);
    }

    public function test_inbox_returns_only_this_staff_members_notifications(): void
    {
        $other = User::factory()->create();
        $other->assignRole('cashier');
        $other->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->seedNotification($this->admin);
        $this->seedNotification($other);

        $response = $this->asAdmin()->getJson('/api/v1/staff/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_mark_read_sets_read_at(): void
    {
        $id = $this->seedNotification($this->admin);

        $response = $this->asAdmin()->patchJson("/api/v1/staff/notifications/{$id}/read");

        $response->assertOk();
        $this->assertDatabaseHas('notifications', [
            'id' => $id,
        ]);

        $notification = $this->admin->notifications()->find($id);
        $this->assertNotNull($notification->read_at);
    }

    public function test_cannot_mark_another_staff_members_notification_as_read(): void
    {
        $other = User::factory()->create();
        $other->assignRole('cashier');
        $id = $this->seedNotification($other);

        $this->asAdmin()->patchJson("/api/v1/staff/notifications/{$id}/read")->assertNotFound();
    }

    public function test_delete_removes_notification(): void
    {
        $id = $this->seedNotification($this->admin);

        $this->asAdmin()->deleteJson("/api/v1/staff/notifications/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_mark_all_read_sets_read_at_on_all_unread(): void
    {
        $this->seedNotification($this->admin);
        $this->seedNotification($this->admin);

        $this->asAdmin()->postJson('/api/v1/staff/notifications/mark-all-read')->assertOk();

        $this->assertEquals(0, $this->admin->unreadNotifications()->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/staff/notifications')->assertUnauthorized();
    }
}
