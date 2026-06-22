<?php

namespace Tests\Feature;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentRangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_type' => 'subscription',
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

    public function test_admin_can_add_subscription_range(): void
    {
        $response = $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'august',
            'subscription_end_year' => 2025,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 3);
        $this->assertDatabaseCount('student_monthly_payments', 3);
    }

    public function test_adding_range_skips_existing_months(): void
    {
        // Pre-create a payment for July 2025
        StudentMonthlyPayment::create([
            'student_id' => $this->student->id,
            'school_month' => 'july',
            'year' => 2025,
            'status' => 'unpaid',
            'amount' => 2970,
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'august',
            'subscription_end_year' => 2025,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 2);

        $skipped = $response->json('skipped');
        $this->assertContains('July 2025', $skipped);

        // Total is 3 (the pre-existing one + 2 new)
        $this->assertDatabaseCount('student_monthly_payments', 3);
    }

    public function test_adding_range_rejects_end_before_start(): void
    {
        $response = $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'february',
            'subscription_end_year' => 2025,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.subscription_end_month.0', 'End month must be after start month.');
    }

    public function test_adding_range_skips_zero_day_months(): void
    {
        // Configure June 2025 as a 0-day month (no school activity)
        BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::June->value,
            'year' => 2025,
            'days' => 0,
            'amount' => 0,
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'august',
            'subscription_end_year' => 2025,
        ]);

        $response->assertStatus(201);
        // June is skipped (0 days); only July and August are created
        $response->assertJsonPath('created', 2);
        $this->assertDatabaseCount('student_monthly_payments', 2);

        $months = StudentMonthlyPayment::pluck('school_month')->toArray();
        $this->assertNotContains('june', $months);
    }

    public function test_cashier_cannot_add_range(): void
    {
        $response = $this->asUserWithRole('cashier')->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'august',
            'subscription_end_year' => 2025,
        ]);

        $response->assertForbidden();
    }
}
