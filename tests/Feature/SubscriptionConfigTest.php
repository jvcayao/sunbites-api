<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'cfg']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_admin_can_get_subscription_config_with_defaults_when_no_row_exists(): void
    {
        $response = $this->asUser($this->admin)->getJson('/api/v1/pos/subscription-config');

        $response->assertOk()
            ->assertJson([
                'meal_daily_limit' => 1,
                'snack_daily_limit' => 1,
                'drink_daily_limit' => 1,
                'extra_daily_limit' => 1,
            ]);

        // GET should not persist a row — defaults are returned without a DB write
        $this->assertDatabaseMissing('branch_subscription_configs', [
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_admin_can_update_subscription_config(): void
    {
        $response = $this->asUser($this->admin)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => 2,
            'snack_daily_limit' => 3,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 0,
        ]);

        $response->assertOk()
            ->assertJson([
                'meal_daily_limit' => 2,
                'snack_daily_limit' => 3,
                'drink_daily_limit' => 1,
                'extra_daily_limit' => 0,
            ]);

        $this->assertDatabaseHas('branch_subscription_configs', [
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 2,
            'snack_daily_limit' => 3,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 0,
        ]);
    }

    public function test_manager_can_get_and_update_subscription_config(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $getResponse = $this->asUser($manager)->getJson('/api/v1/pos/subscription-config');
        $getResponse->assertOk();

        $putResponse = $this->asUser($manager)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => 2,
            'snack_daily_limit' => 2,
            'drink_daily_limit' => 2,
            'extra_daily_limit' => 2,
        ]);
        $putResponse->assertOk();
    }

    public function test_cashier_cannot_update_subscription_config(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->asUser($cashier)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => 5,
            'snack_daily_limit' => 5,
            'drink_daily_limit' => 5,
            'extra_daily_limit' => 5,
        ]);

        $response->assertForbidden();
    }

    public function test_validation_rejects_limit_above_10(): void
    {
        $response = $this->asUser($this->admin)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => 11,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['meal_daily_limit']);
    }

    public function test_validation_rejects_negative_limit(): void
    {
        $response = $this->asUser($this->admin)->putJson('/api/v1/pos/subscription-config', [
            'meal_daily_limit' => -1,
            'snack_daily_limit' => 1,
            'drink_daily_limit' => 1,
            'extra_daily_limit' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['meal_daily_limit']);
    }
}
