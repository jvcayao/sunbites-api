<?php

namespace Tests\Feature\Kitchen;

use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession(['active_branch_id' => $this->branch->id]);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->actingAs($manager)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('kitchen.references.users.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_users_list(): void
    {
        User::factory(3)->create()->each(fn ($u) => $u->assignRole('cashier'));

        $response = $this->actingAsAdmin()->get(route('kitchen.references.users.index'));

        $response->assertOk();
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.store'), [
            'first_name' => 'Juan',
            'last_name' => 'dela Cruz',
            'email' => 'juan@test.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => 'cashier',
            'branch_ids' => [$this->branch->id],
        ]);

        $response->assertRedirect(route('kitchen.references.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'juan@test.com']);
        $user = User::where('email', 'juan@test.com')->first();
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertTrue($user->branches->contains($this->branch));
    }

    public function test_admin_can_update_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->actingAsAdmin()->put(route('kitchen.references.users.update', $user), [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => $user->email,
            'role' => 'supervisor',
        ]);

        $response->assertRedirect(route('kitchen.references.users.show', $user));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'first_name' => 'Updated']);
        $this->assertTrue($user->fresh()->hasRole('supervisor'));
    }

    public function test_admin_can_deactivate_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.deactivate', $user));

        $response->assertRedirect(route('kitchen.references.users.index'));
        $this->assertSoftDeleted($user);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.deactivate', $this->admin));

        $response->assertForbidden();
        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_non_admin_cannot_access_user_detail_page(): void
    {
        $manager = User::factory()->create([
            'sss_number' => '12-3456789-0',
        ]);
        $manager->assignRole('manager');
        $branch = Branch::factory()->create();
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $targetUser = User::factory()->create(['sss_number' => '99-9999999-9']);
        $targetUser->assignRole('cashier');

        $response = $this->actingAs($manager)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('kitchen.references.users.show', $targetUser));

        $response->assertForbidden();
    }

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

    public function test_photo_upload_rejects_invalid_mime(): void
    {
        $fakeFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@test.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => 'cashier',
            'profile_photo' => $fakeFile,
        ]);

        $response->assertSessionHasErrors(['profile_photo']);
    }

    public function test_photo_upload_rejects_files_over_2mb(): void
    {
        $bigFile = UploadedFile::fake()->create('photo.jpg', 3000, 'image/jpeg');

        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test2@test.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => 'cashier',
            'profile_photo' => $bigFile,
        ]);

        $response->assertSessionHasErrors(['profile_photo']);
    }

    public function test_admin_can_reactivate_deactivated_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');
        $user->update(['is_active' => false]);
        $user->delete();

        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.reactivate', $user));

        $response->assertRedirect(route('kitchen.references.users.show', $user));
        $this->assertNotSoftDeleted($user);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_can_send_password_reset_email_to_staff(): void
    {
        Notification::fake();

        $staff = User::factory()->create();
        $staff->assignRole('cashier');

        $response = $this->actingAsAdmin()->post(route('kitchen.references.users.reset-password', $staff));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertSentTo($staff, ResetPassword::class);
    }

    public function test_login_page_does_not_expose_forgot_password_link(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->missing('canResetPassword'),
        );
    }
}
