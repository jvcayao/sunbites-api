<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentWalletFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $student;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        $this->staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::factory()->create();

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staff->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_type_deposit_returns_only_deposit_transactions(): void
    {
        $this->student->deposit(5000); // ₱50 — type: deposit
        $this->student->withdraw(1000); // ₱10 — type: withdraw

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=deposit");

        $response->assertOk();

        $types = collect($response->json('data'))->pluck('type')->unique()->values()->all();
        $this->assertSame(['deposit'], $types);
    }

    public function test_type_withdraw_returns_only_withdraw_transactions(): void
    {
        $this->student->deposit(5000);
        $this->student->withdraw(1000);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=withdraw");

        $response->assertOk();

        $types = collect($response->json('data'))->pluck('type')->unique()->values()->all();
        $this->assertSame(['withdraw'], $types);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?type=transfer");

        $response->assertUnprocessable();
    }

    public function test_no_type_filter_returns_all_transactions(): void
    {
        $this->student->deposit(5000);
        $this->student->withdraw(1000);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_date_range_filter_returns_only_transactions_in_range(): void
    {
        // Create a deposit
        $this->student->deposit(5000);

        // Filter to yesterday — should return nothing
        $yesterday = now()->subDay()->format('Y-m-d');
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?from={$yesterday}&to={$yesterday}");

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        // Filter to today — should return the deposit
        $today = now()->format('Y-m-d');
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/wallet?from={$today}&to={$today}");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
