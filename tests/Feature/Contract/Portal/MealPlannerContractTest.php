<?php

namespace Tests\Feature\Contract\Portal;

use App\Models\MealPlannerWeekVisibility;
use Tests\Feature\Contract\ContractTestCase;

class MealPlannerContractTest extends ContractTestCase
{
    private const string ROUTE = 'GET api/v1/portal/meal-planner';

    public function test_parent_meal_plan_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $this->assertContract($response, self::ROUTE);
    }

    public function test_parent_meal_plan_for_a_hidden_week_matches_contract(): void
    {
        MealPlannerWeekVisibility::create([
            'branch_id' => $this->scenario->branchA->id,
            'school_month' => 'june',
            'week_number' => 1,
            'visible_to_parents' => false,
        ]);

        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $this->assertContract($response, self::ROUTE, 'ok-hidden');
    }

    public function test_parent_meal_plan_with_an_invalid_week_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/meal-planner?week=9');

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_meal_plan_for_a_parent_without_a_subscription_student_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->unactivatedParent)->getJson('/api/v1/portal/meal-planner?month=june&week=1');

        $this->assertContract($response, self::ROUTE, '403-no-subscription');
    }

    public function test_meal_plan_for_a_branch_without_a_linked_student_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)
            ->getJson("/api/v1/portal/meal-planner?branch_id={$this->scenario->branchB->id}&month=june&week=1");

        $this->assertContract($response, self::ROUTE, '403-branch-not-linked');
    }
}
