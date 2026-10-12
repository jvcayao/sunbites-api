<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class SubscriptionConfigContractTest extends ContractTestCase
{
    public function test_subscription_config_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/pos/subscription-config');

        $this->assertContract($response, 'GET api/v1/pos/subscription-config');
    }

    public function test_updating_subscription_config_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => 1,
            'snack_daily_limit' => 2,
            'drink_daily_limit' => 2,
            'extra_daily_limit' => 1,
        ]);

        $this->assertContract($response, 'PUT api/v1/pos/subscription-config');
    }

    public function test_updating_subscription_config_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->putJson('/api/v1/pos/subscription-config', []);

        $this->assertContract($response, 'PUT api/v1/pos/subscription-config', '422-validation');
    }
}
