<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class InventoryReportContractTest extends ContractTestCase
{
    public function test_inventory_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/inventory');

        $this->assertContract($response, 'GET api/v1/reports/inventory');
    }

    public function test_inventory_report_with_an_unknown_type_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/inventory?type=unknown');

        $this->assertContract($response, 'GET api/v1/reports/inventory', '422-validation');
    }

    public function test_inventory_export_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->get('/api/v1/reports/inventory/export');

        $this->assertFileContract($response, 'GET api/v1/reports/inventory/export');
    }

    public function test_inventory_export_with_an_invalid_date_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/inventory/export?from=not-a-date');

        $this->assertContract($response, 'GET api/v1/reports/inventory/export', '422-validation');
    }
}
