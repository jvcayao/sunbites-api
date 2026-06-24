<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\Order;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentActivityFilterTest extends TestCase
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

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

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

    public function test_payment_method_filter_returns_only_cash_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=cash");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'cash');
    }

    public function test_payment_method_filter_returns_only_wallet_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=wallet");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'wallet');
    }

    public function test_spending_total_reflects_filtered_results(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 50.0]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total' => 100.0]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=cash");

        $response->assertOk()
            ->assertJsonPath('spending_total', 50);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity?payment_method=gcash");

        $response->assertUnprocessable();
    }

    public function test_no_filter_returns_all_orders(): void
    {
        Order::factory()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id]);
        Order::factory()->wallet()->create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id]);

        $response = $this->asParent()
            ->getJson("/api/v1/portal/students/{$this->student->id}/activity");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
