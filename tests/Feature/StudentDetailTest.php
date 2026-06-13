<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\MenuCategory;
use App\Models\Branch;
use App\Models\BranchSubscriptionConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    public function test_manager_can_view_student_detail(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->getJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $response->assertJsonStructure(['student', 'wallet_transactions', 'activity_logs']);
    }

    public function test_manager_can_update_student_profile(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'grade_level' => 'Grade 5',
            'section' => 'Section B',
            'birthday' => '2015-06-01',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Updated']);
    }

    public function test_profile_update_strips_html_from_freetext_fields(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->asManager()->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 3',
            'birthday' => '2015-01-01',
            'allergies' => '<b>Peanuts</b>',
            'notes' => '<script>evil()</script>Note',
        ]);

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'allergies' => 'Peanuts',
            'notes' => 'evil()Note',
        ]);
    }

    public function test_qr_regeneration_replaces_old_code(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'qr_code' => 'SB-ORIGINALCODE0']);

        $response = $this->asManager()->postJson("/api/v1/students/{$student->id}/regenerate-qr");

        $response->assertOk();
        $this->assertStringStartsWith('SB-', $response->json('qr_code'));
        $this->assertNotEquals('SB-ORIGINALCODE0', $response->json('qr_code'));
    }

    public function test_regenerated_qr_is_globally_unique(): void
    {
        $existingCode = 'SB-EXISTINGCODE0';
        Student::factory()->create(['branch_id' => $this->branch->id, 'qr_code' => $existingCode]);
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->postJson("/api/v1/students/{$student->id}/regenerate-qr");

        $response->assertOk();
        $this->assertNotEquals($existingCode, $response->json('qr_code'));
    }

    public function test_status_change_without_reason_for_non_requiring_status(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'enrollment_status' => EnrollmentStatus::Enrolled->value,
        ]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/status", [
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'enrollment_status' => EnrollmentStatus::Paused->value,
        ]);
    }

    public function test_status_change_to_banned_requires_reason(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/status", [
            'enrollment_status' => EnrollmentStatus::Banned->value,
        ]);

        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_manager_can_soft_delete_student(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->deleteJson("/api/v1/students/{$student->id}");

        $response->assertOk();
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_manager_can_downgrade_subscription_student_to_wallet(): void
    {
        $student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'non_subscription',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'student_type' => 'non_subscription',
        ]);
    }

    public function test_manager_can_upgrade_wallet_student_to_subscription(): void
    {
        $student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'subscription',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'student_type' => 'subscription',
        ]);
    }

    public function test_invalid_student_type_is_rejected(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->patchJson("/api/v1/students/{$student->id}/type", [
            'student_type' => 'premium',
        ]);

        $response->assertUnprocessable();
    }

    public function test_manager_can_upload_student_photo(): void
    {
        Storage::fake('private');
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->postJson("/api/v1/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('has_photo', true);
        $this->assertNotNull($response->json('photo_url'));
    }

    public function test_unauthenticated_cannot_upload_student_photo(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson("/api/v1/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertUnauthorized();
    }

    // --- POS lookup: subscription daily status ---

    public function test_subscription_student_pos_lookup_includes_daily_status(): void
    {
        $student = Student::factory()->subscription()->create([
            'branch_id' => $this->branch->id,
            'qr_code' => 'SB-SUBSCTEST001',
        ]);

        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 2,
        ]);

        $response = $this->asManager()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => 'SB-SUBSCTEST001',
        ]);

        $response->assertOk();
        $status = $response->json('student.subscription_daily_status');
        $this->assertNotNull($status);
        $this->assertArrayHasKey('meal', $status);
        $this->assertArrayHasKey('snack', $status);
        $this->assertArrayHasKey('drink', $status);
        $this->assertArrayHasKey('extra', $status);
        $this->assertEquals(0, $status['meal']['used']);
        $this->assertEquals(2, $status['meal']['limit']);
        $this->assertEquals(2, $status['meal']['remaining']);
    }

    public function test_non_subscription_student_pos_lookup_has_null_daily_status(): void
    {
        $student = Student::factory()->nonSubscription()->create([
            'branch_id' => $this->branch->id,
            'qr_code' => 'SB-NONSUB00001',
        ]);

        $response = $this->asManager()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => 'SB-NONSUB00001',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('student.subscription_daily_status'));
    }

    public function test_daily_status_counts_only_completed_subscription_orders_from_today(): void
    {
        $student = Student::factory()->subscription()->create([
            'branch_id' => $this->branch->id,
            'qr_code' => 'SB-DAILYCNT001',
        ]);

        BranchSubscriptionConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'meal_daily_limit' => 3,
        ]);

        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');

        $mealItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branch->id,
            'category' => MenuCategory::Meal->value,
        ]);

        // Completed subscription order today → should count
        $completedOrder = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'subscription',
            'status' => 'completed',
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'pos_menu_item_id' => $mealItem->id,
            'quantity' => 1,
        ]);

        // Voided subscription order today → should NOT count
        $voidedOrder = Order::factory()->voided()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'subscription',
        ]);
        OrderItem::factory()->create([
            'order_id' => $voidedOrder->id,
            'pos_menu_item_id' => $mealItem->id,
            'quantity' => 1,
        ]);

        // Cash order today → should NOT count
        $cashOrder = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'cashier_id' => $cashier->id,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        OrderItem::factory()->create([
            'order_id' => $cashOrder->id,
            'pos_menu_item_id' => $mealItem->id,
            'quantity' => 1,
        ]);

        $response = $this->asManager()->postJson('/api/v1/pos/students/lookup', [
            'type' => 'qr',
            'value' => 'SB-DAILYCNT001',
        ]);

        $response->assertOk();
        $mealStatus = $response->json('student.subscription_daily_status.meal');
        $this->assertEquals(1, $mealStatus['used']);
        $this->assertEquals(3, $mealStatus['limit']);
        $this->assertEquals(2, $mealStatus['remaining']);
    }

    private function baseUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'birthday' => '2015-01-15',
        ], $overrides);
    }

    public function test_update_student_number_succeeds(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->putJson("/api/v1/students/{$student->id}", $this->baseUpdatePayload([
            'student_number' => 'NEW-2025-999',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'student_number' => 'NEW-2025-999']);
    }

    public function test_duplicate_student_number_per_branch_fails_on_update(): void
    {
        $studentA = Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'TAKEN-001']);
        $studentB = Student::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->asManager()->putJson("/api/v1/students/{$studentB->id}", $this->baseUpdatePayload([
            'student_number' => 'TAKEN-001',
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['student_number']);
    }

    public function test_clearing_student_number_to_null_succeeds(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'student_number' => 'CLEAR-001']);

        $response = $this->asManager()->putJson("/api/v1/students/{$student->id}", $this->baseUpdatePayload([
            'student_number' => null,
        ]));

        $response->assertOk();
        $this->assertNull($student->fresh()->student_number);
    }
}
