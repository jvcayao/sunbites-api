<?php

namespace Tests\Feature;

use App\Enums\CreditSettlementMethod;
use App\Enums\StaffStatus;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B-27 (design §5.25): a one-element list in a field that expects a single scalar must return
 * 422, not 500. Covers the 6 untyped `exists` rules, the 4 raw `(string)` casts, the 2 rate
 * limiter key builders and the untyped reset `token` rule.
 */
class ListInputValidationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private User $manager;

    private User $admin;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->cashier = $this->staffWithRole('cashier');
        $this->manager = $this->staffWithRole('manager');
        $this->admin = $this->staffWithRole('admin');

        $this->menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 100.00,
            'is_available' => true,
        ]);

        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 9999]);
        $this->menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);
    }

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    // -------------------------------------------------------------------------
    // Items 1-6: untyped `exists` rules
    // -------------------------------------------------------------------------

    public function test_checkout_rejects_student_id_sent_as_a_list(): void
    {
        $student = Student::factory()->enrolled()->create(['branch_id' => $this->branch->id]);

        $response = $this->asUser($this->cashier)->postJson('/api/v1/pos/checkout', [
            'student_id' => [$student->id],
            'items' => [['pos_menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 500,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_a_menu_item_id_sent_as_a_list(): void
    {
        $response = $this->asUser($this->cashier)->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => [$this->menuItem->id], 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 500,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['items.0.pos_menu_item_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_enrollment_rejects_branch_id_sent_as_a_list(): void
    {
        $response = $this->asUser($this->manager)->postJson('/api/v1/enrollment', [
            'branch_id' => [$this->branch->id],
            'student_number' => 'TEST-2025-001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'section' => 'Section A',
            'birthday' => '2015-01-15',
            'student_type' => 'non_subscription',
            'allergies' => null,
            'notes' => null,
            'contacts' => [[
                'full_name' => 'Maria Dela Cruz',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St',
                'email' => 'maria@example.com',
            ]],
            'signature' => 'Maria Dela Cruz',
            'permission_meals' => true,
            'permission_dietary' => true,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_inline_reload_rejects_student_id_sent_as_a_list(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asUser($this->cashier)->postJson('/api/v1/pos/inline-reload', [
            'student_id' => [$student->id],
            'amount' => 85.00,
            'payment_method' => 'cash',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_updating_staff_status_rejects_user_id_sent_as_a_list(): void
    {
        $staffUser = $this->staffWithRole('cashier');

        $response = $this->asUser($this->admin)->postJson('/api/v1/dashboard/staff-status', [
            'user_id' => [$staffUser->id],
            'status' => StaffStatus::OnLeave->value,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        $this->assertDatabaseCount('staff_daily_statuses', 0);
    }

    public function test_public_pre_registration_rejects_branch_id_sent_as_a_list(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
        ]);

        $response = $this->postJson('/api/v1/public/pre-registrations', [
            'branch_id' => [$this->branch->id],
            'first_name' => 'Juan',
            'last_name' => 'Santos',
            'grade_level' => 'Grade 3',
            'birthday' => '2016-06-15',
            'enrollment_type' => 'non_subscription',
            'signatory_name' => 'Maria Santos',
            'acknowledged_at' => now()->toDateTimeString(),
            'recaptcha_token' => 'fake-token',
            'contacts' => [
                [
                    'full_name' => 'Maria Santos',
                    'relationship' => 'Mother',
                    'phone' => '09171234567',
                    'address' => '123 Main St, Iloilo City',
                    'email' => 'maria@example.com',
                    'is_primary' => true,
                ],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->assertDatabaseCount('pre_registrations', 0);
    }

    // -------------------------------------------------------------------------
    // Items 7-10: raw (string) casts before validation
    // -------------------------------------------------------------------------

    public function test_updating_student_status_rejects_enrollment_status_sent_as_a_list(): void
    {
        $student = Student::factory()->enrolled()->create(['branch_id' => $this->branch->id]);

        $response = $this->asUser($this->manager)->patchJson("/api/v1/students/{$student->id}/status", [
            'enrollment_status' => ['withdrawn'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['enrollment_status']);
        $this->assertSame('enrolled', $student->fresh()->enrollment_status->value);
    }

    public function test_waiving_credit_rejects_reason_sent_as_a_list(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 150.00]);

        $response = $this->asUser($this->admin)->postJson("/api/v1/students/{$student->id}/credit/waive", [
            'amount' => 150.00,
            'reason' => ['x'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertEquals(150.00, $student->fresh()->credit_balance);
    }

    public function test_voiding_a_top_up_rejects_reason_sent_as_a_list(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $deposit = $student->deposit(10000, [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $this->manager->id,
        ]);

        $response = $this->asUser($this->admin)->postJson("/api/v1/students/{$student->id}/wallet/top-ups/{$deposit->id}/void", [
            'reason' => ['x'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_settling_credit_rejects_note_sent_as_a_list(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'credit_balance' => 150.00]);

        $response = $this->asUser($this->cashier)->postJson("/api/v1/students/{$student->id}/credit/settle", [
            'amount' => 150.00,
            'payment_method' => CreditSettlementMethod::Cash->value,
            'note' => ['x'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertEquals(150.00, $student->fresh()->credit_balance);
    }

    // -------------------------------------------------------------------------
    // Item 11: rate limiter key builders (public routes, no auth)
    // -------------------------------------------------------------------------

    public function test_login_rejects_email_sent_as_a_list(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ['cook@sunbites.test'],
            'password' => 'Password1',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_password_email_rejects_email_sent_as_a_list(): void
    {
        $response = $this->postJson('/api/v1/auth/password/email', [
            'email' => ['cook@sunbites.test'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_password_reset_rejects_email_sent_as_a_list(): void
    {
        $user = User::factory()->create(['email' => 'cook@sunbites.test']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => ['cook@sunbites.test'],
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->assertFalse(Hash::check('NewPassword1', $user->fresh()->password));
    }

    // -------------------------------------------------------------------------
    // Item 12: untyped reset `token` rule
    // -------------------------------------------------------------------------

    public function test_password_reset_rejects_token_sent_as_a_list(): void
    {
        $user = User::factory()->create(['email' => 'cook@sunbites.test']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => [$token],
            'email' => 'cook@sunbites.test',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->assertFalse(Hash::check('NewPassword1', $user->fresh()->password));
    }
}
