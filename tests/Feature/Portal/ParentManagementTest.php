<?php

namespace Tests\Feature\Portal;

use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    private Student $student;

    private ParentUser $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->manager->id,
            'wallet_alert_threshold' => 50,
        ]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_manager_can_list_parents(): void
    {
        $response = $this->asManager()->getJson('/api/v1/references/parents');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'email' => 'maria.santos@example.com',
                'is_activated' => true,
            ]);
    }

    public function test_parent_list_includes_linked_student_count(): void
    {
        $response = $this->asManager()->getJson('/api/v1/references/parents');

        $response->assertOk()
            ->assertJsonFragment(['students_count' => 1]);
    }

    public function test_manager_can_search_parents_by_name(): void
    {
        ParentUser::create([
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'email' => 'pedro.reyes@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $response = $this->asManager()->getJson('/api/v1/references/parents?search=Santos');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['email' => 'maria.santos@example.com']);
    }

    public function test_manager_can_view_parent_detail(): void
    {
        $response = $this->asManager()->getJson("/api/v1/references/parents/{$this->parent->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $this->parent->id,
                'email' => 'maria.santos@example.com',
                'is_activated' => true,
            ])
            ->assertJsonStructure(['students' => [['id', 'student_number', 'full_name', 'grade_level', 'branch_name', 'wallet_alert_threshold']]]);
    }

    public function test_unactivated_parent_shows_correct_status(): void
    {
        $unactivated = ParentUser::create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'email' => 'jose.rizal@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);
        $unactivated->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->manager->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asManager()->getJson("/api/v1/references/parents/{$unactivated->id}");

        $response->assertOk()
            ->assertJsonFragment(['is_activated' => false]);
    }

    public function test_manager_can_resend_activation_email(): void
    {
        Mail::fake();

        $this->asManager()
            ->postJson("/api/v1/references/parents/{$this->parent->id}/resend-activation")
            ->assertOk()
            ->assertJson(['message' => 'Activation email sent.']);

        Mail::assertQueued(ParentWelcomeMail::class, fn ($mail) => $mail->hasTo('maria.santos@example.com'));
    }

    public function test_unauthenticated_cannot_access_parent_management(): void
    {
        $this->getJson('/api/v1/references/parents')->assertUnauthorized();
    }

    public function test_non_manager_cannot_access_parent_management(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        Sanctum::actingAs($cashier, ['staff']);

        $this->getJson('/api/v1/references/parents')->assertForbidden();
    }
}
