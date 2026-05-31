<?php

namespace Tests\Feature\Portal;

use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentProvisioningService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentProvisioningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentProvisioningService $service;

    private Branch $branch;

    private Student $student;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->service = app(ParentProvisioningService::class);
        $this->branch = Branch::factory()->create();
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
        // Create a staff user so FK on parent_student.linked_by is satisfiable
        $this->staffUser = User::factory()->create();
    }

    public function test_provision_creates_new_parent_and_sends_welcome_mail(): void
    {
        Mail::fake();

        $this->service->provision('new@example.com', 'Maria Dela Cruz', $this->student->id, $this->staffUser->id);

        $this->assertDatabaseHas('parents', ['email' => 'new@example.com', 'first_name' => 'Maria', 'email_verified_at' => null]);
        $this->assertDatabaseHas('parent_student', ['student_id' => $this->student->id]);

        Mail::assertQueued(ParentWelcomeMail::class, fn ($mail) => $mail->hasTo('new@example.com'));
    }

    public function test_provision_does_not_send_mail_for_existing_parent(): void
    {
        Mail::fake();

        $existing = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'existing@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->service->provision('existing@example.com', 'Maria Dela Cruz', $student2->id, $this->staffUser->id);

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('parent_student', ['parent_id' => $existing->id, 'student_id' => $student2->id]);
    }

    public function test_provision_is_idempotent_for_same_student_link(): void
    {
        Mail::fake();

        $this->service->provision('idempotent@example.com', 'Test Parent', $this->student->id, $this->staffUser->id);
        $this->service->provision('idempotent@example.com', 'Test Parent', $this->student->id, $this->staffUser->id);

        $this->assertDatabaseCount('parent_student', 1);
        Mail::assertQueued(ParentWelcomeMail::class, 1);
    }

    public function test_provision_links_same_parent_to_multiple_students(): void
    {
        Mail::fake();

        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->service->provision('parent@example.com', 'Multi Parent', $this->student->id, $this->staffUser->id);
        $this->service->provision('parent@example.com', 'Multi Parent', $student2->id, $this->staffUser->id);

        $parent = ParentUser::where('email', 'parent@example.com')->first();
        $this->assertCount(2, $parent->students);
        Mail::assertQueued(ParentWelcomeMail::class, 1);
    }

    public function test_detach_student_removes_pivot_link(): void
    {
        $this->service->provision('detach@example.com', 'Detach Parent', $this->student->id, $this->staffUser->id);

        $parent = ParentUser::where('email', 'detach@example.com')->first();
        $this->assertDatabaseHas('parent_student', ['parent_id' => $parent->id, 'student_id' => $this->student->id]);

        $this->service->detachStudent('detach@example.com', $this->student->id);

        $this->assertDatabaseMissing('parent_student', ['parent_id' => $parent->id, 'student_id' => $this->student->id]);
    }

    public function test_detach_student_with_nonexistent_email_does_nothing(): void
    {
        $this->service->detachStudent('nobody@example.com', $this->student->id);

        // No exception thrown — graceful no-op
        $this->assertTrue(true);
    }

    public function test_enrollment_creates_parent_via_provisioning_when_contact_has_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $user->assignRole('manager');
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($user, ['staff']);

        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/enrollment', [
                'branch_id' => $this->branch->id,
                'student_number' => 'TEST-2025-PROV',
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'grade_level' => 'Grade 3',
                'birthday' => '2015-01-15',
                'student_type' => 'non_subscription',
                'contacts' => [[
                    'full_name' => 'Maria Dela Cruz',
                    'relationship' => 'Mother',
                    'phone' => '09171234567',
                    'address' => '123 Main St',
                    'email' => 'provision-test@example.com',
                ]],
                'signature' => 'parent-signature',
                'permission_meals' => true,
                'permission_dietary' => true,
            ])->assertCreated();

        $this->assertDatabaseHas('parents', ['email' => 'provision-test@example.com']);
        Mail::assertQueued(ParentWelcomeMail::class);
    }

    public function test_enrollment_skips_provisioning_when_contact_has_no_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $user->assignRole('manager');
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($user, ['staff']);

        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/enrollment', [
                'branch_id' => $this->branch->id,
                'student_number' => 'TEST-2025-NOEMAIL',
                'first_name' => 'Pedro',
                'last_name' => 'Penduko',
                'grade_level' => 'Grade 1',
                'birthday' => '2017-03-20',
                'student_type' => 'non_subscription',
                'contacts' => [[
                    'full_name' => 'Rosa Penduko',
                    'relationship' => 'Mother',
                    'phone' => '09181234567',
                    'address' => '456 Side St',
                    'email' => null,
                ]],
                'signature' => 'parent-signature',
                'permission_meals' => true,
                'permission_dietary' => true,
            ])->assertCreated();

        Mail::assertNothingQueued();
    }
}
