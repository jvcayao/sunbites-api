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

class ProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    public function test_get_profile_returns_profile_photo_url_not_path(): void
    {
        $this->parent->update(['profile_photo_path' => 'photos/parents/test.jpg']);

        $response = $this->asParent()->getJson('/api/v1/portal/profile');

        $response->assertOk()
            ->assertJsonStructure(['profile_photo_url'])
            ->assertJsonMissingPath('profile_photo_path');
    }

    public function test_photo_upload_stores_on_public_disk_and_returns_url(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->asParent()->post('/api/v1/portal/profile/photo', [
            'photo' => $file,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['profile_photo_url']);

        $this->parent->refresh();
        Storage::disk('public')->assertExists($this->parent->profile_photo_path);
    }

    public function test_photo_upload_deletes_old_photo_from_public_disk(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('photos/parents/old.jpg', 'old content');
        $this->parent->update(['profile_photo_path' => 'photos/parents/old.jpg']);

        $this->asParent()->post('/api/v1/portal/profile/photo', [
            'photo' => UploadedFile::fake()->image('new.jpg'),
        ]);

        Storage::disk('public')->assertMissing('photos/parents/old.jpg');
    }

    public function test_change_password_succeeds_with_correct_current_password(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'Password1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Password changed successfully.']);
    }

    public function test_change_password_fails_422_with_wrong_current_password(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'WrongPassword1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_change_password_fails_422_when_confirmation_mismatches(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'Password1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'DifferentPassword3!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_patch_profile_does_not_accept_password_fields(): void
    {
        $response = $this->asParent()->patchJson('/api/v1/portal/profile', [
            'first_name' => 'Updated',
            'current_password' => 'Password1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ]);

        $response->assertOk();

        $this->parent->refresh();
        $this->assertEquals('Updated', $this->parent->first_name);
        $this->assertTrue(Hash::check('Password1!', $this->parent->password));
    }

    public function test_change_password_revokes_all_sessions(): void
    {
        $this->parent->createToken('other-device', ['parent']);

        $this->asParent()->postJson('/api/v1/portal/profile/change-password', [
            'current_password' => 'Password1!',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ])->assertOk();

        // All tokens must be gone — user must re-authenticate on all devices.
        $this->assertEquals(0, $this->parent->tokens()->count());
    }

    public function test_get_profile_includes_has_subscription_student_false_when_no_students(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/profile');

        $response->assertOk()
            ->assertJsonPath('has_subscription_student', false);
    }

    public function test_get_profile_includes_has_subscription_student_true_when_subscription_student_linked(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create();
        $student = Student::factory()->subscription()->create(['branch_id' => $branch->id]);
        $this->parent->students()->attach($student->id, [
            'linked_at' => now(),
            'linked_by' => $staff->id,
            'wallet_alert_threshold' => 0,
        ]);

        $response = $this->asParent()->getJson('/api/v1/portal/profile');

        $response->assertOk()
            ->assertJsonPath('has_subscription_student', true);
    }

    public function test_patch_profile_response_includes_has_subscription_student(): void
    {
        $response = $this->asParent()->patchJson('/api/v1/portal/profile', [
            'first_name' => 'Updated',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['has_subscription_student']);
    }

    public function test_login_response_includes_profile_photo_url_not_path(): void
    {
        $this->parent->update(['profile_photo_path' => 'photos/parents/test.jpg']);

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'maria@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('parent.profile_photo_url', fn ($v) => $v !== null)
            ->assertJsonMissingPath('parent.profile_photo_path');
    }
}
