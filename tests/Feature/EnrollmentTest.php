<?php

namespace Tests\Feature;

use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $this->branch = Branch::factory()->create();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function actingAsManager(): static
    {
        return $this->actingAs($this->manager)->withSession(['active_branch_id' => $this->branch->id]);
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

    public function test_manager_can_enroll_non_subscription_student(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('students', [
            'first_name' => 'Juan',
            'branch_id' => $this->branch->id,
            'student_type' => StudentType::NonSubscription->value,
        ]);

        $student = Student::where('student_number', 'TEST-2025-001')->first();
        $this->assertStringStartsWith('SB-', $student->qr_code);
        $this->assertDatabaseHas('student_contacts', ['student_id' => $student->id, 'is_primary' => true]);
    }

    public function test_subscription_student_gets_10_monthly_payments_seeded(): void
    {
        $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload([
            'student_number' => 'TEST-2025-002',
            'student_type' => StudentType::Subscription->value,
        ]));

        $student = Student::where('student_number', 'TEST-2025-002')->first();
        $this->assertCount(10, $student->monthlyPayments);
        $this->assertTrue($student->monthlyPayments->every(fn ($p) => $p->status === 'unpaid'));
    }

    public function test_non_subscription_student_has_no_monthly_payments(): void
    {
        $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload());

        $student = Student::where('student_number', 'TEST-2025-001')->first();
        $this->assertCount(0, $student->monthlyPayments);
    }

    public function test_duplicate_student_number_rejected_per_branch(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'TEST-2025-001']);

        $response = $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload());

        $response->assertSessionHasErrors('student_number');
    }

    public function test_same_student_number_allowed_in_different_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        Student::factory()->create(['branch_id' => $otherBranch->id, 'student_number' => 'TEST-2025-001']);

        $response = $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload());

        $response->assertRedirect();
        $this->assertDatabaseCount('students', 2);
    }

    public function test_freetext_fields_are_html_stripped(): void
    {
        $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload([
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

        $response = $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('kitchen.enrollment.store'), $this->validPayload());

        $response->assertForbidden();
    }

    public function test_enrollment_requires_at_least_one_contact(): void
    {
        $response = $this->actingAsManager()->post(route('kitchen.enrollment.store'), $this->validPayload(['contacts' => []]));

        $response->assertSessionHasErrors('contacts');
    }
}
