<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentPhotoTest extends TestCase
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

    // --- store ---

    public function test_parent_can_upload_photo_for_linked_student(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        );

        $response->assertOk()
            ->assertJsonStructure(['photo_url'])
            ->assertJsonPath('photo_url', url("/api/v1/portal/students/{$this->student->id}/photo"));

        $this->student->refresh();
        $this->assertNotNull($this->student->photo_path);
        Storage::disk('private')->assertExists($this->student->photo_path);
    }

    public function test_upload_deletes_old_photo_from_private_disk(): void
    {
        Storage::fake('private');

        Storage::disk('private')->put('photos/students/old.jpg', 'old data');
        $this->student->update(['photo_path' => 'photos/students/old.jpg']);

        $file = UploadedFile::fake()->image('new.jpg', 200, 200);

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertOk();

        Storage::disk('private')->assertMissing('photos/students/old.jpg');
    }

    public function test_upload_rejects_invalid_mime(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_upload_rejects_files_over_5mb(): void
    {
        Storage::fake('private');

        $file = UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_upload_forbidden_for_non_linked_student(): void
    {
        Storage::fake('private');

        $other = Student::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->asParent()->postJson(
            "/api/v1/portal/students/{$other->id}/photo",
            ['photo' => $file],
        )->assertForbidden();
    }

    // --- show ---

    public function test_parent_can_view_photo_of_linked_student(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('photos/students/student.jpg', 'img');
        $this->student->update(['photo_path' => 'photos/students/student.jpg']);

        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$this->student->id}/photo");

        $response->assertOk();
    }

    public function test_show_returns_404_when_no_photo(): void
    {
        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$this->student->id}/photo");

        $response->assertNotFound();
    }

    public function test_show_forbidden_for_non_linked_student(): void
    {
        $other = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asParent()
            ->get("/api/v1/portal/students/{$other->id}/photo");

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_upload(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson(
            "/api/v1/portal/students/{$this->student->id}/photo",
            ['photo' => $file],
        )->assertUnauthorized();
    }
}
