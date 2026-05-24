<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\WeeklyMealPlan;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlannerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $cashier;

    private Branch $branch;

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
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    /** @return array<int, array{day: string, ulam: string, vegetables: string, fruit: string, soup: string}> */
    private function validRows(): array
    {
        return [
            ['day' => 'monday', 'ulam' => 'Adobo', 'vegetables' => 'Chopsuey', 'fruit' => 'Mango', 'soup' => 'Sinigang'],
            ['day' => 'tuesday', 'ulam' => 'Sinigang', 'vegetables' => 'Pinakbet', 'fruit' => 'Banana', 'soup' => 'Miso'],
            ['day' => 'wednesday', 'ulam' => 'Tinola', 'vegetables' => 'Laing', 'fruit' => 'Apple', 'soup' => 'Broth'],
            ['day' => 'thursday', 'ulam' => 'Kaldereta', 'vegetables' => 'Gulay', 'fruit' => 'Orange', 'soup' => 'Clear'],
            ['day' => 'friday', 'ulam' => 'Inasal', 'vegetables' => 'Ampalaya', 'fruit' => 'Watermelon', 'soup' => 'Corn'],
        ];
    }

    public function test_anyone_can_view_meal_planner(): void
    {
        $response = $this->asUser($this->cashier)->getJson('/api/v1/references/meal-planner');

        $response->assertOk();
        $response->assertJsonStructure(['grid']);
    }

    public function test_admin_can_save_meal_plan(): void
    {
        $response = $this->asUser($this->admin)->patchJson('/api/v1/references/meal-planner', [
            'month' => 'june',
            'week' => 1,
            'rows' => $this->validRows(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_meal_plans', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Adobo',
        ]);
    }

    public function test_manager_can_save_meal_plan(): void
    {
        $response = $this->asUser($this->manager)->patchJson('/api/v1/references/meal-planner', [
            'month' => 'july',
            'week' => 2,
            'rows' => $this->validRows(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_meal_plans', ['school_month' => 'july', 'week_number' => 2]);
    }

    public function test_supervisor_cannot_save_meal_plan(): void
    {
        $response = $this->asUser($this->supervisor)->patchJson('/api/v1/references/meal-planner', [
            'month' => 'june',
            'week' => 1,
            'rows' => $this->validRows(),
        ]);

        $response->assertForbidden();
    }

    public function test_cashier_cannot_save_meal_plan(): void
    {
        $response = $this->asUser($this->cashier)->patchJson('/api/v1/references/meal-planner', [
            'month' => 'june',
            'week' => 1,
            'rows' => $this->validRows(),
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_reset_meal_plan(): void
    {
        WeeklyMealPlan::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Custom Ulam',
        ]);

        $response = $this->asUser($this->admin)->postJson('/api/v1/references/meal-planner/reset', [
            'month' => 'june',
            'week' => 1,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_meal_plans', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Chicken Adobo',
        ]);
    }

    public function test_upsert_updates_existing_record(): void
    {
        WeeklyMealPlan::withoutBranch()->create([
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Old Ulam',
        ]);

        $this->asUser($this->admin)->patchJson('/api/v1/references/meal-planner', [
            'month' => 'june',
            'week' => 1,
            'rows' => $this->validRows(),
        ]);

        $this->assertDatabaseCount('weekly_meal_plans', 5);
        $this->assertDatabaseHas('weekly_meal_plans', [
            'branch_id' => $this->branch->id,
            'ulam' => 'Adobo',
        ]);
        $this->assertDatabaseMissing('weekly_meal_plans', ['ulam' => 'Old Ulam']);
    }

    public function test_meal_plan_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        WeeklyMealPlan::withoutBranch()->create([
            'branch_id' => $otherBranch->id,
            'school_month' => 'june',
            'week_number' => 1,
            'day_of_week' => 'monday',
            'ulam' => 'Other Branch Ulam',
        ]);

        $response = $this->asUser($this->admin)->getJson('/api/v1/references/meal-planner');

        $response->assertOk();
        $grid = $response->json('grid');
        $ulams = array_column($grid, 'ulam');
        $this->assertNotContains('Other Branch Ulam', $ulams);
    }
}
