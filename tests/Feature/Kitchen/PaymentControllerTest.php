<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Student $student;

    private StudentMonthlyPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $this->payment = StudentMonthlyPayment::create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => 2025,
            'status' => 'unpaid',
            'amount' => 2970,
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

    // -------------------------------------------------------------------------
    // toggle
    // -------------------------------------------------------------------------

    public function test_admin_can_toggle_payment_to_paid(): void
    {
        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$this->payment->id}"
        );

        $response->assertOk();
        $response->assertJsonStructure(['id', 'year', 'status', 'recorded_at']);
        $response->assertJsonPath('status', 'paid');
        $response->assertJsonPath('year', 2025);
        $this->assertDatabaseHas('student_monthly_payments', [
            'id' => $this->payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_toggle_flips_paid_payment_back_to_unpaid(): void
    {
        $this->payment->update(['status' => 'paid', 'recorded_at' => now()]);

        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$this->payment->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('status', 'unpaid');
        $response->assertJsonPath('year', 2025);
        $this->assertDatabaseHas('student_monthly_payments', [
            'id' => $this->payment->id,
            'status' => 'unpaid',
        ]);
    }

    public function test_cashier_cannot_toggle_payment(): void
    {
        $response = $this->asUserWithRole('cashier')->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$this->payment->id}"
        );

        $response->assertForbidden();
    }

    public function test_toggle_rejects_unauthenticated_request(): void
    {
        $response = $this->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$this->payment->id}"
        );

        $response->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // record
    // -------------------------------------------------------------------------

    public function test_admin_can_record_payment_with_year(): void
    {
        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/payments",
            [
                'school_month' => 'june',
                'year' => 2025,
                'amount' => '2970.00',
            ]
        );

        $response->assertOk();
        $response->assertJsonStructure(['id', 'status', 'amount', 'recorded_at']);
        $response->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('student_monthly_payments', [
            'id' => $this->payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_record_requires_year(): void
    {
        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/payments",
            [
                'school_month' => 'june',
                'amount' => '2970.00',
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['year']);
    }

    public function test_record_requires_valid_school_month(): void
    {
        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/payments",
            [
                'school_month' => 'invalid_month',
                'year' => 2025,
                'amount' => '2970.00',
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['school_month']);
    }

    public function test_record_returns_404_when_payment_not_found_for_month_year(): void
    {
        $response = $this->asAdmin()->postJson(
            "/api/v1/students/{$this->student->id}/payments",
            [
                'school_month' => 'july',
                'year' => 2025,
                'amount' => '2970.00',
            ]
        );

        $response->assertNotFound();
    }
}
