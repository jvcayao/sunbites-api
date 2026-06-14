<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentAccountManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asUserWithRole(string $role): static
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    // -------------------------------------------------------------------------
    // disable
    // -------------------------------------------------------------------------

    public function test_admin_can_disable_an_active_parent(): void
    {
        $parent = ParentUser::factory()->create();
        $parent->createToken('portal-token', ['parent']);

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/disable");

        $response->assertOk();
        $response->assertJson(['message' => 'Parent access disabled.']);
        $this->assertNotNull($parent->fresh()->disabled_at);
        $this->assertCount(0, $parent->tokens()->get());
    }
}
