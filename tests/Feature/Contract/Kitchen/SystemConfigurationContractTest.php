<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class SystemConfigurationContractTest extends ContractTestCase
{
    public function test_system_configuration_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)->getJson('/api/v1/system-configurations');

        $this->assertContract($response, 'GET api/v1/system-configurations');
    }

    public function test_updating_a_configuration_value_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)->putJson('/api/v1/system-configurations/credit_limit', ['value' => 500]);

        $this->assertContract($response, 'PUT api/v1/system-configurations/{key}');
    }

    public function test_updating_a_configuration_with_a_non_numeric_value_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)->putJson('/api/v1/system-configurations/credit_limit', ['value' => 'abc']);

        $this->assertContract($response, 'PUT api/v1/system-configurations/{key}', '422-validation');
    }

    public function test_updating_an_unknown_configuration_key_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->admin)->putJson('/api/v1/system-configurations/unknown_key', ['value' => 500]);

        $this->assertContract($response, 'PUT api/v1/system-configurations/{key}', '404');
    }
}
