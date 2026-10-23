<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Models\MealPlannerWeekVisibility;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Contract\ContractTestCase;

class MealPlannerContractTest extends ContractTestCase
{
    public function test_meal_plan_week_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/references/meal-planner?month=june&week=1');

        $this->assertContract($response, 'GET api/v1/references/meal-planner');
    }

    public function test_meal_plan_week_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->cashier)->getJson('/api/v1/references/meal-planner?month=june&week=1');

        $this->assertContract($response, 'GET api/v1/references/meal-planner', '422-no-branch');
    }

    public function test_meal_plan_week_hidden_from_parents_matches_contract(): void
    {
        MealPlannerWeekVisibility::create([
            'branch_id' => $this->scenario->branchA->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => false,
        ]);

        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/references/meal-planner?month=june&week=1');

        $this->assertContract($response, 'GET api/v1/references/meal-planner', 'ok-hidden');
    }

    public function test_saving_a_meal_plan_week_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->patchJson('/api/v1/references/meal-planner', $this->week());

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner');
    }

    public function test_saving_a_meal_plan_week_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->patchJson('/api/v1/references/meal-planner', []);

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner', '422-validation');
    }

    public function test_saving_a_meal_plan_week_without_an_active_branch_matches_contract(): void
    {
        Sanctum::actingAs($this->scenario->manager, ['staff']);

        $response = $this->patchJson('/api/v1/references/meal-planner', $this->week());

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner', '422-no-branch');
    }

    public function test_resetting_a_meal_plan_week_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson('/api/v1/references/meal-planner/reset', ['month' => 'june', 'week' => 1]);

        $this->assertContract($response, 'POST api/v1/references/meal-planner/reset');
    }

    public function test_resetting_a_meal_plan_week_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->postJson('/api/v1/references/meal-planner/reset', []);

        $this->assertContract($response, 'POST api/v1/references/meal-planner/reset', '422-validation');
    }

    public function test_resetting_a_meal_plan_week_without_an_active_branch_matches_contract(): void
    {
        Sanctum::actingAs($this->scenario->manager, ['staff']);

        $response = $this->postJson('/api/v1/references/meal-planner/reset', ['month' => 'june', 'week' => 1]);

        $this->assertContract($response, 'POST api/v1/references/meal-planner/reset', '422-no-branch');
    }

    public function test_changing_week_visibility_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner/week-visibility');
    }

    public function test_changing_week_visibility_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->patchJson('/api/v1/references/meal-planner/week-visibility', []);

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner/week-visibility', '422-validation');
    }

    public function test_changing_week_visibility_without_an_active_branch_matches_contract(): void
    {
        Sanctum::actingAs($this->scenario->manager, ['staff']);

        $response = $this->patchJson('/api/v1/references/meal-planner/week-visibility', [
            'month' => 'june',
            'week' => 1,
            'visible_to_parents' => false,
        ]);

        $this->assertContract($response, 'PATCH api/v1/references/meal-planner/week-visibility', '422-no-branch');
    }

    /**
     * @return array<string, mixed>
     */
    private function week(): array
    {
        $rows = array_map(fn (string $day) => [
            'day' => $day,
            'ulam' => 'Chicken Inasal',
            'vegetables' => 'Pechay',
            'fruit' => 'Mango',
            'soup' => 'Sotanghon',
            'snacks' => 'Maja Blanca',
        ], ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        return ['month' => 'june', 'week' => 2, 'rows' => $rows];
    }
}
