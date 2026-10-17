<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The `api/*` renderable in `bootstrap/app.php` replaces the framework's 403 and 404 bodies
 * for policy denials and model lookups, and leaves every `abort()` text and the `role:`
 * middleware text as they are (Spec 16 B-23, design §5.21).
 */
class ApiExceptionRenderingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    /**
     * Debug mode is off, as in production and CI, so error bodies have no exception details
     * (the local `.env.testing` turns debug on; Spec 16 design §4.1.2).
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    public function test_policy_denial_returns_403_with_the_forbidden_message(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        Sanctum::actingAs(ParentUser::factory()->create(), ['parent'], 'parents');

        $response = $this->getJson("/api/v1/portal/students/{$student->id}/wallet");

        $response->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_missing_route_model_returns_404_with_the_not_found_message(): void
    {
        $manager = $this->staff('manager');

        $response = $this->asStaff($manager)->getJson('/api/v1/students/999999');

        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_manager_assigning_the_admin_role_keeps_its_abort_message_on_403(): void
    {
        $manager = $this->staff('manager');

        $response = $this->asStaff($manager)->postJson('/api/v1/users', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'email' => 'ana@sunbites.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => 'admin',
        ]);

        $response->assertForbidden()->assertExactJson(['message' => 'Only an admin may assign the admin role.']);
        $this->assertDatabaseMissing('users', ['email' => 'ana@sunbites.test']);
    }

    public function test_contact_of_another_student_keeps_its_abort_message_on_404(): void
    {
        $supervisor = $this->staff('supervisor');
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $otherStudent = Student::factory()->create(['branch_id' => $this->branch->id]);
        $contact = StudentContact::factory()->create(['student_id' => $otherStudent->id, 'phone' => '09170000000']);

        $response = $this->asStaff($supervisor)->putJson("/api/v1/students/{$student->id}/contacts/{$contact->id}", ['phone' => '09181110000']);

        $response->assertNotFound()->assertExactJson(['message' => 'Contact does not belong to this student.']);
        $this->assertSame('09170000000', $contact->fresh()->phone);
    }

    public function test_cashier_on_a_manager_route_keeps_the_role_middleware_message_on_403(): void
    {
        $cashier = $this->staff('cashier');

        $response = $this->asStaff($cashier)->getJson('/api/v1/users');

        $response->assertForbidden()->assertExactJson(['message' => 'User does not have the right roles.']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function asStaff(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
