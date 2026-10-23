<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The POS transaction list's student search (Spec 16 B-10).
 */
class TransactionSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_student_search_matches_percent_and_underscore_literally(): void
    {
        $fiveHundred = $this->orderFor('Ana500', 'N-0001');
        $this->orderFor('Anaaxb', 'N-0002');
        $underscore = $this->orderFor('Anaa_b', 'N-0003');
        $idsFor = fn (string $term): array => collect($this->asCashier()->getJson('/api/v1/pos/transactions?'.http_build_query(['student_search' => $term]))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(
            ['50%' => [], 'a_b' => [$underscore->id], '500' => [$fiveHundred->id]],
            ['50%' => $idsFor('50%'), 'a_b' => $idsFor('a_b'), '500' => $idsFor('500')],
        );
    }

    private function orderFor(string $firstName, string $studentNumber): Order
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => $firstName,
            'last_name' => 'Reyes',
            'student_number' => $studentNumber,
        ]);

        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $this->cashier->id,
        ]);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
