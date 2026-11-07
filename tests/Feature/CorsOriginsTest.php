<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T-24 (design §18.2): nothing tests CORS today, and a slip in config/cors.php would
 * block both production frontends with nothing to catch it.
 */
class CorsOriginsTest extends TestCase
{
    use RefreshDatabase;

    private function preflight(string $origin): TestResponse
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');
    }

    public function test_production_frontends_are_allowed_origins(): void
    {
        foreach (['https://pos.sunbites.com.ph', 'https://portal.sunbites.com.ph'] as $origin) {
            $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_staging_frontends_are_not_allowed_origins(): void
    {
        foreach (['https://pos-staging.sunbites.com.ph', 'https://portal-staging.sunbites.com.ph'] as $origin) {
            $this->preflight($origin)->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
}
