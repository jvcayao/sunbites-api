<?php

namespace Tests\Feature\Contract\Public;

use Tests\Feature\Contract\ContractTestCase;

class KioskLookupContractTest extends ContractTestCase
{
    private const string ROUTE = 'POST api/v1/public/kiosk/lookup';

    public function test_kiosk_lookup_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => $this->scenario->subscriptionStudent->qr_code]);

        $this->assertContract($response, self::ROUTE);
    }

    public function test_kiosk_lookup_without_a_qr_code_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/public/kiosk/lookup', []);

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_kiosk_lookup_of_an_unknown_qr_code_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-UNKNOWNCODE1']);

        $this->assertContract($response, self::ROUTE, '404');
    }

    public function test_kiosk_lookup_of_a_non_enrolled_student_matches_contract(): void
    {
        $this->scenario->nonSubscriptionStudent->update(['enrollment_status' => 'banned']);

        $response = $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => $this->scenario->nonSubscriptionStudent->qr_code]);

        $this->assertContract($response, self::ROUTE, '403-not-eligible');
    }
}
