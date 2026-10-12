<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;

class PreRegistrationContractTest extends ContractTestCase
{
    private const string ROUTE = 'POST api/v1/portal/pre-registrations';

    public function test_portal_pre_registration_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->submission());

        $this->assertContract($response, self::ROUTE);
    }

    public function test_portal_pre_registration_without_input_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', []);

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_portal_pre_registration_of_an_enrolled_student_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->submission([
            'first_name' => $this->scenario->subscriptionStudent->first_name,
            'last_name' => $this->scenario->subscriptionStudent->last_name,
            'birthday' => $this->scenario->subscriptionStudent->birthday->toDateString(),
        ]));

        $this->assertContract($response, self::ROUTE, '422-already-enrolled');
    }

    public function test_portal_pre_registration_of_a_pending_student_matches_contract(): void
    {
        $response = $this->postJson('/api/v1/portal/pre-registrations', $this->submission([
            'first_name' => $this->scenario->pendingPreRegistration->first_name,
            'last_name' => $this->scenario->pendingPreRegistration->last_name,
            'birthday' => $this->scenario->pendingPreRegistration->birthday->toDateString(),
        ]));

        $this->assertContract($response, self::ROUTE, '422-already-pending');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submission(array $overrides = []): array
    {
        return [
            'branch_id' => $this->scenario->branchA->id,
            'first_name' => 'Paolo',
            'last_name' => 'Mercado',
            'student_number' => 'ANT-2026-301',
            'grade_level' => 'Grade 1',
            'section' => 'Jacinto',
            'birthday' => '2019-05-05',
            'enrollment_type' => 'non_subscription',
            'allergies' => 'None',
            'notes' => 'Prefers vegetables.',
            'signatory_name' => 'Grace Mercado',
            'contacts' => [
                [
                    'full_name' => 'Grace Mercado',
                    'relationship' => 'Mother',
                    'phone' => '09195556666',
                    'address' => '5 Jacinto Street, Antipolo',
                    'email' => 'grace.mercado@example.test',
                    'is_primary' => true,
                ],
            ],
            ...$overrides,
        ];
    }
}
