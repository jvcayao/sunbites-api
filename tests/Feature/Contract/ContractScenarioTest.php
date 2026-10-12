<?php

namespace Tests\Feature\Contract;

class ContractScenarioTest extends ContractTestCase
{
    /**
     * Row counts the scenario documents (design §4.1.3). Both tests below assert the same
     * literal counts, so two independent builds are proven identical.
     *
     * @var array<string, int>
     */
    private const array EXPECTED_ROW_COUNTS = [
        'branches' => 2,
        'users' => 5,
        'students' => 4,
        'parents' => 3,
        'parent_student' => 3,
        'student_contacts' => 4,
        'student_monthly_payments' => 5,
        'inventory_items' => 3,
        'pos_menu_items' => 2,
        'pos_menu_item_inventory' => 2,
        'inventory_logs' => 5,
        'orders' => 5,
        'order_items' => 4,
        'wallets' => 1,
        'transactions' => 5,
        'credit_transactions' => 1,
        'wallet_topup_voids' => 1,
        'branch_monthly_amounts' => 2,
        'weekly_meal_plans' => 5,
        'announcements' => 2,
        'notifications' => 2,
        'feedbacks' => 2,
        'pre_registrations' => 5,
        'pre_registration_contacts' => 10,
        'parent_payment_reminders' => 1,
        'system_configurations' => 5,
    ];

    public function test_build_creates_the_documented_rows(): void
    {
        foreach (self::EXPECTED_ROW_COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_a_second_independent_build_creates_identical_rows(): void
    {
        foreach (self::EXPECTED_ROW_COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_build_leaves_no_authenticated_user_or_active_branch_behind(): void
    {
        $this->assertFalse(app()->bound('active_branch'));
        $this->assertSame([], $this->defaultHeaders);
        $this->assertGuest('sanctum');
    }

    public function test_branch_b_student_is_not_listed_with_the_branch_a_header(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/students');

        $response->assertOk();
        $this->assertSame(
            [$this->scenario->subscriptionStudent->id, $this->scenario->nonSubscriptionStudent->id],
            collect($response->json('data'))->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_branch_b_student_returns_404_with_the_branch_a_header(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/students/{$this->scenario->branchBStudent->id}");

        $response->assertNotFound();
    }
}
