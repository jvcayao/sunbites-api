<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReminderTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
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

    private function seedPayment(string $schoolMonth = 'june', int $year = 2026): void
    {
        StudentMonthlyPayment::create([
            'student_id' => $this->student->id,
            'school_month' => $schoolMonth,
            'year' => $year,
            'amount' => 2970,
            'status' => 'unpaid',
        ]);
    }

    // -------------------------------------------------------------------------
    // bellCount
    // -------------------------------------------------------------------------

    public function test_bell_count_returns_zero_when_outside_reminder_window(): void
    {
        // Set "now" to a date where now+14 days lands in April (not a school month)
        Carbon::setTestNow(Carbon::create(2027, 4, 1));

        $response = $this->asAdmin()->getJson('/api/v1/reminders/bell-count');

        $response->assertOk()->assertJson(['count' => 0, 'school_month' => null, 'school_year' => null]);
    }

    public function test_bell_count_returns_correct_count_when_window_is_open(): void
    {
        // now = June 1; +14 days = June 15 → school month = june
        Carbon::setTestNow(Carbon::create(2026, 6, 1));
        $this->seedPayment('june', 2026);

        $response = $this->asAdmin()->getJson('/api/v1/reminders/bell-count');

        $response->assertOk()
            ->assertJson([
                'count' => 1,
                'school_month' => 'june',
                'school_year' => 2026,
            ]);
    }

    public function test_bell_count_excludes_already_notified_parents(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1));
        $this->seedPayment('june', 2026);

        ParentPaymentReminder::create([
            'parent_user_id' => $this->parent->id,
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'school_year' => 2026,
            'sent_at' => now(),
            'sent_by_user_id' => $this->admin->id,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reminders/bell-count');

        $response->assertOk()->assertJson(['count' => 0]);
    }

    // -------------------------------------------------------------------------
    // eligibleParents
    // -------------------------------------------------------------------------

    public function test_eligible_parents_list_is_branch_scoped(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1));

        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->subscription()->create(['branch_id' => $otherBranch->id]);
        $otherParent = ParentUser::create([
            'first_name' => 'Other',
            'last_name' => 'Parent',
            'email' => 'other@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $otherParent->students()->attach($otherStudent->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reminders/eligible-parents');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->parent->id));
        $this->assertFalse($ids->contains($otherParent->id));
    }

    // -------------------------------------------------------------------------
    // send
    // -------------------------------------------------------------------------

    public function test_send_creates_reminder_records_and_notifications(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 1));
        $this->seedPayment('june', 2026);

        $response = $this->asAdmin()->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->parent->id],
        ]);

        $response->assertOk()->assertJson(['sent' => 1, 'skipped' => 0]);

        $this->assertDatabaseHas('parent_payment_reminders', [
            'parent_user_id' => $this->parent->id,
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'school_year' => 2026,
        ]);

        Notification::assertSentTo($this->parent, PaymentReminderNotification::class);
    }

    public function test_send_skips_already_notified_parents(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 1));
        $this->seedPayment('june', 2026);

        ParentPaymentReminder::create([
            'parent_user_id' => $this->parent->id,
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'school_year' => 2026,
            'sent_at' => now()->subDay(),
            'sent_by_user_id' => $this->admin->id,
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->parent->id],
        ]);

        $response->assertOk()->assertJson(['sent' => 0, 'skipped' => 1]);
        Notification::assertNothingSent();
    }

    public function test_send_with_force_true_resends_to_already_notified_parents(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::create(2026, 6, 1));
        $this->seedPayment('june', 2026);

        ParentPaymentReminder::create([
            'parent_user_id' => $this->parent->id,
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'school_year' => 2026,
            'sent_at' => now()->subDay(),
            'sent_by_user_id' => $this->admin->id,
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->parent->id],
            'force' => true,
        ]);

        $response->assertOk()->assertJson(['sent' => 1, 'skipped' => 0]);
        Notification::assertSentTo($this->parent, PaymentReminderNotification::class);
    }

    public function test_send_requires_parent_ids(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/reminders/send', []);

        $response->assertUnprocessable();
    }

    public function test_cashier_cannot_send_reminders(): void
    {
        $response = $this->asUserWithRole('cashier')->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->parent->id],
        ]);

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_parent_with_subscription_students_and_payments(): void
    {
        $this->seedPayment('june', 2026);

        $response = $this->asAdmin()->getJson("/api/v1/reminders/parents/{$this->parent->id}");

        $response->assertOk()
            ->assertJsonPath('id', $this->parent->id)
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.full_name', $this->student->full_name);
    }

    public function test_show_returns_403_when_parent_has_no_students_in_active_branch(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->subscription()->create(['branch_id' => $otherBranch->id]);

        $isolatedParent = ParentUser::create([
            'first_name' => 'Isolated',
            'last_name' => 'Parent',
            'email' => 'isolated@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $isolatedParent->students()->attach($otherStudent->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reminders/parents/{$isolatedParent->id}");

        $response->assertForbidden();
    }

    public function test_cashier_cannot_access_eligible_parents(): void
    {
        $response = $this->asUserWithRole('cashier')->getJson('/api/v1/reminders/eligible-parents');

        $response->assertForbidden();
    }
}
