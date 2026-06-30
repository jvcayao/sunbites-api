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

class SubscriptionReportHistoricalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_subscription_report_includes_historical_section(): void
    {
        $exSubscriber = Student::factory()->nonSubscription()->create([
            'branch_id' => $this->branch->id,
        ]);
        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $exSubscriber->id,
            'school_month' => 'june',
            'year' => now()->year,
            'amount' => 2970,
        ]);

        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/subscription?month=june&year='.now()->year
        );

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta', 'historical_data']);

        $historical = $response->json('historical_data');
        $this->assertCount(1, $historical);
        $this->assertEquals($exSubscriber->id, $historical[0]['id']);
        $this->assertEquals(2970.0, $historical[0]['payment_amount']);
    }

    public function test_historical_data_excludes_other_branch_students(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->nonSubscription()->create([
            'branch_id' => $otherBranch->id,
        ]);
        StudentMonthlyPayment::factory()->paid()->create([
            'student_id' => $otherStudent->id,
            'school_month' => 'june',
            'year' => now()->year,
        ]);

        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/subscription?month=june&year='.now()->year
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('historical_data'));
    }

    public function test_historical_data_is_empty_when_no_ex_subscribers(): void
    {
        $response = $this->asAdmin()->getJson(
            '/api/v1/reports/subscription?month=june&year='.now()->year
        );

        $response->assertOk();
        $this->assertEquals([], $response->json('historical_data'));
    }
}
