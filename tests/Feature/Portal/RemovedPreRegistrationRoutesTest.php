<?php

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The portal's own pre-registration endpoints had no frontend caller (the portal posts to
 * /public/pre-registrations) and were removed (Spec 16 B-04).
 */
class RemovedPreRegistrationRoutesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_submitting_a_portal_pre_registration_returns_404(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', []);

        $response->assertNotFound();
    }

    public function test_checking_a_portal_pre_registration_returns_404(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations/check', []);

        $response->assertNotFound();
    }
}
