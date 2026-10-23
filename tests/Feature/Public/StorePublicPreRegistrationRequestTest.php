<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The honeypot, then the reCAPTCHA check, still run before the form is validated
 * (Spec 16 T-8.3, design §4.2.2, hazard H4).
 */
class StorePublicPreRegistrationRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(SystemConfigurationSeeder::class);
    }

    public function test_filled_honeypot_with_an_invalid_body_returns_201_and_creates_nothing(): void
    {
        Http::fake();

        $response = $this->postJson('/api/v1/public/pre-registrations', ['website' => 'http://spam.example.com']);

        $response->assertCreated()->assertExactJson(['message' => 'Pre-registration received.']);
        $this->assertDatabaseCount('pre_registrations', 0);
        Http::assertNothingSent();
    }

    public function test_unreachable_recaptcha_returns_503_and_logs_a_warning(): void
    {
        Http::fake(['https://www.google.com/recaptcha/api/siteverify' => Http::failedConnection()]);
        Log::spy();

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->validPayload());

        $response->assertServiceUnavailable()->assertExactJson(['message' => 'Verification service temporarily unavailable. Please try again shortly.']);
        Log::shouldHaveReceived('warning')->once()->with('reCAPTCHA verification service unavailable', ['ip' => '127.0.0.1']);
        $this->assertDatabaseCount('pre_registrations', 0);
    }

    public function test_low_recaptcha_score_with_an_invalid_body_returns_422_not_verified(): void
    {
        Http::fake(['https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.2])]);

        $response = $this->postJson('/api/v1/public/pre-registrations', ['recaptcha_token' => 'fake-token']);

        $response->assertUnprocessable()->assertExactJson(['message' => 'Submission could not be verified. Please try again.']);
        $this->assertDatabaseCount('pre_registrations', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'first_name' => 'Juan',
            'last_name' => 'Santos',
            'grade_level' => 'Grade 3',
            'birthday' => '2016-06-15',
            'enrollment_type' => 'non_subscription',
            'signatory_name' => 'Maria Santos',
            'acknowledged_at' => now()->toDateTimeString(),
            'recaptcha_token' => 'fake-token',
            'contacts' => [[
                'full_name' => 'Maria Santos',
                'relationship' => 'Mother',
                'phone' => '09171234567',
                'address' => '123 Main St, Iloilo City',
                'email' => 'maria@example.com',
                'is_primary' => true,
            ]],
        ];
    }
}
