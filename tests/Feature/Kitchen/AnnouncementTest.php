<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    private ParentUser $parent;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asUserWithRole(string $role): static
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_admin_can_send_announcement_to_parents(): void
    {
        Notification::fake();

        $response = $this->asAdmin()->postJson('/api/v1/announcements', [
            'title' => 'Canteen notice',
            'message' => 'The canteen will be closed tomorrow.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('announcements', [
            'title' => 'Canteen notice',
            'recipient_type' => 'parents',
            'recipient_count' => 1,
        ]);

        Notification::assertSentTo($this->parent, AnnouncementNotification::class);
    }

    public function test_admin_can_send_announcement_to_staff(): void
    {
        Notification::fake();

        $staffMember = User::factory()->create();
        $staffMember->assignRole('cashier');
        $staffMember->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->asAdmin()->postJson('/api/v1/announcements', [
            'message' => 'Staff meeting at 4pm.',
            'recipient_type' => 'staff',
            'recipient_ids' => [$staffMember->id],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('announcements', [
            'recipient_type' => 'staff',
            'recipient_count' => 1,
        ]);

        Notification::assertSentTo($staffMember, AnnouncementNotification::class);
    }

    public function test_cashier_cannot_send_announcement(): void
    {
        Notification::fake();

        $this->asUserWithRole('cashier')->postJson('/api/v1/announcements', [
            'message' => 'Hello.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id],
        ])->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_recipient_ids_not_in_active_branch_are_silently_skipped(): void
    {
        Notification::fake();

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherParent = ParentUser::create([
            'first_name' => 'Other',
            'last_name' => 'Parent',
            'email' => 'other@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $otherStudent = Student::factory()->subscription()->create(['branch_id' => $otherBranch->id]);
        $otherParent->students()->attach($otherStudent->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/announcements', [
            'message' => 'Hello.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id, $otherParent->id],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('announcements', ['recipient_count' => 1]);

        Notification::assertSentTo($this->parent, AnnouncementNotification::class);
        Notification::assertNotSentTo($otherParent, AnnouncementNotification::class);
    }

    public function test_announcements_list_is_branch_scoped(): void
    {
        Notification::fake();

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $otherAdmin->branches()->attach($otherBranch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($otherAdmin, ['staff']);
        $this->withHeaders(['X-Branch-Id' => $otherBranch->id])
            ->postJson('/api/v1/announcements', [
                'message' => 'Other branch announcement.',
                'recipient_type' => 'parents',
                'recipient_ids' => [$this->parent->id],
            ]);

        $this->asAdmin()->postJson('/api/v1/announcements', [
            'message' => 'Active branch announcement.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id],
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/announcements');

        $response->assertOk();

        $messages = collect($response->json('data'))->pluck('message_preview')->toArray();
        $this->assertContains('Active branch announcement.', $messages);
        $this->assertNotContains('Other branch announcement.', $messages);
    }

    public function test_show_returns_full_announcement_with_recipient_read_status(): void
    {
        Notification::fake();

        $response = $this->asAdmin()->postJson('/api/v1/announcements', [
            'message' => 'Full detail test.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id],
        ]);

        $announcementId = $response->json('id');

        $showResponse = $this->asAdmin()->getJson("/api/v1/announcements/{$announcementId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.message', 'Full detail test.');
        $showResponse->assertJsonPath('data.recipient_type', 'parents');
    }

    public function test_show_returns_404_for_announcement_in_another_branch(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $otherAdmin->branches()->attach($otherBranch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Notification::fake();

        Sanctum::actingAs($otherAdmin, ['staff']);
        $otherResponse = $this->withHeaders(['X-Branch-Id' => $otherBranch->id])
            ->postJson('/api/v1/announcements', [
                'message' => 'Secret.',
                'recipient_type' => 'parents',
                'recipient_ids' => [$this->parent->id],
            ]);

        $announcementId = $otherResponse->json('id');

        $this->asAdmin()->getJson("/api/v1/announcements/{$announcementId}")->assertNotFound();
    }

    public function test_announcement_requires_message(): void
    {
        $this->asAdmin()->postJson('/api/v1/announcements', [
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->parent->id],
        ])->assertUnprocessable();
    }

    public function test_announcement_requires_recipient_ids(): void
    {
        $this->asAdmin()->postJson('/api/v1/announcements', [
            'message' => 'Hello.',
            'recipient_type' => 'parents',
            'recipient_ids' => [],
        ])->assertUnprocessable();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/announcements')->assertUnauthorized();
    }
}
