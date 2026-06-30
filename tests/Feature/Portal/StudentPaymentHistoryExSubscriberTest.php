<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentPaymentHistoryExSubscriberTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $student;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->nonSubscription()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->parent = ParentUser::factory()->create();

        $this->parent->students()->attach($this->student->id, [
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

    public function test_portal_payment_history_accessible_after_type_switch(): void
    {
        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'amount' => 2970,
        ]);

        $response = $this->asParent()->getJson(
            "/api/v1/portal/students/{$this->student->id}/payment-history"
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_portal_payment_history_excludes_voided_records(): void
    {
        StudentMonthlyPayment::factory()->create([
            'student_id' => $this->student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'status' => 'voided',
            'amount' => 2970,
            'voided_at' => now(),
            'voided_by' => null,
            'void_reason' => 'Type switch.',
        ]);

        $response = $this->asParent()->getJson(
            "/api/v1/portal/students/{$this->student->id}/payment-history"
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
