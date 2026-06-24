<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentListFieldsTest extends TestCase
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

        $this->student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'birthday' => '2011-05-15',
            'notes' => 'Lactose intolerant',
        ]);

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

    public function test_students_list_includes_birthday_notes_qr_code(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.birthday', '2011-05-15')
            ->assertJsonPath('data.0.notes', 'Lactose intolerant')
            ->assertJsonPath('data.0.qr_code', $this->student->qr_code);
    }

    public function test_photo_url_is_null_when_no_photo(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.photo_url', null);
    }

    public function test_photo_url_points_to_serve_endpoint_when_photo_exists(): void
    {
        $this->student->update(['photo_path' => 'photos/students/test.jpg']);

        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonPath('data.0.photo_url', url("/api/v1/portal/students/{$this->student->id}/photo"));
    }

    public function test_response_does_not_expose_photo_path(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/students');

        $response->assertOk()
            ->assertJsonMissingPath('data.0.photo_path');
    }
}
