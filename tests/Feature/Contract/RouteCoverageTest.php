<?php

namespace Tests\Feature\Contract;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteRegistry;

class RouteCoverageTest extends ContractTestCase
{
    /**
     * The application's API routes on 2026-09-26 (`route:list --path=api --except-vendor`).
     * Spec 16 B-04 removes the two unused portal pre-registration routes (163). Any other
     * change to this number is an added or removed endpoint, which must be deliberate.
     */
    private const int EXPECTED_API_ROUTE_COUNT = 163;

    /**
     * Coverage reads only route definitions and fixture files, so no scenario is built.
     */
    protected function setUpScenario(): void {}

    public function test_every_api_route_has_an_ok_contract_fixture(): void
    {
        if (! is_dir($this->contractFixturesDirectory())) {
            $this->markTestSkipped(self::FIXTURES_ABSENT_MESSAGE);
        }

        $missing = [];

        foreach ($this->apiRouteKeys() as $routeKey) {
            if (! is_file($this->contractFixturesDirectory().'/'.$this->contractSlug($routeKey).'@ok.json')) {
                $missing[] = "Route {$routeKey} has no contract fixture.";
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_the_api_exposes_the_expected_number_of_routes(): void
    {
        $this->assertCount(self::EXPECTED_API_ROUTE_COUNT, $this->apiRouteKeys());
    }

    /**
     * Routes under `api/` handled by the application (`App\` controllers, or a closure such as
     * `api/health`). This leaves out the two framework-owned
     * `Illuminate\Broadcasting\BroadcastController@authenticate` routes, whose authentication is
     * covered by RoleAuthorizationMatrixTest's 401 checks.
     *
     * @return list<string>
     */
    private function apiRouteKeys(): array
    {
        return collect(RouteRegistry::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            ->filter(fn (Route $route): bool => $route->getActionName() === 'Closure'
                || str_starts_with($route->getActionName(), 'App\\'))
            ->map(fn (Route $route): string => collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD').' '.$route->uri())
            ->values()
            ->all();
    }
}
