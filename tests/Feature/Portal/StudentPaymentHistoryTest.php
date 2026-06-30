<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentPaymentHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $subscriptionStudent;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->subscriptionStudent = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->subscriptionStudent->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_subscription_student_payment_history_is_returned(): void
    {
        StudentMonthlyPayment::create([
            'student_id' => $this->subscriptionStudent->id,
            'school_month' => 'june',
            'year' => 2026,
            'amount' => 2970,
            'status' => 'unpaid',
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $this->subscriptionStudent->id,
            'school_month' => 'july',
            'year' => 2026,
            'amount' => 2970,
            'status' => 'paid',
            'recorded_at' => now(),
        ]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->subscriptionStudent->id}/payment-history");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.school_month', 'june')
            ->assertJsonPath('data.1.school_month', 'july');
    }

    public function test_non_subscription_student_returns_empty_payment_history(): void
    {
        $nonSubscriptionStudent = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_type' => 'non_subscription',
        ]);

        $this->parent->students()->attach($nonSubscriptionStudent->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$nonSubscriptionStudent->id}/payment-history");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_parent_cannot_view_another_parents_student(): void
    {
        $otherStudent = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$otherStudent->id}/payment-history");

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson("/api/v1/portal/students/{$this->subscriptionStudent->id}/payment-history");

        $response->assertUnauthorized();
    }
}
