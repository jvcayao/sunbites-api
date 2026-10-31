<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Models\PosMenuItem;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class PosMenuItemContractTest extends ContractTestCase
{
    public function test_menu_item_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/pos/menu-items');

        $this->assertContract($response, 'GET api/v1/pos/menu-items');
    }

    public function test_menu_item_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->cashier)->getJson('/api/v1/pos/menu-items');

        $this->assertContract($response, 'GET api/v1/pos/menu-items', '422-no-branch');
    }

    public function test_creating_a_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->postJson('/api/v1/pos/menu-items', $this->menuItem());

        $this->assertContract($response, 'POST api/v1/pos/menu-items');
    }

    public function test_creating_a_menu_item_without_input_matches_contract(): void
    {
        $response = $this->asManager()->postJson('/api/v1/pos/menu-items', []);

        $this->assertContract($response, 'POST api/v1/pos/menu-items', '422-validation');
    }

    public function test_updating_a_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->putJson("/api/v1/pos/menu-items/{$this->scenario->menuItem->id}", $this->menuItem());

        $this->assertContract($response, 'PUT api/v1/pos/menu-items/{item}');
    }

    public function test_updating_a_menu_item_without_input_matches_contract(): void
    {
        $response = $this->asManager()->putJson("/api/v1/pos/menu-items/{$this->scenario->menuItem->id}", []);

        $this->assertContract($response, 'PUT api/v1/pos/menu-items/{item}', '422-validation');
    }

    public function test_updating_an_other_branch_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->putJson("/api/v1/pos/menu-items/{$this->scenario->branchBMenuItem->id}", $this->menuItem());

        $this->assertContract($response, 'PUT api/v1/pos/menu-items/{item}', 'other-branch');
    }

    public function test_toggling_a_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/pos/menu-items/{$this->scenario->menuItem->id}/toggle");

        $this->assertContract($response, 'POST api/v1/pos/menu-items/{item}/toggle');
    }

    public function test_toggling_an_other_branch_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->postJson("/api/v1/pos/menu-items/{$this->scenario->branchBMenuItem->id}/toggle");

        $this->assertContract($response, 'POST api/v1/pos/menu-items/{item}/toggle', 'other-branch');
    }

    /**
     * Deletes an item with no order lines, so the request succeeds. A sold item now returns
     * 422 (`@422-has-sales`, since B-20) instead, covered by
     * test_deleting_a_sold_menu_item_matches_contract below.
     */
    public function test_deleting_a_menu_item_matches_contract(): void
    {
        $unsold = PosMenuItem::factory()->create([
            'branch_id' => $this->scenario->branchA->id,
            'name' => 'Seasonal Halo-Halo',
            'price' => 75,
            'category' => 'extra',
        ]);

        $response = $this->asManager()->deleteJson("/api/v1/pos/menu-items/{$unsold->id}");

        $this->assertContract($response, 'DELETE api/v1/pos/menu-items/{item}');
    }

    public function test_deleting_a_sold_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->deleteJson("/api/v1/pos/menu-items/{$this->scenario->menuItem->id}");

        $this->assertContract($response, 'DELETE api/v1/pos/menu-items/{item}', '422-has-sales');
    }

    public function test_deleting_an_other_branch_menu_item_matches_contract(): void
    {
        $response = $this->asManager()->deleteJson("/api/v1/pos/menu-items/{$this->scenario->branchBMenuItem->id}");

        $this->assertContract($response, 'DELETE api/v1/pos/menu-items/{item}', 'other-branch');
    }

    private function asManager(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->manager);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItem(): array
    {
        return [
            'name' => 'Pancit Canton',
            'price' => 60,
            'category' => 'meal',
            'is_subscription_item' => true,
        ];
    }
}
