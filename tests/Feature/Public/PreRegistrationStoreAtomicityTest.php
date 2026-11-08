<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\PreRegistrationContact;
use Database\Seeders\SystemConfigurationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * A public pre-registration and its contacts are written in one transaction, so a failure
 * leaves nothing behind (Spec 16 B-09).
 */
class PreRegistrationStoreAtomicityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(SystemConfigurationSeeder::class);
    }

    public function test_a_failed_contact_write_leaves_no_pre_registration_and_sends_nothing(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        Http::fake(['https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9])]);
        Mail::fake();
        Notification::fake();
        PreRegistrationContact::creating(fn () => throw new RuntimeException('Contact insert failed.'));

        $response = $this->postJson('/api/v1/public/pre-registrations', [
            'branch_id' => $branch->id,
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
        ]);

        $response->assertInternalServerError();
        $this->assertDatabaseCount('pre_registrations', 0);
        $this->assertDatabaseCount('pre_registration_contacts', 0);
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
