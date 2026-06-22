<?php

namespace Tests\Feature;

use App\Enums\SchoolMonth;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchMonthlyAmountTest extends TestCase
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

    public function test_admin_can_list_branch_monthly_amounts(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/branch-monthly-amounts');

        $response->assertOk();
        $response->assertJsonCount(10, '*');
    }

    public function test_list_shows_configured_and_default_months(): void
    {
        BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::June->value,
            'year' => 2025,
            'days' => 20,
            'amount' => 20 * 135,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/branch-monthly-amounts?year=2025');

        $response->assertOk();

        $months = collect($response->json());

        $june = $months->firstWhere('school_month', 'june');
        $this->assertTrue($june['is_configured']);
        $this->assertEquals(20, $june['days']);

        $configuredCount = $months->where('is_configured', true)->count();
        $this->assertEquals(1, $configuredCount);

        $unconfiguredCount = $months->where('is_configured', false)->count();
        $this->assertEquals(9, $unconfiguredCount);
    }

    public function test_admin_can_create_month_config(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2025,
            'days' => 20,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('branch_monthly_amounts', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'year' => 2025,
            'days' => 20,
            'amount' => 20 * 135,
        ]);
    }

    public function test_store_updates_existing_record(): void
    {
        // First call creates
        $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2025,
            'days' => 20,
        ])->assertStatus(201);

        // Second call with same month+year updates in-place
        $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2025,
            'days' => 22,
        ])->assertOk();

        $this->assertDatabaseCount('branch_monthly_amounts', 1);
        $this->assertDatabaseHas('branch_monthly_amounts', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'year' => 2025,
            'days' => 22,
            'amount' => 22 * 135,
        ]);
    }

    public function test_admin_can_update_month_config(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::July->value,
            'year' => 2025,
            'days' => 18,
            'amount' => 18 * 135,
        ]);

        $response = $this->asAdmin()->putJson("/api/v1/branch-monthly-amounts/{$record->id}", [
            'days' => 22,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('branch_monthly_amounts', [
            'id' => $record->id,
            'days' => 22,
            'amount' => 22 * 135,
        ]);
    }

    public function test_admin_can_delete_month_config(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::August->value,
            'year' => 2025,
            'days' => 18,
            'amount' => 18 * 135,
        ]);

        $response = $this->asAdmin()->deleteJson("/api/v1/branch-monthly-amounts/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Month config deleted.');
        $this->assertDatabaseMissing('branch_monthly_amounts', ['id' => $record->id]);
    }

    public function test_store_accepts_explicit_amount_override(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2025,
            'days' => 20,
            'amount' => 3500,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('branch_monthly_amounts', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'year' => 2025,
            'days' => 20,
            'amount' => 3500,
        ]);
    }

    public function test_update_accepts_explicit_amount_override(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::June->value,
            'year' => 2025,
            'days' => 20,
            'amount' => 20 * 135,
        ]);

        $response = $this->asAdmin()->putJson("/api/v1/branch-monthly-amounts/{$record->id}", [
            'days' => 20,
            'amount' => 4000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('branch_monthly_amounts', [
            'id' => $record->id,
            'days' => 20,
            'amount' => 4000,
        ]);
    }

    public function test_admin_can_create_month_config_with_zero_days(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2026,
            'days' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('branch_monthly_amounts', [
            'branch_id' => $this->branch->id,
            'school_month' => 'june',
            'year' => 2026,
            'days' => 0,
            'amount' => 0,
        ]);
    }

    public function test_admin_can_update_month_config_to_zero_days(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::June->value,
            'year' => 2026,
            'days' => 20,
            'amount' => 20 * 135,
        ]);

        $response = $this->asAdmin()->putJson("/api/v1/branch-monthly-amounts/{$record->id}", [
            'days' => 0,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('branch_monthly_amounts', [
            'id' => $record->id,
            'days' => 0,
            'amount' => 0,
        ]);
    }

    public function test_store_rejects_amount_override_when_days_is_zero(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/branch-monthly-amounts', [
            'school_month' => 'june',
            'year' => 2026,
            'days' => 0,
            'amount' => 500,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_update_rejects_amount_override_when_days_is_zero(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::June->value,
            'year' => 2026,
            'days' => 20,
            'amount' => 20 * 135,
        ]);

        $response = $this->asAdmin()->putJson("/api/v1/branch-monthly-amounts/{$record->id}", [
            'days' => 0,
            'amount' => 500,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_cashier_cannot_manage_branch_monthly_amounts(): void
    {
        $record = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => SchoolMonth::September->value,
            'year' => 2025,
            'days' => 22,
            'amount' => 22 * 135,
        ]);

        $asCashier = $this->asUserWithRole('cashier');

        $asCashier->getJson('/api/v1/branch-monthly-amounts')->assertForbidden();
        $asCashier->postJson('/api/v1/branch-monthly-amounts', [])->assertForbidden();
        $asCashier->putJson("/api/v1/branch-monthly-amounts/{$record->id}", [])->assertForbidden();
        $asCashier->deleteJson("/api/v1/branch-monthly-amounts/{$record->id}")->assertForbidden();
    }
}
