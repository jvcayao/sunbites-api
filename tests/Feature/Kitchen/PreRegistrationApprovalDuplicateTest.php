<?php

namespace Tests\Feature\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreRegistrationApprovalDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

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

    private function preRegWithContact(array $preRegOverrides = [], array $contactOverrides = []): PreRegistration
    {
        $preReg = PreRegistration::factory()->pending()->create(array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'student_number' => null,
        ], $preRegOverrides));

        PreRegistrationContact::factory()->primary()->create(array_merge([
            'pre_registration_id' => $preReg->id,
        ], $contactOverrides));

        return $preReg;
    }

    public function test_pre_registration_has_duplicate_check_columns(): void
    {
        $preReg = PreRegistration::factory()->create([
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => true,
            'parent_phone_exists' => false,
        ]);

        $this->assertNotNull($preReg->duplicate_check_passed_at);
        $this->assertTrue($preReg->parent_email_exists);
        $this->assertFalse($preReg->parent_phone_exists);
    }

    public function test_approval_blocked_when_name_birthday_matches_enrolled_student_and_no_student_number(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $preReg = $this->preRegWithContact(['student_number' => null]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_blocked_when_name_birthday_matches_enrolled_student_with_student_number_set(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $preReg = $this->preRegWithContact(['student_number' => 'ABC-2025-001']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_blocked_when_student_number_matches_enrolled_student(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'ABC-2025-001',
        ]);

        $preReg = $this->preRegWithContact([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthday' => '2014-06-20',
            'student_number' => 'ABC-2025-001',
        ]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertUnprocessable();
    }

    public function test_approval_proceeds_when_no_student_number_and_no_name_birthday_match(): void
    {
        Mail::fake();

        $preReg = $this->preRegWithContact(['student_number' => null]);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
        ]);
    }

    public function test_approval_links_student_to_existing_parent_via_email(): void
    {
        Mail::fake();

        $parent = ParentUser::factory()->create(['email' => 'maria@example.com']);

        $preReg = $this->preRegWithContact([], ['email' => 'maria@example.com']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $student = Student::where('first_name', 'Juan')->first();
        $this->assertTrue($parent->students()->where('student_id', $student->id)->exists());
    }

    public function test_approval_creates_new_parent_account_when_email_not_found(): void
    {
        Mail::fake();

        $preReg = $this->preRegWithContact([], ['email' => 'new@example.com']);

        $this->asAdmin()
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('parents', ['email' => 'new@example.com']);
    }

    public function test_approve_succeeds_when_active_branch_differs_from_pre_registration_branch(): void
    {
        Mail::fake();

        // Pre-registration belongs to branch A ($this->branch).
        $preReg = $this->preRegWithContact(['student_number' => null]);

        // Admin also has access to a second branch B, which is the ACTIVE branch on the request.
        $branchB = Branch::factory()->create(['is_active' => true]);
        $this->admin->branches()->attach($branchB->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($this->admin, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branchB->id])
            ->postJson("/api/v1/pre-registrations/{$preReg->id}/approve");

        $response->assertOk();

        // Enrolled into the pre-registration's OWN branch (A), not the active branch (B).
        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'branch_id' => $this->branch->id,
        ]);
        $this->assertDatabaseHas('pre_registrations', [
            'id' => $preReg->id,
            'status' => PreRegistrationStatus::Approved->value,
        ]);
    }
}
