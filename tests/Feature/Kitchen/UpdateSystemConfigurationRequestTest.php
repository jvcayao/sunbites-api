<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The configuration lookup still runs before the value is validated (Spec 16 T-6.3).
 */
class UpdateSystemConfigurationRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);
    }

    public function test_unknown_key_with_an_empty_body_returns_404_not_422(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        Sanctum::actingAs($admin, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->putJson('/api/v1/system-configurations/unknown_key', []);

        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
}
