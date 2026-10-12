<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class PreRegistrationCheckContractTest extends ContractTestCase
{
    public function test_pre_registration_check_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations/check', [
            'branch_id' => $this->scenario->branchA->id,
            'first_name' => 'Paolo',
            'last_name' => 'Mercado',
            'birthday' => '2019-05-05',
            'email' => 'grace.mercado@example.test',
            'phone' => '09195556666',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/pre-registrations/check');
    }

    public function test_pre_registration_check_without_input_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations/check', []);

        $this->assertContract($response, 'POST api/v1/portal/pre-registrations/check', '422-validation');
    }
}
