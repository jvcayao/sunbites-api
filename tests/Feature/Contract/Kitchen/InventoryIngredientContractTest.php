<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class InventoryIngredientContractTest extends ContractTestCase
{
    public function test_ingredient_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/references/menu-items/{$this->scenario->menuItem->id}/ingredients");

        $this->assertContract($response, 'GET api/v1/references/menu-items/{item}/ingredients');
    }

    public function test_other_branch_ingredient_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/references/menu-items/{$this->scenario->branchBMenuItem->id}/ingredients");

        $this->assertContract($response, 'GET api/v1/references/menu-items/{item}/ingredients', 'other-branch');
    }

    public function test_attaching_an_ingredient_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/references/menu-items/{$this->scenario->menuItem->id}/ingredients", [
                'inventory_item_id' => $this->scenario->inventoryItem->id,
                'quantity_used' => 2,
            ]);

        $this->assertContract($response, 'POST api/v1/references/menu-items/{item}/ingredients');
    }

    public function test_attaching_an_ingredient_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/references/menu-items/{$this->scenario->menuItem->id}/ingredients", []);

        $this->assertContract($response, 'POST api/v1/references/menu-items/{item}/ingredients', '422-validation');
    }

    public function test_attaching_an_other_branch_inventory_item_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/references/menu-items/{$this->scenario->menuItem->id}/ingredients", [
                'inventory_item_id' => $this->scenario->branchBInventoryItem->id,
                'quantity_used' => 2,
            ]);

        $this->assertContract($response, 'POST api/v1/references/menu-items/{item}/ingredients', '404');
    }

    public function test_attaching_an_ingredient_to_an_other_branch_menu_item_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->postJson("/api/v1/references/menu-items/{$this->scenario->branchBMenuItem->id}/ingredients", [
                'inventory_item_id' => $this->scenario->inventoryItem->id,
                'quantity_used' => 2,
            ]);

        $this->assertContract($response, 'POST api/v1/references/menu-items/{item}/ingredients', 'other-branch');
    }

    public function test_detaching_an_ingredient_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->deleteJson("/api/v1/references/menu-items/{$this->scenario->menuItem->id}/ingredients/{$this->scenario->inventoryItem->id}");

        $this->assertContract($response, 'DELETE api/v1/references/menu-items/{item}/ingredients/{inventoryItem}');
    }

    public function test_detaching_an_other_branch_ingredient_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->deleteJson("/api/v1/references/menu-items/{$this->scenario->branchBMenuItem->id}/ingredients/{$this->scenario->branchBInventoryItem->id}");

        $this->assertContract($response, 'DELETE api/v1/references/menu-items/{item}/ingredients/{inventoryItem}', 'other-branch');
    }
}
