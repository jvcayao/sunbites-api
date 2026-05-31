<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MealPlannerWeekVisibility;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use App\Models\WeeklyMealPlan;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlannerWeekVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $cashier;

    private Branch $branch;

    private ParentUser $parent;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asStaff(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    // --- Week visibility toggle ---

    public function test_admin_can_publish_week(): void
    {
        $response = $this->asStaff($this->admin)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['visible_to_parents' => true]);
        $this->assertDatabaseHas('meal_planner_week_visibility', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => true,
        ]);
    }

    public function test_admin_can_unpublish_week(): void
    {
        $response = $this->asStaff($this->admin)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        $response->assertOk();
        $response->assertJson(['visible_to_parents' => false]);
        $this->assertDatabaseHas('meal_planner_week_visibility', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => false,
        ]);
    }

    public function test_manager_can_toggle_week_visibility(): void
    {
        $response = $this->asStaff($this->manager)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'july',
            'week' => 2,
            'visible_to_parents' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('meal_planner_week_visibility', [
            'branch_id' => $this->branch->id,
            'school_month' => 'july',
            'week_number' => 2,
            'visible_to_parents' => false,
        ]);
    }

    public function test_supervisor_cannot_toggle_week_visibility(): void
    {
        $response = $this->asStaff($this->supervisor)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_cashier_cannot_toggle_week_visibility(): void
    {
        $response = $this->asStaff($this->cashier)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        $response->assertForbidden();
    }

    // --- Portal endpoint ---

    public function test_portal_returns_meal_data_when_week_is_published(): void
    {
        MealPlannerWeekVisibility::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => true,
        ]);

        WeeklyMealPlan::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Chicken Adobo',
            'snacks' => 'Graham Crackers',
        ]);

        $response = $this->asParent()->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
        $response->assertJson(['visible_to_parents' => true]);
        $this->assertCount(5, $response->json('days'));
        $mondayRow = collect($response->json('days'))->firstWhere('day', 'monday');
        $this->assertArrayHasKey('snacks', $mondayRow);
    }

    public function test_portal_returns_empty_when_week_is_unpublished(): void
    {
        MealPlannerWeekVisibility::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => false,
        ]);

        WeeklyMealPlan::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Chicken Adobo',
        ]);

        $response = $this->asParent()->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $response->assertOk();
        $response->assertJson(['visible_to_parents' => false, 'days' => []]);
    }

    // --- Branch scoping ---

    public function test_week_visibility_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);

        MealPlannerWeekVisibility::withoutBranch()->create([
            'branch_id' => $otherBranch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => true,
        ]);

        $this->asStaff($this->admin)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        // The other branch record must be unaffected
        $this->assertDatabaseHas('meal_planner_week_visibility', [
            'branch_id' => $otherBranch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => true,
        ]);

        // This branch has the new value
        $this->assertDatabaseHas('meal_planner_week_visibility', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => false,
        ]);
    }
}
