<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SubscriptionStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private ParentUser $parent;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    private function attachStudent(Student $student): void
    {
        $this->parent->students()->attach($student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    public function test_portal_students_list_includes_monthly_status_for_subscription_student(): void
    {
        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        $student = Student::factory()->subscription()->enrolled()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->attachStudent($student);

        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk();
        $studentData = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($studentData);
        $this->assertArrayHasKey('subscription_monthly_status', $studentData);

        if ($studentData['subscription_monthly_status'] !== null) {
            $status = $studentData['subscription_monthly_status'];
            $this->assertArrayHasKey('month', $status);
            $this->assertArrayHasKey('year', $status);
            $this->assertArrayHasKey('categories', $status);
            $this->assertArrayHasKey('meal', $status['categories']);
            $this->assertArrayHasKey('remaining', $status['categories']['meal']);
        }
    }

    public function test_portal_students_list_has_null_monthly_status_for_non_subscription_student(): void
    {
        $student = Student::factory()->nonSubscription()->enrolled()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->attachStudent($student);

        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk();
        $studentData = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($studentData);
        $this->assertNull($studentData['subscription_monthly_status']);
    }
}
