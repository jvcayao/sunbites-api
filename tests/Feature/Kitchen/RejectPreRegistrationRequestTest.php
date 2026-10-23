<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The pending check still runs before the rejection reason is validated (Spec 16 T-8.2,
 * design §4.2.2, hazard H4).
 */
class RejectPreRegistrationRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * Debug mode is on: the exact body also proves the guard is a JSON response, not `abort()` (H3).
     */
    public function test_rejecting_an_approved_record_without_a_reason_returns_422_only_pending(): void
    {
        config(['app.debug' => true]);
        $branch = Branch::factory()->create(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $preRegistration = PreRegistration::factory()->approved()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($manager, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])
            ->postJson("/api/v1/pre-registrations/{$preRegistration->id}/reject");

        $response->assertUnprocessable()->assertExactJson(['message' => 'Only pending pre-registrations can be rejected.']);
        $this->assertSame('approved', $preRegistration->fresh()->status->value);
    }
}
