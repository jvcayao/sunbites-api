<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class SalesReportContractTest extends ContractTestCase
{
    public function test_sales_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/sales');

        $this->assertContract($response, 'GET api/v1/reports/sales');
    }

    public function test_sales_report_with_an_invalid_page_size_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/sales?per_page=0');

        $this->assertContract($response, 'GET api/v1/reports/sales', '422-validation');
    }

    public function test_sales_export_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->get('/api/v1/reports/sales/export');

        $this->assertFileContract($response, 'GET api/v1/reports/sales/export');
    }

    public function test_sales_export_with_an_invalid_date_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/sales/export?date_from=not-a-date');

        $this->assertContract($response, 'GET api/v1/reports/sales/export', '422-validation');
    }
}
