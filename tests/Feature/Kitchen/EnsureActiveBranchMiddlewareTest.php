<?php

namespace Tests\Feature\Kitchen;

use App\Http\Middleware\EnsureActiveBranch;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every kitchen route needs an active branch, except the routes the POS calls before a branch is
 * chosen and the ones that are not branch data (Spec 16 B-02, design §5.2).
 */
class EnsureActiveBranchMiddlewareTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NO_ACTIVE_BRANCH = ['message' => 'No active branch selected. Please set a branch first.'];

    /**
     * The design §5.2 allowlist as "METHOD uri".
     */
    private const ROUTES_WITHOUT_A_BRANCH = [
        'DELETE api/v1/staff/notifications/{id}',
        'DELETE api/v1/users/{user}/branches/{branch}',
        'GET api/v1/auth/user',
        'GET api/v1/branches',
        'GET api/v1/staff/notifications',
        'GET api/v1/staff/notifications/unread-count',
        'GET api/v1/system-configurations',
        'GET api/v1/users',
        'GET api/v1/users/{user}',
        'PATCH api/v1/staff/notifications/{id}/read',
        'POST api/v1/auth/branch',
        'POST api/v1/auth/logout',
        'POST api/v1/branches/{branch}/toggle',
        'POST api/v1/broadcasting/auth',
        'POST api/v1/staff/notifications/mark-all-read',
        'POST api/v1/users',
        'POST api/v1/users/{user}/branches',
        'POST api/v1/users/{user}/deactivate',
        'POST api/v1/users/{user}/photo',
        'POST api/v1/users/{user}/reactivate',
        'POST api/v1/users/{user}/reset-password',
        'PUT api/v1/branches/{branch}',
        'PUT api/v1/system-configurations/{key}',
        'PUT api/v1/users/{user}',
    ];

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    public function test_only_the_allowlisted_kitchen_routes_skip_the_branch_requirement(): void
    {
        $kitchenRoutes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('ability:staff', $route->gatherMiddleware(), true));

        $withoutBranchRequirement = $kitchenRoutes
            ->reject(fn (Route $route): bool => in_array(EnsureActiveBranch::class, Router::gatherRouteMiddleware($route), true))
            ->map(fn (Route $route): string => $route->methods()[0].' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame(self::ROUTES_WITHOUT_A_BRANCH, $withoutBranchRequirement);
    }

    public function test_a_branch_data_route_without_a_branch_header_returns_422(): void
    {
        Sanctum::actingAs($this->cashier(), ['staff']);

        $response = $this->getJson('/api/v1/pos/transactions');

        $response->assertUnprocessable();
        $this->assertSame(self::NO_ACTIVE_BRANCH, $response->json());
    }

    public function test_a_header_naming_no_active_branch_returns_422(): void
    {
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $cashier = $this->cashier();
        $cashier->branches()->attach($inactiveBranch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($cashier, ['staff']);

        $response = $this->withHeader('X-Branch-Id', (string) $inactiveBranch->id)->getJson('/api/v1/pos/transactions');

        $response->assertUnprocessable();
        $this->assertSame(self::NO_ACTIVE_BRANCH, $response->json());
    }

    public function test_allowlisted_routes_answer_without_a_branch_header(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['staff']);

        $statuses = [
            'GET auth/user' => $this->getJson('/api/v1/auth/user')->status(),
            'POST auth/branch' => $this->postJson('/api/v1/auth/branch', ['branch_id' => $this->branch->id])->status(),
            'GET branches' => $this->getJson('/api/v1/branches')->status(),
            'GET system-configurations' => $this->getJson('/api/v1/system-configurations')->status(),
            'GET users' => $this->getJson('/api/v1/users')->status(),
            'GET staff/notifications' => $this->getJson('/api/v1/staff/notifications')->status(),
            'GET staff/notifications/unread-count' => $this->getJson('/api/v1/staff/notifications/unread-count')->status(),
        ];

        $this->assertSame(array_fill_keys(array_keys($statuses), 200), $statuses);
    }

    public function test_an_unauthenticated_request_without_a_branch_header_returns_401_not_422(): void
    {
        $response = $this->getJson('/api/v1/pos/transactions');

        $response->assertUnauthorized();
        $this->assertSame(['message' => 'Unauthenticated.'], $response->json());
    }

    private function cashier(): User
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $cashier;
    }
}
