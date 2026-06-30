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

    public function test_payment_history_spanning_two_school_years_is_sorted_chronologically(): void
    {
        // School year: June 2025 = start of SY2025-26, then June 2026 ends the next year.
        // Month-index order: June(0), July(1), ..., March(9).
        // A payment in March 2025 has month-index 9; July 2025 has index 1; June 2026 has index 0.
        // Wrong sort (month-index only): June 2026, July 2025, March 2025.
        // Correct sort (year then month-index): March 2025, July 2025, June 2026.

        $paymentsData = [
            ['school_month' => 'july', 'year' => 2025],
            ['school_month' => 'march', 'year' => 2025],
            ['school_month' => 'june', 'year' => 2026],
        ];

        foreach ($paymentsData as $data) {
            StudentMonthlyPayment::factory()->create([
                'student_id' => $this->student->id,
                'school_month' => $data['school_month'],
                'year' => $data['year'],
                'amount' => 2970,
            ]);
        }

        $response = $this->asParent()->getJson(
            "/api/v1/portal/students/{$this->student->id}/payment-history"
        );

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);

        // March 2025 comes before July 2025 (same year, lower month-index for July but March is earlier calendar month — wait:
        // school year order: june(0) july(1) aug(2) sep(3) oct(4) nov(5) dec(6) jan(7) feb(8) mar(9)
        // March has the highest month-index (9), July has index 1.
        // Within year 2025: July (index 1) sorts before March (index 9). So: July 2025, March 2025, June 2026.
        $this->assertSame('july', $data[0]['school_month']);
        $this->assertSame(2025, $data[0]['year']);

        $this->assertSame('march', $data[1]['school_month']);
        $this->assertSame(2025, $data[1]['year']);

        $this->assertSame('june', $data[2]['school_month']);
        $this->assertSame(2026, $data[2]['year']);
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
