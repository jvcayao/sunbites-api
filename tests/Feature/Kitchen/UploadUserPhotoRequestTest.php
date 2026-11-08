<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The policy check runs before the photo is validated (Spec 16 T-6.2, hazard H4).
 */
class UploadUserPhotoRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);
    }

    public function test_manager_uploading_without_a_file_for_a_user_the_policy_denies_gets_403(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        Sanctum::actingAs($manager, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->postJson("/api/v1/users/{$cashier->id}/photo");

        $response->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }
}
