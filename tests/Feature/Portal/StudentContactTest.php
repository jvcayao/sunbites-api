<?php

namespace Tests\Feature\Portal;

use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentContactTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_manager_can_list_student_contacts(): void
    {
        StudentContact::factory()->create(['student_id' => $this->student->id, 'is_primary' => true]);
        StudentContact::factory()->create(['student_id' => $this->student->id, 'is_primary' => false]);

        $response = $this->asManager()
            ->getJson("/api/v1/students/{$this->student->id}/contacts");

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_manager_can_add_contact_without_email(): void
    {
        $response = $this->asManager()
            ->postJson("/api/v1/students/{$this->student->id}/contacts", [
                'full_name' => 'Rosa Santos',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => null,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('student_contacts', ['full_name' => 'Rosa Santos', 'student_id' => $this->student->id]);
    }

    public function test_adding_contact_with_email_provisions_parent(): void
    {
        Mail::fake();

        $this->asManager()
            ->postJson("/api/v1/students/{$this->student->id}/contacts", [
                'full_name' => 'Maria Dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'contact-provision@example.com',
            ])->assertCreated();

        $this->assertDatabaseHas('parents', ['email' => 'contact-provision@example.com']);
        Mail::assertQueued(ParentWelcomeMail::class);
    }

    public function test_cannot_add_more_than_three_contacts(): void
    {
        StudentContact::factory()->count(3)->create(['student_id' => $this->student->id]);

        $this->asManager()
            ->postJson("/api/v1/students/{$this->student->id}/contacts", [
                'full_name' => 'Fourth Contact',
                'relationship' => 'Uncle',
                'phone' => '09181234567',
                'address' => '789 Side St',
            ])->assertUnprocessable();
    }

    public function test_manager_can_update_contact(): void
    {
        $contact = StudentContact::factory()->create(['student_id' => $this->student->id]);

        $this->asManager()
            ->putJson("/api/v1/students/{$this->student->id}/contacts/{$contact->id}", [
                'full_name' => 'Updated Name',
            ])->assertOk()
            ->assertJsonFragment(['full_name' => 'Updated Name']);

        $this->assertDatabaseHas('student_contacts', ['id' => $contact->id, 'full_name' => 'Updated Name']);
    }

    public function test_changing_contact_email_detaches_old_parent_and_provisions_new(): void
    {
        Mail::fake();

        $contact = StudentContact::factory()->create([
            'student_id' => $this->student->id,
            'email' => 'old@example.com',
        ]);

        $oldParent = ParentUser::create([
            'first_name' => 'Old',
            'last_name' => 'Parent',
            'email' => 'old@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $oldParent->students()->attach($this->student->id, ['linked_at' => now(), 'linked_by' => 1, 'wallet_alert_threshold' => 0]);

        $this->asManager()
            ->putJson("/api/v1/students/{$this->student->id}/contacts/{$contact->id}", [
                'email' => 'new@example.com',
            ])->assertOk();

        $this->assertDatabaseMissing('parent_student', ['parent_id' => $oldParent->id, 'student_id' => $this->student->id]);
        $this->assertDatabaseHas('parents', ['email' => 'new@example.com']);
    }

    public function test_manager_can_delete_contact(): void
    {
        $contact = StudentContact::factory()->create(['student_id' => $this->student->id]);

        $this->asManager()
            ->deleteJson("/api/v1/students/{$this->student->id}/contacts/{$contact->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('student_contacts', ['id' => $contact->id]);
    }

    public function test_deleting_contact_with_email_detaches_parent(): void
    {
        Mail::fake();

        $contact = StudentContact::factory()->create([
            'student_id' => $this->student->id,
            'email' => 'detach-contact@example.com',
        ]);

        $parent = ParentUser::create([
            'first_name' => 'Detach',
            'last_name' => 'Me',
            'email' => 'detach-contact@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);
        $parent->students()->attach($this->student->id, ['linked_at' => now(), 'linked_by' => 1, 'wallet_alert_threshold' => 0]);

        $this->asManager()
            ->deleteJson("/api/v1/students/{$this->student->id}/contacts/{$contact->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('parent_student', ['parent_id' => $parent->id, 'student_id' => $this->student->id]);
    }

    public function test_contact_does_not_belong_to_student_returns_404(): void
    {
        $otherStudent = Student::factory()->create(['branch_id' => $this->branch->id]);
        $contact = StudentContact::factory()->create(['student_id' => $otherStudent->id]);

        $this->asManager()
            ->deleteJson("/api/v1/students/{$this->student->id}/contacts/{$contact->id}")
            ->assertNotFound();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson("/api/v1/students/{$this->student->id}/contacts")
            ->assertUnauthorized();
    }
}
