<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\PreRegistrationNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PreRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, SystemConfigurationSeeder::class]);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->validPayload = [
            'branch_id' => $this->branch->id,
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
        ];
    }

    public function test_valid_submission_creates_pre_registration_and_notifies_staff(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
        ]);
        Mail::fake();
        Notification::fake();

        $staff = User::factory()->create();
        $staff->assignRole('supervisor');
        $staff->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->validPayload);

        $response->assertCreated()
            ->assertJson(['message' => 'Pre-registration received.']);

        $this->assertDatabaseHas('pre_registrations', [
            'first_name' => 'Juan',
            'last_name' => 'Santos',
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('pre_registration_contacts', [
            'full_name' => 'Maria Santos',
            'is_primary' => true,
        ]);

        Notification::assertSentTo($staff, PreRegistrationNotification::class);
    }

    public function test_honeypot_filled_returns_201_without_creating_record(): void
    {
        $payload = array_merge($this->validPayload, ['website' => 'http://spam.example.com']);

        $response = $this->postJson('/api/v1/public/pre-registrations', $payload);

        $response->assertCreated();
        $this->assertDatabaseCount('pre_registrations', 0);
    }

    public function test_recaptcha_score_too_low_returns_422(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.2,
            ]),
        ]);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->validPayload);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Submission could not be verified. Please try again.']);
    }

    public function test_recaptcha_failure_returns_422(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => false,
            ]),
        ]);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->validPayload);

        $response->assertStatus(422);
    }

    public function test_subscription_type_without_period_fields_returns_422(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
        ]);

        $payload = array_merge($this->validPayload, ['enrollment_type' => 'subscription']);

        $response = $this->postJson('/api/v1/public/pre-registrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['subscription_start_month', 'subscription_start_year']);
    }

    public function test_missing_required_field_returns_422(): void
    {
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
        ]);

        $payload = $this->validPayload;
        unset($payload['first_name']);

        $response = $this->postJson('/api/v1/public/pre-registrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name']);
    }

    public function test_public_branches_endpoint_returns_active_branches_without_auth(): void
    {
        Branch::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/branches');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($this->branch->id, $ids->toArray());

        // Should only return id and name, not sensitive fields
        $firstItem = $response->json('data.0');
        $this->assertArrayHasKey('id', $firstItem);
        $this->assertArrayHasKey('name', $firstItem);
        $this->assertArrayNotHasKey('address', $firstItem);
        $this->assertArrayNotHasKey('phone', $firstItem);
    }
}
