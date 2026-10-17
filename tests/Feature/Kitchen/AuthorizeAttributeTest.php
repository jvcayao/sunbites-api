<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Order;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each test sends one request that the endpoint's policy check refuses, and expects the
 * standard 403 body. Together they cover the 14 `#[Authorize]` attributes (Spec 16 T-2).
 */
class AuthorizeAttributeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const array FORBIDDEN = ['message' => 'This action is unauthorized.'];

    private Branch $branch;

    /**
     * Debug mode is off, as in production and CI, so the 403 body has no exception details
     * (the local `.env.testing` turns debug on; Spec 16 design §4.1.2).
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
    }

    public function test_voiding_an_order_returns_403_when_the_void_ability_is_denied(): void
    {
        $supervisor = $this->staff('supervisor');
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'cashier_id' => $supervisor->id]);
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'void' ? false : null);

        $response = $this->asStaff($supervisor)->postJson("/api/v1/pos/transactions/{$order->id}/void", ['void_reason' => 'Wrong item']);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertSame('completed', $order->fresh()->status->value);
    }

    public function test_deactivating_yourself_returns_403(): void
    {
        $admin = $this->staff('admin');

        $response = $this->asStaff($admin)->postJson("/api/v1/users/{$admin->id}/deactivate");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertNotSoftDeleted($admin);
    }

    public function test_reactivating_a_user_returns_403_without_users_manage(): void
    {
        $admin = $this->adminWithoutUserManagement();
        $target = $this->staff('cashier');
        $target->delete();

        $response = $this->asStaff($admin)->postJson("/api/v1/users/{$target->id}/reactivate");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertSoftDeleted($target);
    }

    public function test_sending_a_password_reset_returns_403_without_users_manage(): void
    {
        Mail::fake();
        $admin = $this->adminWithoutUserManagement();
        $target = $this->staff('cashier');

        $response = $this->asStaff($admin)->postJson("/api/v1/users/{$target->id}/reset-password");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        Mail::assertNothingOutgoing();
    }

    public function test_manager_uploading_a_staff_photo_returns_403(): void
    {
        Storage::fake('private');
        $manager = $this->staff('manager');
        $target = $this->staff('cashier');

        $response = $this->asStaff($manager)->postJson("/api/v1/users/{$target->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertNull($target->fresh()->profile_photo_path);
    }

    public function test_assigning_a_branch_returns_403_without_users_manage(): void
    {
        $admin = $this->adminWithoutUserManagement();
        $target = $this->staff('cashier');
        $otherBranch = Branch::factory()->create(['is_active' => true]);

        $response = $this->asStaff($admin)->postJson("/api/v1/users/{$target->id}/branches", ['branch_id' => $otherBranch->id]);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertDatabaseMissing('branch_user', ['user_id' => $target->id, 'branch_id' => $otherBranch->id]);
    }

    public function test_removing_a_branch_returns_403_without_users_manage(): void
    {
        $admin = $this->adminWithoutUserManagement();
        $target = $this->staff('cashier');

        $response = $this->asStaff($admin)->deleteJson("/api/v1/users/{$target->id}/branches/{$this->branch->id}");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertDatabaseHas('branch_user', ['user_id' => $target->id, 'branch_id' => $this->branch->id]);
    }

    public function test_unlinked_parent_viewing_a_wallet_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->getJson("/api/v1/portal/students/{$student->id}/wallet");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_unlinked_parent_setting_a_wallet_alert_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->patchJson("/api/v1/portal/students/{$student->id}/wallet/alert", ['threshold' => 150]);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertDatabaseMissing('parent_student', ['student_id' => $student->id]);
    }

    public function test_unlinked_parent_viewing_a_ledger_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->getJson("/api/v1/portal/students/{$student->id}/ledger");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_unlinked_parent_viewing_a_photo_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->getJson("/api/v1/portal/students/{$student->id}/photo");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_unlinked_parent_uploading_a_photo_returns_403(): void
    {
        Storage::fake('private');
        $student = $this->student();

        $response = $this->asUnlinkedParent()->postJson("/api/v1/portal/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->assertNull($student->fresh()->photo_path);
    }

    public function test_unlinked_parent_viewing_spending_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->getJson("/api/v1/portal/students/{$student->id}/spending-summary");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_unlinked_parent_viewing_activity_returns_403(): void
    {
        $student = $this->student();

        $response = $this->asUnlinkedParent()->getJson("/api/v1/portal/students/{$student->id}/activity");

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    /**
     * An admin passes the `role:admin` route group, but without `users.manage` the UserPolicy
     * abilities refuse, so the 403 comes from the policy check rather than the route group.
     */
    private function adminWithoutUserManagement(): User
    {
        Role::findByName('admin', 'web')->revokePermissionTo('users.manage');

        return $this->staff('admin');
    }

    private function student(): Student
    {
        return Student::factory()->subscription()->create(['branch_id' => $this->branch->id, 'photo_path' => null]);
    }

    private function asStaff(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asUnlinkedParent(): static
    {
        Sanctum::actingAs(ParentUser::factory()->create(), ['parent'], 'parents');

        return $this;
    }
}
