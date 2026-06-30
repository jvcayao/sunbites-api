<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ], $overrides);
    }

    public function test_returns_no_duplicate_when_no_match(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
            'parent' => ['email_exists' => false, 'phone_exists' => false],
        ]);
    }

    public function test_detects_enrolled_student_duplicate(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'enrolled'],
        ]);
    }

    public function test_detects_pending_pre_registration_duplicate(): void
    {
        PreRegistration::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'pending'],
        ]);
    }

    public function test_approved_pre_registration_does_not_trigger_duplicate(): void
    {
        PreRegistration::factory()->approved()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
        ]);
    }

    public function test_ignores_soft_deleted_students(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
            'deleted_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => false, 'status' => null],
        ]);
    }

    public function test_name_matching_is_case_insensitive(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'first_name' => 'juan',
            'last_name' => 'dela cruz',
        ]));

        $response->assertOk()->assertJson([
            'student' => ['is_duplicate' => true, 'status' => 'enrolled'],
        ]);
    }

    public function test_detects_existing_parent_email(): void
    {
        ParentUser::factory()->create(['email' => 'parent@example.com']);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'email' => 'parent@example.com',
        ]));

        $response->assertOk()->assertJson([
            'parent' => ['email_exists' => true, 'phone_exists' => false],
        ]);
    }

    public function test_detects_existing_parent_phone_when_no_email(): void
    {
        ParentUser::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload([
            'phone' => '09171234567',
        ]));

        $response->assertOk()->assertJson([
            'parent' => ['email_exists' => false, 'phone_exists' => true],
        ]);
    }

    public function test_response_contains_no_student_identifying_details(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'birthday' => '2015-03-15',
        ]);

        $response = $this->postJson('/api/v1/portal/pre-registrations/check', $this->payload());

        $data = $response->json();
        $this->assertArrayNotHasKey('id', $data['student']);
        $this->assertArrayNotHasKey('name', $data['student']);
        $this->assertArrayNotHasKey('student_number', $data['student']);
    }

    public function test_returns_422_when_required_fields_missing(): void
    {
        $this->postJson('/api/v1/portal/pre-registrations/check', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'first_name', 'last_name', 'birthday']);
    }
}
