<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Mail;
use Tests\Feature\Contract\ContractTestCase;

class EnrollmentContractTest extends ContractTestCase
{
    public function test_enrollment_form_data_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/enrollment');

        $this->assertContract($response, 'GET api/v1/enrollment', mapPaths: ['school_month_defaults']);
    }

    public function test_enrollment_form_data_without_a_branch_matches_contract(): void
    {
        $response = $this->scenario->asStaffWithoutBranch($this->scenario->supervisor)->getJson('/api/v1/enrollment');

        $this->assertContract($response, 'GET api/v1/enrollment', '422-no-branch');
    }

    public function test_enrolling_a_subscription_student_matches_contract(): void
    {
        Mail::fake();

        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/enrollment', $this->enrollment());

        $this->assertContract($response, 'POST api/v1/enrollment');
    }

    public function test_enrolling_a_non_subscription_student_matches_contract(): void
    {
        Mail::fake();

        $payload = $this->enrollment(['student_type' => 'non_subscription']);
        unset($payload['subscription_start_month'], $payload['subscription_start_year'], $payload['subscription_end_month'], $payload['subscription_end_year']);

        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/enrollment', $payload);

        $this->assertContract($response, 'POST api/v1/enrollment', 'ok-non-subscription');
    }

    public function test_enrolling_without_input_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/enrollment', []);

        $this->assertContract($response, 'POST api/v1/enrollment', '422-validation');
    }

    public function test_enrolling_with_an_end_month_before_the_start_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/enrollment', $this->enrollment([
            'subscription_start_month' => 'march',
            'subscription_start_year' => 2027,
            'subscription_end_month' => 'june',
            'subscription_end_year' => 2026,
        ]));

        $this->assertContract($response, 'POST api/v1/enrollment', '422-end-before-start');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enrollment(array $overrides = []): array
    {
        return [
            'branch_id' => $this->scenario->branchA->id,
            'student_number' => 'ANT-2026-201',
            'first_name' => 'Carmen',
            'last_name' => 'Villanueva',
            'grade_level' => 'Grade 2',
            'section' => 'Aguinaldo',
            'birthday' => '2018-03-09',
            'student_type' => 'subscription',
            'allergies' => 'None',
            'notes' => 'New enrollee.',
            'contacts' => [
                [
                    'full_name' => 'Rita Villanueva',
                    'relationship' => 'Mother',
                    'phone' => '09191234567',
                    'address' => '22 Aguinaldo Street, Antipolo',
                    'email' => 'rita.villanueva@example.test',
                ],
            ],
            'signature' => 'Rita Villanueva',
            'permission_meals' => true,
            'permission_dietary' => true,
            'subscription_start_month' => 'october',
            'subscription_start_year' => 2026,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2027,
            ...$overrides,
        ];
    }
}
