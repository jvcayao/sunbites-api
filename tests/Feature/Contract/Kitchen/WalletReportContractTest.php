<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class WalletReportContractTest extends ContractTestCase
{
    public function test_wallet_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/wallet');

        $this->assertContract($response, 'GET api/v1/reports/wallet');
    }

    public function test_wallet_report_with_an_unknown_sort_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/wallet?sort=unknown');

        $this->assertContract($response, 'GET api/v1/reports/wallet', '422-validation');
    }

    public function test_wallet_export_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->get('/api/v1/reports/wallet/export');

        $this->assertFileContract($response, 'GET api/v1/reports/wallet/export');
    }
}
