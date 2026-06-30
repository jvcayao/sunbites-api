<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SystemConfigurationSeeder::class]);
        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'grade_level' => 'Grade 3',
            'enrollment_type' => 'non_subscription',
            'signatory_name' => 'Maria dela Cruz',
            'contacts' => [
                [
                    'full_name' => 'Maria dela Cruz',
                    'relationship' => 'Mother',
                    'phone' => '09171234567',
                    'address' => '123 Main St',
                    'email' => null,
                    'is_primary' => true,
                ],
            ],
        ], $overrides);
    }

    public function test_creates_pre_registration_when_no_duplicate(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload());

        $response->assertCreated();
        $this->assertDatabaseHas('pre_registrations', [
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'status' => 'pending',
        ]);
    }

    public function test_sets_duplicate_check_passed_at_on_created_record(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $preReg = PreRegistration::first();
        $this->assertNotNull($preReg->duplicate_check_passed_at);
    }

    public function test_blocks_when_student_already_enrolled(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.student.0', fn ($msg) => str_contains($msg, 'already enrolled'));
    }

    public function test_blocks_when_pending_pre_registration_exists(): void
    {
        PreRegistration::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.student.0', fn ($msg) => str_contains($msg, 'already pending'));
    }

    public function test_does_not_block_when_rejected_pre_registration_exists(): void
    {
        PreRegistration::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'status' => 'rejected',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())
            ->assertCreated();
    }

    public function test_name_matching_is_case_insensitive(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'birthday' => '2015-03-15',
        ]);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'first_name' => 'juan',
            'last_name' => 'dela cruz',
        ]))->assertUnprocessable();
    }

    public function test_returns_warning_when_parent_email_exists(): void
    {
        ParentUser::factory()->create(['email' => 'maria@example.com']);

        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'contacts' => [[
                'full_name' => 'Maria dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
                'is_primary' => true,
            ]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('warnings.parent_email_exists', true)
            ->assertJsonPath('warnings.parent_phone_exists', false);
    }

    public function test_returns_warning_when_parent_phone_exists_and_no_email(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('warnings.parent_email_exists', false)
            ->assertJsonPath('warnings.parent_phone_exists', true);
    }

    public function test_sets_parent_email_exists_flag_on_record(): void
    {
        ParentUser::factory()->create(['email' => 'maria@example.com']);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload([
            'contacts' => [[
                'full_name' => 'Maria dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
                'is_primary' => true,
            ]],
        ]))->assertCreated();

        $this->assertTrue(PreRegistration::first()->parent_email_exists);
    }

    public function test_sets_parent_phone_exists_flag_on_record(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $this->assertTrue(PreRegistration::first()->parent_phone_exists);
    }

    public function test_creates_contact_records(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', $this->payload())->assertCreated();

        $this->assertDatabaseHas('pre_registration_contacts', [
            'full_name' => 'Maria dela Cruz',
            'relationship' => 'Mother',
            'is_primary' => true,
        ]);
    }

    public function test_returns_422_when_required_fields_missing(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'first_name', 'last_name', 'birthday', 'grade_level', 'enrollment_type', 'signatory_name', 'contacts']);
    }
}
