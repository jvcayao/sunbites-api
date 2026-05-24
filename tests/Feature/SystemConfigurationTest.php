<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemConfiguration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemConfigurationSeeder::class);

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

    public function test_admin_can_list_all_configurations(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/system-configurations');

        $response->assertOk();
        $response->assertJsonCount(3);

        $keys = collect($response->json())->pluck('key')->sort()->values()->all();
        $this->assertEquals(['credit_limit', 'daily_meal_rate', 'loyalty_point_threshold'], $keys);
    }

    public function test_admin_can_update_daily_meal_rate(): void
    {
        Cache::forget('system_config.daily_meal_rate');

        $response = $this->asAdmin()->putJson('/api/v1/system-configurations/daily_meal_rate', [
            'value' => '150',
        ]);

        $response->assertOk();
        $response->assertJsonPath('key', 'daily_meal_rate');
        $response->assertJsonPath('value', '150');

        // Cache is busted — getValue reads fresh value from DB
        $this->assertEquals(150.0, SystemConfiguration::getValue('daily_meal_rate'));
    }

    public function test_update_rejects_negative_value_for_decimal_type(): void
    {
        $response = $this->asAdmin()->putJson('/api/v1/system-configurations/daily_meal_rate', [
            'value' => '-10',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['value']);
    }

    public function test_update_rejects_non_numeric_value_for_decimal_type(): void
    {
        $response = $this->asAdmin()->putJson('/api/v1/system-configurations/daily_meal_rate', [
            'value' => 'not-a-number',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['value']);
    }

    public function test_update_returns_404_for_unknown_key(): void
    {
        $this->asAdmin()->putJson('/api/v1/system-configurations/nonexistent_key', [
            'value' => '100',
        ])->assertNotFound();
    }

    public function test_manager_cannot_access_system_configurations(): void
    {
        $this->asUserWithRole('manager')->getJson('/api/v1/system-configurations')->assertForbidden();
        $this->asUserWithRole('manager')->putJson('/api/v1/system-configurations/daily_meal_rate', ['value' => '200'])->assertForbidden();
    }

    public function test_supervisor_cannot_access_system_configurations(): void
    {
        $this->asUserWithRole('supervisor')->getJson('/api/v1/system-configurations')->assertForbidden();
        $this->asUserWithRole('supervisor')->putJson('/api/v1/system-configurations/daily_meal_rate', ['value' => '200'])->assertForbidden();
    }

    public function test_cashier_cannot_access_system_configurations(): void
    {
        $this->asUserWithRole('cashier')->getJson('/api/v1/system-configurations')->assertForbidden();
        $this->asUserWithRole('cashier')->putJson('/api/v1/system-configurations/daily_meal_rate', ['value' => '200'])->assertForbidden();
    }
}
