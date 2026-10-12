<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class BillingReportContractTest extends ContractTestCase
{
    public function test_billing_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/billing');

        $this->assertContract($response, 'GET api/v1/reports/billing');
    }

    public function test_billing_report_with_an_invalid_year_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/billing?year=1999');

        $this->assertContract($response, 'GET api/v1/reports/billing', '422-validation');
    }

    public function test_billing_export_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->get('/api/v1/reports/billing/export');

        $this->assertFileContract($response, 'GET api/v1/reports/billing/export');
    }

    public function test_billing_export_with_an_invalid_year_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/billing/export?year=1999');

        $this->assertContract($response, 'GET api/v1/reports/billing/export', '422-validation');
    }
}
