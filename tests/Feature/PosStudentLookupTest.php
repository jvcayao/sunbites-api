<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosStudentLookupTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $cashier;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'test']);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_qr_lookup_returns_full_student_data_for_enrolled_student(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => EnrollmentStatus::Enrolled->value,
        ]);
        $student->deposit(10000); // ₱100.00 wallet

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => $student->qr_code,
        ]);

        $response->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.full_name', $student->full_name)
            ->assertJsonStructure(['student' => [
                'id', 'full_name', 'grade_level', 'wallet_balance', 'credit_balance', 'points',
            ]]);
    }

    public function test_qr_lookup_returns_422_for_non_enrolled_student(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => $student->qr_code,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'not_enrolled');
    }

    public function test_qr_lookup_returns_404_for_unknown_qr(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => 'SB-NOTEXIST1234',
        ]);

        $response->assertNotFound();
    }

    public function test_search_returns_minimal_data_without_wallet_balance(): void
    {
        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'search',
            'value' => 'Maria',
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonMissing(['wallet_balance'])
            ->assertJsonStructure(['students' => [['id', 'full_name', 'grade_level', 'enrollment_status']]]);
    }

    public function test_search_is_branch_scoped(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        Student::factory()->create([
            'branch_id' => $otherBranch->id,
            'first_name' => 'Other',
            'last_name' => 'Branch',
        ]);

        Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'My',
            'last_name' => 'Branch',
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'search',
            'value' => 'Branch',
        ]);

        $response->assertOk()->assertJsonCount(1, 'students');
    }

    public function test_search_returns_maximum_8_results(): void
    {
        Student::factory()->count(10)->create([
            'branch_id' => $this->branch->id,
            'last_name' => 'Garcia',
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'search',
            'value' => 'Garcia',
        ]);

        $response->assertOk();
        $this->assertCount(8, $response->json('students'));
    }

    public function test_lookup_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => 'SB-test12345678',
        ]);

        $response->assertUnauthorized();
    }
}
