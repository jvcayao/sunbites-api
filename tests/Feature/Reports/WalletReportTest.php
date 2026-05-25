<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_supervisor_cannot_access_wallet_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/wallet');

        $response->assertForbidden();
    }

    public function test_admin_can_access_wallet_report(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'summary']);
    }

    public function test_manager_can_access_wallet_report(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
    }

    public function test_wallet_report_summary_includes_net_movement(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_credits', 'total_debits', 'net_movement', 'students_below_100'],
            ]);
    }

    public function test_supervisor_cannot_export_wallet_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/wallet/export');

        $response->assertForbidden();
    }

    public function test_manager_can_export_wallet_report(): void
    {
        Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson('/api/v1/reports/wallet/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_wallet_report_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create();
        Student::factory()->count(3)->create(['branch_id' => $otherBranch->id]);
        Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/wallet');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
