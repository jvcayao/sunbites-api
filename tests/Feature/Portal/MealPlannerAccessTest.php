<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MealPlannerAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staffUser = User::factory()->create();
    }

    private function createParent(): ParentUser
    {
        return ParentUser::factory()->create();
    }

    private function attachStudent(ParentUser $parent, Student $student): void
    {
        $parent->students()->attach($student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staffUser->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(ParentUser $parent): static
    {
        $token = $parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_parent_with_only_non_subscription_students_cannot_access_meal_plan(): void
    {
        $parent = $this->createParent();
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $student);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertForbidden();
    }

    public function test_parent_with_only_subscription_students_can_access_meal_plan(): void
    {
        $parent = $this->createParent();
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $student);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
    }

    public function test_parent_with_mixed_students_can_access_meal_plan(): void
    {
        $parent = $this->createParent();

        $subStudent = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $nonSubStudent = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);
        $this->attachStudent($parent, $subStudent);
        $this->attachStudent($parent, $nonSubStudent);

        $response = $this->asParent($parent)
            ->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/portal/meal-planner?month=june&week=1')
            ->assertUnauthorized();
    }
}
