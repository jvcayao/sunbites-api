<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The student list filters were never validated, so their rules only accept anything
 * (Spec 16 T-5.2, design §4.2.3, hazard H8).
 */
class ListStudentsRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_unchecked_filter_values_still_return_200(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $removed = Student::factory()->create(['branch_id' => $branch->id]);
        $removed->delete();
        Student::factory()->create(['branch_id' => $branch->id]);
        Sanctum::actingAs($manager, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->getJson('/api/v1/students?deleted=1&type=all&month=june&year=abc');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $removed->id);
    }
}
