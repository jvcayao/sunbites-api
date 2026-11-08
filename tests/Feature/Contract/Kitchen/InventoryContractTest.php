<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class InventoryContractTest extends ContractTestCase
{
    public function test_pos_inventory_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/pos/inventory');

        $this->assertContract($response, 'GET api/v1/pos/inventory');
    }

    public function test_pos_inventory_list_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson('/api/v1/pos/inventory');

        $this->assertContract($response, 'GET api/v1/pos/inventory', '422-no-branch');
    }

    public function test_adjusting_stock_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->postJson("/api/v1/pos/inventory/{$this->scenario->inventoryItem->id}/adjust", $this->adjustment());

        $this->assertContract($response, 'POST api/v1/pos/inventory/{item}/adjust');
    }

    public function test_adjusting_stock_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->postJson("/api/v1/pos/inventory/{$this->scenario->inventoryItem->id}/adjust", []);

        $this->assertContract($response, 'POST api/v1/pos/inventory/{item}/adjust', '422-validation');
    }

    public function test_adjusting_stock_as_a_sale_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->postJson("/api/v1/pos/inventory/{$this->scenario->inventoryItem->id}/adjust", $this->adjustment(['type' => 'sale']));

        $this->assertContract($response, 'POST api/v1/pos/inventory/{item}/adjust', '422-sale-type');
    }

    public function test_adjusting_other_branch_stock_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->postJson("/api/v1/pos/inventory/{$this->scenario->branchBInventoryItem->id}/adjust", $this->adjustment());

        $this->assertContract($response, 'POST api/v1/pos/inventory/{item}/adjust', 'other-branch');
    }

    public function test_reference_inventory_list_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/references/inventory');

        $this->assertContract($response, 'GET api/v1/references/inventory');
    }

    public function test_creating_an_inventory_item_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson('/api/v1/references/inventory', [
            'name' => 'Cooking Oil',
            'quantity' => 20,
            'unit' => 'liters',
            'restock_threshold' => 5,
            'overstock_threshold' => 50,
            'cost_per_unit' => 95,
        ]);

        $this->assertContract($response, 'POST api/v1/references/inventory');
    }

    public function test_creating_an_inventory_item_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()->postJson('/api/v1/references/inventory', []);

        $this->assertContract($response, 'POST api/v1/references/inventory', '422-validation');
    }

    public function test_inventory_history_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/references/inventory/history');

        $this->assertContract($response, 'GET api/v1/references/inventory/history');
    }

    public function test_inventory_history_with_an_unknown_type_matches_contract(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/references/inventory/history?type=unknown');

        $this->assertContract($response, 'GET api/v1/references/inventory/history', '422-validation');
    }

    public function test_updating_an_inventory_item_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->putJson("/api/v1/references/inventory/{$this->scenario->inventoryItem->id}", $this->itemDetails());

        $this->assertContract($response, 'PUT api/v1/references/inventory/{item}');
    }

    public function test_updating_an_inventory_item_without_input_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->putJson("/api/v1/references/inventory/{$this->scenario->inventoryItem->id}", []);

        $this->assertContract($response, 'PUT api/v1/references/inventory/{item}', '422-validation');
    }

    public function test_updating_an_other_branch_inventory_item_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->putJson("/api/v1/references/inventory/{$this->scenario->branchBInventoryItem->id}", $this->itemDetails());

        $this->assertContract($response, 'PUT api/v1/references/inventory/{item}', 'other-branch');
    }

    public function test_deleting_an_inventory_item_without_history_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->deleteJson("/api/v1/references/inventory/{$this->scenario->archivedInventoryItem->id}");

        $this->assertContract($response, 'DELETE api/v1/references/inventory/{item}');
    }

    public function test_deleting_an_inventory_item_with_history_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->deleteJson("/api/v1/references/inventory/{$this->scenario->inventoryItem->id}");

        $this->assertContract($response, 'DELETE api/v1/references/inventory/{item}', '422-has-history');
    }

    public function test_deleting_an_other_branch_inventory_item_matches_contract(): void
    {
        $response = $this->asSupervisor()
            ->deleteJson("/api/v1/references/inventory/{$this->scenario->branchBInventoryItem->id}");

        $this->assertContract($response, 'DELETE api/v1/references/inventory/{item}', 'other-branch');
    }

    public function test_archiving_an_inventory_item_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->patchJson("/api/v1/references/inventory/{$this->scenario->inventoryItem->id}/archive");

        $this->assertContract($response, 'PATCH api/v1/references/inventory/{item}/archive');
    }

    public function test_archiving_an_other_branch_inventory_item_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->patchJson("/api/v1/references/inventory/{$this->scenario->branchBInventoryItem->id}/archive");

        $this->assertContract($response, 'PATCH api/v1/references/inventory/{item}/archive', 'other-branch');
    }

    public function test_unarchiving_an_inventory_item_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)
            ->patchJson("/api/v1/references/inventory/{$this->scenario->archivedInventoryItem->id}/unarchive");

        $this->assertContract($response, 'PATCH api/v1/references/inventory/{item}/unarchive');
    }

    public function test_unarchiving_an_other_branch_inventory_item_matches_contract(): void
    {
        $this->scenario->branchBInventoryItem->update(['is_archived' => true]);

        $response = $this->scenario->asStaff($this->scenario->manager)
            ->patchJson("/api/v1/references/inventory/{$this->scenario->branchBInventoryItem->id}/unarchive");

        $this->assertContract($response, 'PATCH api/v1/references/inventory/{item}/unarchive', 'other-branch');
    }

    public function test_inventory_item_logs_match_contract(): void
    {
        $response = $this->asSupervisor()
            ->getJson("/api/v1/references/inventory/{$this->scenario->inventoryItem->id}/logs");

        $this->assertContract($response, 'GET api/v1/references/inventory/{item}/logs');
    }

    public function test_other_branch_inventory_item_logs_match_contract(): void
    {
        $response = $this->asSupervisor()
            ->getJson("/api/v1/references/inventory/{$this->scenario->branchBInventoryItem->id}/logs");

        $this->assertContract($response, 'GET api/v1/references/inventory/{item}/logs', 'other-branch');
    }

    private function asSupervisor(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->supervisor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function adjustment(array $overrides = []): array
    {
        return [
            'type' => 'waste',
            'quantity' => 2,
            'direction' => 'deduct',
            'reason' => 'Spoiled sacks',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDetails(): array
    {
        return [
            'name' => 'Jasmine Rice',
            'unit' => 'kg',
            'restock_threshold' => 25,
            'overstock_threshold' => 450,
            'cost_per_unit' => 55,
        ];
    }
}
