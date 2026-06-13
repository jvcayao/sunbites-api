<?php

namespace Tests\Feature\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Mail\PreRegistrationRejectedMail;
use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asCashier(): static
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function createPreRegWithContact(array $preRegOverrides = [], array $contactOverrides = []): PreRegistration
    {
        $preReg = PreRegistration::factory()->create(array_merge(
            ['branch_id' => $this->branch->id],
            $preRegOverrides
        ));

        PreRegistrationContact::factory()->primary()->create(array_merge(
            ['pre_registration_id' => $preReg->id],
            $contactOverrides
        ));

        return $preReg;
    }

    public function test_list_is_branch_scoped_and_defaults_to_pending(): void
    {
        $this->createPreRegWithContact(); // pending in branch
        $this->createPreRegWithContact(['status' => PreRegistrationStatus::Approved]); // approved in branch
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        PreRegistration::factory()->create(['branch_id' => $otherBranch->id, 'status' => PreRegistrationStatus::Pending]);

        $response = $this->asAdmin()->getJson('/api/v1/pre-registrations');

        $response->assertOk();
        $this->assertCount(1, $response->json('data')); // only pending in this branch
        $this->assertEquals('pending', $response->json('data.0.status'));
    }

    public function test_list_filters_by_status_query_param(): void
    {
        $this->createPreRegWithContact(['status' => PreRegistrationStatus::Approved]);
        $this->createPreRegWithContact(); // pending

        $response = $this->asAdmin()->getJson('/api/v1/pre-registrations?status=approved');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('approved', $response->json('data.0.status'));
    }

    public function test_supervisor_can_list_pre_registrations(): void
    {
        $this->createPreRegWithContact();

        $response = $this->asSupervisor()->getJson('/api/v1/pre-registrations');

        $response->assertOk();
    }

    public function test_cashier_cannot_list_pre_registrations(): void
    {
        $response = $this->asCashier()->getJson('/api/v1/pre-registrations');

        $response->assertForbidden();
    }

    public function test_can_edit_pending_pre_registration(): void
    {
        $preReg = $this->createPreRegWithContact();

        $response = $this->asAdmin()->patchJson("/api/v1/pre-registrations/{$preReg->id}", [
            'first_name' => 'Updated',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('pre_registrations', ['id' => $preReg->id, 'first_name' => 'Updated']);
    }

    public function test_cannot_edit_approved_pre_registration(): void
    {
        $preReg = $this->createPreRegWithContact(['status' => PreRegistrationStatus::Approved]);

        $response = $this->asAdmin()->patchJson("/api/v1/pre-registrations/{$preReg->id}", [
            'first_name' => 'Changed',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_approve_pre_registration(): void
    {
        Mail::fake();
        Notification::fake();

        $preReg = $this->createPreRegWithContact();

        $response = $this->asAdmin()->postJson("/api/v1/pre-registrations/{$preReg->id}/approve");

        $response->assertOk();

        $this->assertDatabaseHas('pre_registrations', [
            'id' => $preReg->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('students', [
            'first_name' => $preReg->first_name,
            'last_name' => $preReg->last_name,
            'branch_id' => $this->branch->id,
        ]);

        $this->assertDatabaseHas('student_contacts', [
            'full_name' => $preReg->contacts->first()->full_name,
        ]);
    }

    public function test_approve_with_duplicate_student_number_returns_422(): void
    {
        Mail::fake();

        $existingStudent = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'SB-2024-001',
        ]);

        $preReg = $this->createPreRegWithContact(['student_number' => 'SB-2024-001']);

        $response = $this->asAdmin()->postJson("/api/v1/pre-registrations/{$preReg->id}/approve");

        $response->assertStatus(422);
    }

    public function test_supervisor_cannot_approve(): void
    {
        $preReg = $this->createPreRegWithContact();

        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$preReg->id}/approve");

        $response->assertForbidden();
    }

    public function test_admin_can_reject_pre_registration(): void
    {
        Mail::fake();

        $preReg = $this->createPreRegWithContact();

        $response = $this->asAdmin()->postJson("/api/v1/pre-registrations/{$preReg->id}/reject", [
            'rejection_reason' => 'Incomplete information provided.',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('pre_registrations', [
            'id' => $preReg->id,
            'status' => 'rejected',
            'rejection_reason' => 'Incomplete information provided.',
        ]);

        Mail::assertQueued(PreRegistrationRejectedMail::class);
    }

    public function test_reject_requires_rejection_reason(): void
    {
        $preReg = $this->createPreRegWithContact();

        $response = $this->asAdmin()->postJson("/api/v1/pre-registrations/{$preReg->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_supervisor_cannot_reject(): void
    {
        $preReg = $this->createPreRegWithContact();

        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$preReg->id}/reject", [
            'rejection_reason' => 'Test',
        ]);

        $response->assertForbidden();
    }

    public function test_supervisor_can_reactivate_expired_pre_registration(): void
    {
        $preReg = $this->createPreRegWithContact(['status' => PreRegistrationStatus::Expired]);

        $response = $this->asSupervisor()->postJson("/api/v1/pre-registrations/{$preReg->id}/reactivate");

        $response->assertOk();

        $this->assertDatabaseHas('pre_registrations', [
            'id' => $preReg->id,
            'status' => 'pending',
        ]);
    }

    public function test_cashier_cannot_reactivate(): void
    {
        $preReg = $this->createPreRegWithContact(['status' => PreRegistrationStatus::Expired]);

        $response = $this->asCashier()->postJson("/api/v1/pre-registrations/{$preReg->id}/reactivate");

        $response->assertForbidden();
    }

    public function test_reactivate_non_expired_record_returns_422(): void
    {
        $preReg = $this->createPreRegWithContact(); // pending

        $response = $this->asAdmin()->postJson("/api/v1/pre-registrations/{$preReg->id}/reactivate");

        $response->assertStatus(422);
    }

    public function test_expire_command_marks_old_pending_records_as_expired(): void
    {
        // pending + expired (past expires_at)
        $expiredPreReg = $this->createPreRegWithContact([
            'status' => PreRegistrationStatus::Pending,
            'expires_at' => now()->subDays(2),
        ]);

        // pending + not yet expired
        $activePreReg = $this->createPreRegWithContact([
            'status' => PreRegistrationStatus::Pending,
            'expires_at' => now()->addDays(10),
        ]);

        Artisan::call('pre-registrations:expire');

        $this->assertDatabaseHas('pre_registrations', [
            'id' => $expiredPreReg->id,
            'status' => 'expired',
        ]);

        $this->assertDatabaseHas('pre_registrations', [
            'id' => $activePreReg->id,
            'status' => 'pending',
        ]);
    }

    public function test_show_includes_duplicate_warning_when_student_number_exists(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'DUP-001',
        ]);

        $preReg = $this->createPreRegWithContact(['student_number' => 'DUP-001']);

        $response = $this->asAdmin()->getJson("/api/v1/pre-registrations/{$preReg->id}");

        $response->assertOk()
            ->assertJsonPath('data.duplicate_warning', true)
            ->assertJsonPath('data.student_number', 'DUP-001');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $preReg = $this->createPreRegWithContact();

        $response = $this->getJson("/api/v1/pre-registrations/{$preReg->id}");

        $response->assertUnauthorized();
    }
}
