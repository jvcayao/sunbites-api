<?php

namespace Tests\Feature\Kitchen;

use App\Http\Resources\UserResource;
use App\Mail\StaffResetPasswordMail;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('staff', ['staff'])->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Unit tests — kept exactly as they were; they test the resource in isolation
    // -------------------------------------------------------------------------

    public function test_user_resource_excludes_gov_ids_for_non_admin(): void
    {
        $user = User::factory()->create(['sss_number' => '12-3456789-0']);
        $user->assignRole('manager');
        $user->load('roles', 'branches');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $resource = new UserResource($user);
        $data = $resource->resolve($request);

        $this->assertArrayNotHasKey('sss_number', $data);
        $this->assertArrayNotHasKey('pagibig_number', $data);
    }

    public function test_user_resource_includes_gov_ids_for_admin(): void
    {
        $user = User::factory()->create(['sss_number' => '12-3456789-0']);
        $user->load('roles', 'branches');

        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $adminUser);

        $resource = new UserResource($user);
        $data = $resource->toArray($request);

        $this->assertArrayHasKey('sss_number', $data);
    }

    // -------------------------------------------------------------------------
    // JSON API tests
    // -------------------------------------------------------------------------

    public function test_manager_can_access_user_list(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $managerToken = $manager->createToken('staff', ['staff'])->plainTextToken;

        $response = $this->withToken($managerToken)
            ->getJson('/api/v1/users');

        $response->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_supervisor_cannot_access_user_list(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisorToken = $supervisor->createToken('staff', ['staff'])->plainTextToken;

        $response = $this->withToken($supervisorToken)
            ->getJson('/api/v1/users');

        $response->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        User::factory(3)->create()->each(fn (User $u) => $u->assignRole('cashier'));

        $response = $this->withToken($this->adminToken())
            ->getJson('/api/v1/users');

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonIsArray('data');
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->withToken($this->adminToken())
            ->postJson('/api/v1/users', [
                'first_name' => 'Juan',
                'last_name' => 'dela Cruz',
                'email' => 'juan@sunbites.test',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'role' => 'cashier',
                'branch_ids' => [$this->branch->id],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'juan@sunbites.test']);

        $created = User::where('email', 'juan@sunbites.test')->first();
        $this->assertTrue($created->hasRole('cashier'));
        $this->assertTrue($created->branches->contains($this->branch));
    }

    public function test_create_user_validation_rejects_weak_password(): void
    {
        $response = $this->withToken($this->adminToken())
            ->postJson('/api/v1/users', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'weak@sunbites.test',
                'password' => 'alllowercase',
                'password_confirmation' => 'alllowercase',
                'role' => 'cashier',
            ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_create_user_validation_rejects_duplicate_email(): void
    {
        $existing = User::factory()->create(['email' => 'taken@sunbites.test']);
        $existing->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/v1/users', [
                'first_name' => 'Another',
                'last_name' => 'User',
                'email' => 'taken@sunbites.test',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'role' => 'cashier',
            ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_admin_can_view_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->getJson("/api/v1/users/{$user->id}");

        $response->assertOk()
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_admin_can_update_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->putJson("/api/v1/users/{$user->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => $user->email,
                'role' => 'cashier',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'first_name' => 'Updated']);
    }

    public function test_admin_can_change_user_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/users/{$user->id}", [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => 'supervisor',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasRole('cashier'));
        $this->assertTrue($fresh->hasRole('supervisor'));
    }

    public function test_admin_can_deactivate_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$user->id}/deactivate");

        $response->assertOk();
        $this->assertSoftDeleted($user);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$this->admin->id}/deactivate");

        $response->assertForbidden();
        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_admin_can_reactivate_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->update(['is_active' => false]);
        $user->delete();

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$user->id}/reactivate");

        $response->assertOk();
        $this->assertNotSoftDeleted($user);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_can_send_password_reset_email(): void
    {
        Mail::fake();

        $staff = User::factory()->create();
        $staff->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$staff->id}/reset-password");

        $response->assertOk();
        Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) use ($staff) {
            return $mail->hasTo($staff->email);
        });
    }

    public function test_password_reset_mail_url_points_to_pos_app(): void
    {
        Mail::fake();

        $staff = User::factory()->create(['email' => 'staff@example.com']);
        $staff->assignRole('cashier');

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$staff->id}/reset-password")
            ->assertOk();

        Mail::assertQueued(StaffResetPasswordMail::class, function (StaffResetPasswordMail $mail) {
            $url = $mail->content()->with['resetUrl'];
            $posUrl = config('app.pos_url');

            return str_starts_with($url, $posUrl) && str_contains($url, 'reset-password');
        });
    }

    public function test_photo_upload_rejects_invalid_mime(): void
    {
        Storage::fake('private');

        $user = User::factory()->create();
        $user->assignRole('cashier');
        $fakeFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$user->id}/photo", [
                'photo' => $fakeFile,
            ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_photo_upload_rejects_files_over_2mb(): void
    {
        Storage::fake('private');

        $user = User::factory()->create();
        $user->assignRole('cashier');
        $bigFile = UploadedFile::fake()->create('photo.jpg', 3000, 'image/jpeg');

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$user->id}/photo", [
                'photo' => $bigFile,
            ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['photo']]);
    }

    public function test_admin_can_assign_branch_to_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/v1/users/{$user->id}/branches", [
                'branch_id' => $this->branch->id,
            ]);

        $response->assertOk();
        $this->assertTrue($user->fresh()->branches->contains($this->branch));
    }

    public function test_admin_can_detach_branch_from_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => $this->admin->id]);

        $response = $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/users/{$user->id}/branches/{$this->branch->id}");

        $response->assertOk();
        $this->assertFalse($user->fresh()->branches->contains($this->branch));
    }
}
