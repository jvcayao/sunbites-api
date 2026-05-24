<?php

namespace Tests\Feature;

use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'student_number' => 'TEST-2025-001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'section' => 'Section A',
            'birthday' => '2015-01-15',
            'student_type' => StudentType::NonSubscription->value,
            'allergies' => null,
            'notes' => null,
            'contacts' => [[
                'full_name' => 'Maria Dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
            ]],
            'signature' => 'Maria Dela Cruz',
            'permission_meals' => true,
            'permission_dietary' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function subscriptionFields(array $overrides = []): array
    {
        return array_merge([
            'student_type' => StudentType::Subscription->value,
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2026,
        ], $overrides);
    }

    public function test_manager_can_enroll_non_subscription_student(): void
    {
        $response = $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'qr_code', 'student_number', 'full_name']);
        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'branch_id' => $this->branch->id,
            'student_type' => StudentType::NonSubscription->value,
        ]);

        $student = Student::where('student_number', 'TEST-2025-001')->first();
        $this->assertStringStartsWith('SB-', $student->qr_code);
        $this->assertDatabaseHas('student_contacts', ['student_id' => $student->id, 'is_primary' => true]);
    }

    public function test_subscription_enrollment_seeds_payments_for_range(): void
    {
        $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload(
            array_merge(['student_number' => 'TEST-2025-002'], $this->subscriptionFields())
        ));

        $student = Student::where('student_number', 'TEST-2025-002')->first();
        $this->assertCount(10, $student->monthlyPayments);
        $this->assertTrue($student->monthlyPayments->every(fn ($p) => $p->status === 'unpaid'));
    }

    public function test_subscription_enrollment_seeds_partial_range(): void
    {
        $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload(
            array_merge(['student_number' => 'TEST-2025-003'], $this->subscriptionFields([
                'subscription_start_month' => 'august',
                'subscription_start_year' => 2025,
                'subscription_end_month' => 'december',
                'subscription_end_year' => 2025,
            ]))
        ));

        $student = Student::where('student_number', 'TEST-2025-003')->first();
        $this->assertCount(5, $student->monthlyPayments);

        $months = $student->monthlyPayments->pluck('school_month')->map->value->toArray();
        $this->assertEqualsCanonicalizing(['august', 'september', 'october', 'november', 'december'], $months);
    }

    public function test_enrollment_rejects_end_before_start(): void
    {
        $response = $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload(
            $this->subscriptionFields([
                'subscription_start_month' => 'june',
                'subscription_start_year' => 2025,
                'subscription_end_month' => 'february',
                'subscription_end_year' => 2025,
            ])
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('errors.subscription_end_month.0', 'End month must be after start month.');
    }

    public function test_non_subscription_student_has_no_monthly_payments(): void
    {
        $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload([
            'student_type' => StudentType::NonSubscription->value,
        ]));

        $student = Student::where('student_number', 'TEST-2025-001')->first();
        $this->assertCount(0, $student->monthlyPayments);
    }

    public function test_duplicate_student_number_rejected_per_branch(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'TEST-2025-001']);

        $response = $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload());

        $response->assertJsonValidationErrors(['student_number']);
    }

    public function test_same_student_number_allowed_in_different_branch(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        Student::factory()->create(['branch_id' => $otherBranch->id, 'student_number' => 'TEST-2025-001']);

        $response = $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload());

        $response->assertCreated();
        $this->assertDatabaseCount('students', 2);
    }

    public function test_freetext_fields_are_html_stripped(): void
    {
        $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload([
            'allergies' => '<script>alert("xss")</script>Peanuts',
            'notes' => '<b>Bold note</b>',
        ]));

        $this->assertDatabaseHas('students', ['allergies' => 'alert("xss")Peanuts']);
        $this->assertDatabaseHas('students', ['notes' => 'Bold note']);
    }

    public function test_cashier_cannot_enroll_student(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/enrollment', $this->validPayload());

        $response->assertForbidden();
    }

    public function test_enrollment_requires_at_least_one_contact(): void
    {
        $response = $this->asManager()->postJson('/api/v1/enrollment', $this->validPayload(['contacts' => []]));

        $response->assertJsonValidationErrors(['contacts']);
    }
}
