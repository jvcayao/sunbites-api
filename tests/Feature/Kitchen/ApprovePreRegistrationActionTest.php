<?php

namespace Tests\Feature\Kitchen;

use App\Mail\PreRegistrationApprovedMail;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Approval keeps its contact, provisioning, duplicate and mail behaviour (Spec 16 T-8.1,
 * design §4.3.5). Debug mode is on so the mechanism is visible (hazard H3): each duplicate
 * guard is an `abort_if()`, which adds the exception details.
 */
class ApprovePreRegistrationActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_only_the_primary_contact_with_an_email_gets_a_portal_account(): void
    {
        Mail::fake();
        $preRegistration = $this->preRegistration();
        $this->contact($preRegistration, ['is_primary' => true, 'email' => 'primary@example.com']);
        $this->contact($preRegistration, ['email' => 'second@example.com']);

        $response = $this->approve($preRegistration);

        $response->assertOk();
        $this->assertSame(['primary@example.com'], ParentUser::orderBy('email')->pluck('email')->all());
    }

    public function test_every_contact_is_copied_to_the_student(): void
    {
        Mail::fake();
        $preRegistration = $this->preRegistration();
        $this->contact($preRegistration, ['full_name' => 'Maria', 'is_primary' => true, 'email' => 'maria@example.com']);
        $this->contact($preRegistration, ['full_name' => 'Jose', 'email' => null]);

        $response = $this->approve($preRegistration);

        $response->assertOk();
        $this->assertSame(
            ['Maria' => true, 'Jose' => false],
            Student::findOrFail($response->json('data.id'))->contacts()->orderBy('id')->get()
                ->mapWithKeys(fn ($contact) => [$contact->full_name => $contact->is_primary])->all(),
        );
    }

    public function test_name_and_birthday_already_enrolled_returns_422(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Juan', 'last_name' => 'dela Cruz', 'birthday' => '2015-03-15']);
        $preRegistration = $this->preRegistration();

        $response = $this->approve($preRegistration);

        $this->assertAbortGuard(
            $response->assertUnprocessable(),
            'A student with this name and birthday is already enrolled. Please resolve the duplicate before approving.',
        );
        $this->assertSame('pending', $preRegistration->fresh()->status->value);
    }

    public function test_student_number_already_used_returns_422(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'SB-2026-001']);
        $preRegistration = $this->preRegistration(['student_number' => 'SB-2026-001']);

        $response = $this->approve($preRegistration);

        $this->assertAbortGuard(
            $response->assertUnprocessable(),
            'A student with this student number already exists. Please resolve the duplicate before approving.',
        );
        $this->assertSame('pending', $preRegistration->fresh()->status->value);
    }

    public function test_approved_mail_is_queued_to_the_primary_contact(): void
    {
        Mail::fake();
        $preRegistration = $this->preRegistration();
        $this->contact($preRegistration, ['email' => 'other@example.com']);
        $this->contact($preRegistration, ['is_primary' => true, 'email' => 'primary@example.com']);

        $response = $this->approve($preRegistration);

        $response->assertOk();
        Mail::assertQueued(PreRegistrationApprovedMail::class, fn (PreRegistrationApprovedMail $mail): bool => $mail->hasTo('primary@example.com') && count($mail->to) === 1);
    }

    public function test_approved_mail_falls_back_to_the_first_contact_without_a_primary(): void
    {
        Mail::fake();
        $preRegistration = $this->preRegistration();
        $this->contact($preRegistration, ['email' => 'first@example.com']);
        $this->contact($preRegistration, ['email' => 'second@example.com']);

        $response = $this->approve($preRegistration);

        $response->assertOk();
        Mail::assertQueued(PreRegistrationApprovedMail::class, fn (PreRegistrationApprovedMail $mail): bool => $mail->hasTo('first@example.com') && count($mail->to) === 1);
    }

    private function assertAbortGuard(TestResponse $response, string $message): void
    {
        $response->assertJsonPath('message', $message)->assertJsonPath('exception', HttpException::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function preRegistration(array $attributes = []): PreRegistration
    {
        return PreRegistration::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'student_number' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(PreRegistration $preRegistration, array $attributes): PreRegistrationContact
    {
        return PreRegistrationContact::factory()->create(['pre_registration_id' => $preRegistration->id, ...$attributes]);
    }

    private function approve(PreRegistration $preRegistration): TestResponse
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/pre-registrations/{$preRegistration->id}/approve");
    }
}
