<?php

namespace Tests\Feature\Contract\Public;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Contract\ContractTestCase;

class PreRegistrationContractTest extends ContractTestCase
{
    private const string ROUTE = 'POST api/v1/public/pre-registrations';

    private const string RECAPTCHA_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function test_public_pre_registration_matches_contract(): void
    {
        $this->fakeRecaptcha(success: true);
        Mail::fake();
        Notification::fake();

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->submission());

        $this->assertContract($response, self::ROUTE);
    }

    public function test_honeypot_submission_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/public/pre-registrations', $this->submission(['website' => 'https://spam.example']));

        $this->assertContract($response, self::ROUTE, 'ok-honeypot');
    }

    public function test_public_pre_registration_without_input_matches_contract(): void
    {
        $this->fakeRecaptcha(success: true);

        $response = $this->postJson('/api/v1/public/pre-registrations', ['recaptcha_token' => 'fake-token']);

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_failed_recaptcha_matches_contract(): void
    {
        $this->fakeRecaptcha(success: false);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->submission());

        $this->assertContract($response, self::ROUTE, '422-recaptcha-failed');
    }

    public function test_unreachable_recaptcha_matches_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::RECAPTCHA_URL => Http::failedConnection()]);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->submission());

        $this->assertContract($response, self::ROUTE, '503-recaptcha-unavailable');
    }

    public function test_public_pre_registration_ending_before_it_starts_matches_contract(): void
    {
        $this->fakeRecaptcha(success: true);

        $response = $this->postJson('/api/v1/public/pre-registrations', $this->submission([
            'subscription_start_month' => 'march',
            'subscription_start_year' => 2027,
            'subscription_end_month' => 'june',
            'subscription_end_year' => 2026,
        ]));

        $this->assertContract($response, self::ROUTE, '422-end-before-start');
    }

    private function fakeRecaptcha(bool $success): void
    {
        Http::preventStrayRequests();
        Http::fake([self::RECAPTCHA_URL => Http::response(['success' => $success, 'score' => 0.9])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submission(array $overrides = []): array
    {
        return [
            'recaptcha_token' => 'fake-token',
            'branch_id' => $this->scenario->branchA->id,
            'first_name' => 'Bianca',
            'last_name' => 'Soriano',
            'student_number' => 'ANT-2026-401',
            'grade_level' => 'Grade 1',
            'section' => 'Jacinto',
            'birthday' => '2019-11-11',
            'enrollment_type' => 'subscription',
            'allergies' => 'None',
            'notes' => 'First time at Sunbites.',
            'subscription_start_month' => 'november',
            'subscription_start_year' => 2026,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2027,
            'signatory_name' => 'Lorna Soriano',
            'acknowledged_at' => now()->toDateTimeString(),
            'contacts' => [
                [
                    'full_name' => 'Lorna Soriano',
                    'relationship' => 'Mother',
                    'phone' => '09196667777',
                    'address' => '8 Jacinto Street, Antipolo',
                    'email' => 'lorna.soriano@example.test',
                    'is_primary' => true,
                ],
            ],
            ...$overrides,
        ];
    }
}
