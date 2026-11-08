<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The contact ownership check still runs before validation (Spec 16 T-5.4, design §4.2.2).
 */
class UpdateStudentContactRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * Debug mode is on so the mechanism is visible (hazard H3): the guard is an `abort()`.
     */
    public function test_updating_another_students_contact_with_an_invalid_email_returns_404(): void
    {
        config(['app.debug' => true]);
        $branch = Branch::factory()->create(['is_active' => true]);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);
        $contact = StudentContact::factory()->create([
            'student_id' => Student::factory()->create(['branch_id' => $branch->id])->id,
            'email' => 'keep@example.com',
        ]);
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])
            ->putJson("/api/v1/students/{$student->id}/contacts/{$contact->id}", ['email' => 'not-an-email']);

        $response->assertNotFound()
            ->assertJsonPath('message', 'Contact does not belong to this student.')
            ->assertJsonPath('exception', NotFoundHttpException::class);
        $this->assertSame('keep@example.com', $contact->fresh()->email);
    }
}
