<?php

namespace Tests\Feature\Contract\Portal;

use App\Models\ParentUser;
use Tests\Feature\Contract\ContractTestCase;

class FeedbackContractTest extends ContractTestCase
{
    public function test_parent_feedback_list_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->getJson('/api/v1/portal/feedback');

        $this->assertContract($response, 'GET api/v1/portal/feedback');
    }

    public function test_submitting_feedback_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->scenario->subscriptionStudent->id,
            'category' => 'Service',
            'rating' => 5,
            'message' => 'The staff were very patient with my son today.',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/feedback');
    }

    public function test_submitting_feedback_without_input_matches_contract(): void
    {
        $response = $this->scenario->asParent($this->scenario->parent)->postJson('/api/v1/portal/feedback', []);

        $this->assertContract($response, 'POST api/v1/portal/feedback', '422-validation');
    }

    public function test_submitting_feedback_without_linked_students_matches_contract(): void
    {
        $parentWithoutStudents = ParentUser::factory()->create([
            'first_name' => 'Nora',
            'last_name' => 'Navarro',
            'email' => 'nora.navarro@example.test',
            'phone' => '09187770000',
            'address' => '1 Quezon Street, Antipolo',
        ]);

        $response = $this->scenario->asParent($parentWithoutStudents)->postJson('/api/v1/portal/feedback', [
            'category' => 'Service',
            'rating' => 5,
            'message' => 'The staff were very patient with my son today.',
        ]);

        $this->assertContract($response, 'POST api/v1/portal/feedback', '422-no-linked-students');
    }
}
