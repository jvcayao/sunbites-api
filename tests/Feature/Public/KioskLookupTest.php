<?php

namespace Tests\Feature\Public;

use App\Enums\EnrollmentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosMenuItem;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskLookupTest extends TestCase
{
    use RefreshDatabase;

    private PosMenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $this->menuItem = PosMenuItem::factory()->create(['branch_id' => $branch->id]);
    }

    public function test_enrolled_student_gets_balance_and_orders(): void
    {
        $student = Student::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-testqrcode1234',
        ]);

        $student->deposit(20000); // ₱200.00

        $order = Order::factory()->create(['student_id' => $student->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->menuItem->id,
            'name' => 'Rice Meal',
            'line_total' => 5500,
        ]);

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-testqrcode1234',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'name', 'initials', 'grade_level', 'student_type', 'balance', 'last_orders',
            ])
            ->assertJson([
                'name' => 'Juan Dela Cruz',
                'initials' => 'JD',
                'grade_level' => 'Grade 3',
                'balance' => '200.00',
            ]);
    }

    public function test_last_orders_are_capped_at_five(): void
    {
        $student = Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-captest1234567',
        ]);

        Order::factory(7)->create(['student_id' => $student->id])->each(function ($order) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'pos_menu_item_id' => $this->menuItem->id,
            ]);
        });

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-captest1234567',
        ]);

        $response->assertOk();
        $this->assertCount(5, $response->json('last_orders'));
    }

    public function test_returns_404_for_unknown_qr_code(): void
    {
        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-doesnotexist12',
        ]);

        $response->assertNotFound()
            ->assertJson(['message' => 'Student not found.']);
    }

    public function test_paused_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Paused,
            'qr_code' => 'SB-pausedstudent12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-pausedstudent12'])
            ->assertForbidden()
            ->assertJson(['message' => 'Student is not eligible.']);
    }

    public function test_unenrolled_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Unenrolled,
            'qr_code' => 'SB-unenrolledst12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-unenrolledst12'])
            ->assertForbidden();
    }

    public function test_banned_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Banned,
            'qr_code' => 'SB-bannedstudent12',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-bannedstudent12'])
            ->assertForbidden();
    }

    public function test_graduated_student_returns_403(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Graduated,
            'qr_code' => 'SB-graduatedst1234',
        ]);

        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'SB-graduatedst1234'])
            ->assertForbidden();
    }

    public function test_rejects_qr_without_sb_prefix(): void
    {
        $this->postJson('/api/v1/public/kiosk/lookup', ['qr_code' => 'INVALID-123'])
            ->assertUnprocessable();
    }

    public function test_rejects_missing_qr_code(): void
    {
        $this->postJson('/api/v1/public/kiosk/lookup', [])
            ->assertUnprocessable();
    }

    public function test_sensitive_fields_are_excluded_from_response(): void
    {
        Student::factory()->create([
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'qr_code' => 'SB-sensitivetest12',
        ]);

        $response = $this->postJson('/api/v1/public/kiosk/lookup', [
            'qr_code' => 'SB-sensitivetest12',
        ]);

        $response->assertOk();

        $json = $response->json();
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('qr_code', $json);
        $this->assertArrayNotHasKey('student_number', $json);
        $this->assertArrayNotHasKey('photo_url', $json);
        $this->assertArrayNotHasKey('photo_path', $json);
    }
}
