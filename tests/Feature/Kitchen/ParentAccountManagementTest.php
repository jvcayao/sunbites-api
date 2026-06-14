<?php

namespace Tests\Feature\Kitchen;

use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    // -------------------------------------------------------------------------
    // enable
    // -------------------------------------------------------------------------

    public function test_admin_can_enable_a_disabled_parent(): void
    {
        Mail::fake();

        $parent = ParentUser::factory()->disabled()->create();

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/enable");

        $response->assertOk();
        $response->assertJson(['message' => 'Parent access enabled. Activation email queued.']);
        $this->assertNull($parent->fresh()->disabled_at);
        $this->assertNull($parent->fresh()->email_verified_at);
        Mail::assertQueued(ParentWelcomeMail::class);
    }

    public function test_enabling_an_already_enabled_parent_is_idempotent(): void
    {
        Mail::fake();

        $parent = ParentUser::factory()->create(); // active, enabled

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/enable");

        $response->assertOk();
        $this->assertNull($parent->fresh()->disabled_at);
        $this->assertNull($parent->fresh()->email_verified_at);
        Mail::assertQueued(ParentWelcomeMail::class);
    }

    // -------------------------------------------------------------------------
    // destroy (soft delete)
    // -------------------------------------------------------------------------

    public function test_admin_can_soft_delete_a_parent(): void
    {
        $parent = ParentUser::factory()->create();
        $parent->createToken('portal-token', ['parent']);

        $response = $this->asAdmin()->deleteJson("/api/v1/references/parents/{$parent->id}");

        $response->assertOk();
        $response->assertJson(['message' => 'Parent account deleted.']);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertCount(0, $parent->tokens()->get());
    }

    // -------------------------------------------------------------------------
    // restore
    // -------------------------------------------------------------------------

    public function test_admin_can_restore_a_soft_deleted_parent(): void
    {
        Mail::fake();

        $parent = ParentUser::factory()->create();
        $parent->delete();

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/restore");

        $response->assertOk();
        $response->assertJson(['message' => 'Parent account restored. Activation email queued.']);
        $this->assertNull($parent->fresh()->deleted_at);
        $this->assertNull($parent->fresh()->disabled_at);
        $this->assertNull($parent->fresh()->email_verified_at);
        Mail::assertQueued(ParentWelcomeMail::class);
    }

    public function test_restoring_a_non_deleted_parent_returns_404(): void
    {
        $parent = ParentUser::factory()->create(); // not deleted

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/restore");

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // portal login — disabled parent
    // -------------------------------------------------------------------------

    public function test_disabled_parent_cannot_login_to_portal(): void
    {
        $parent = ParentUser::factory()->disabled()->create();

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => $parent->email,
            'password' => 'Password1',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'account_disabled']);
    }
}
