<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The payment guards that ran before validation still win over a validation error
 * (Spec 16 T-5.3, design §4.2.2, hazard H4). Debug mode is on so the mechanism is visible
 * (hazard H3): each guard is an `abort_if()`, which adds the exception details.
 */
class PaymentRequestsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $manager;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
    }

    public function test_editing_the_amount_of_a_paid_payment_returns_422_before_validating(): void
    {
        $payment = StudentMonthlyPayment::factory()->paid()->create(['student_id' => $this->student->id, 'amount' => 2970]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$this->student->id}/payments/{$payment->id}/amount", ['amount' => 'abc']);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Can only edit amount on unpaid payments.')
            ->assertJsonPath('exception', HttpException::class);
        $this->assertSame('2970.00', $payment->fresh()->amount);
    }

    public function test_voiding_another_students_payment_returns_404_before_validating(): void
    {
        $otherStudent = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $payment = StudentMonthlyPayment::factory()->paid()->create(['student_id' => $otherStudent->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$this->student->id}/payments/{$payment->id}/void");

        $response->assertNotFound()->assertJsonPath('exception', NotFoundHttpException::class);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_voiding_an_unpaid_payment_returns_422_before_validating(): void
    {
        $payment = StudentMonthlyPayment::factory()->unpaid()->create(['student_id' => $this->student->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$this->student->id}/payments/{$payment->id}/void");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Only paid payments can be voided.')
            ->assertJsonPath('exception', HttpException::class);
        $this->assertSame('unpaid', $payment->fresh()->status);
    }

    /**
     * Guards hazard H16: the month loop must advance, so the request returns.
     */
    public function test_adding_a_june_to_march_range_creates_ten_payments(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2026,
        ]);

        $response->assertCreated()->assertJsonPath('created', 10);
        $this->assertSame(10, $this->student->monthlyPayments()->count());
    }

    public function test_adding_a_range_that_ends_before_it_starts_returns_422_with_errors_only(): void
    {
        $response = $this->asManager()->postJson("/api/v1/students/{$this->student->id}/payments/range", [
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2026,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2026,
        ]);

        $response->assertUnprocessable()->assertExactJson([
            'errors' => ['subscription_end_month' => ['End month must be after start month.']],
        ]);
        $this->assertSame(0, $this->student->monthlyPayments()->count());
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
