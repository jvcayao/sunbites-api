<?php

namespace Tests\Feature\Kitchen;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoidPaymentTest extends TestCase
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
        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
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

    private function makeCurrentMonthPayment(string $status = 'paid'): StudentMonthlyPayment
    {
        $now = now();
        $currentMonth = SchoolMonth::fromMonthNumber($now->month)?->value ?? 'june';

        return StudentMonthlyPayment::factory()->state(['status' => $status])->create([
            'student_id' => $this->student->id,
            'school_month' => $currentMonth,
            'year' => $now->year,
            'amount' => 2970,
            'recorded_at' => $status === 'paid' ? now() : null,
        ]);
    }

    private function makePastMonthPayment(): StudentMonthlyPayment
    {
        return StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => now()->year - 1,
            'amount' => 2970,
        ]);
    }

    public function test_admin_can_void_current_month_paid_payment(): void
    {
        $payment = $this->makeCurrentMonthPayment('paid');

        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            ['reason' => 'Student downgraded mid-month.']
        );

        $response->assertOk();
        $this->assertDatabaseHas('student_monthly_payments', [
            'id' => $payment->id,
            'status' => 'voided',
        ]);
        $this->assertNotNull(
            StudentMonthlyPayment::find($payment->id)->voided_at
        );
    }

    public function test_cannot_void_past_month_paid_payment(): void
    {
        $payment = $this->makePastMonthPayment();

        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            ['reason' => 'Attempt to void past month.']
        );

        $response->assertUnprocessable();
        $this->assertDatabaseHas('student_monthly_payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_cannot_void_unpaid_payment(): void
    {
        $payment = $this->makeCurrentMonthPayment('unpaid');

        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            ['reason' => 'Should not work.']
        );

        $response->assertUnprocessable();
    }

    public function test_void_requires_reason(): void
    {
        $payment = $this->makeCurrentMonthPayment('paid');

        $response = $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            []
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_supervisor_cannot_void_payment(): void
    {
        $payment = $this->makeCurrentMonthPayment('paid');

        $response = $this->asUserWithRole('supervisor')->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            ['reason' => 'Test.']
        );

        $response->assertForbidden();
    }

    public function test_void_logs_activity(): void
    {
        $payment = $this->makeCurrentMonthPayment('paid');

        $this->asAdmin()->patchJson(
            "/api/v1/students/{$this->student->id}/payments/{$payment->id}/void",
            ['reason' => 'Refund issued separately.']
        );

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Student::class,
            'subject_id' => $this->student->id,
            'description' => 'student_payment.voided',
        ]);
    }
}
