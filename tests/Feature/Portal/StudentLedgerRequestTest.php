<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The portal ledger checks the policy before it validates the query (Spec 16 T-4.4).
 */
class StudentLedgerRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unlinked_parent_with_an_invalid_per_page_gets_403_not_422(): void
    {
        config(['app.debug' => false]);
        $student = Student::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        Sanctum::actingAs(ParentUser::factory()->create(), ['parent'], 'parents');

        $response = $this->getJson("/api/v1/portal/students/{$student->id}/ledger?per_page=999");

        $response->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }
}
