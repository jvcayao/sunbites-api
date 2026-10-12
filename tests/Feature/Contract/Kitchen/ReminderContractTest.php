<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Notification;
use Tests\Feature\Contract\ContractTestCase;

class ReminderContractTest extends ContractTestCase
{
    public function test_reminder_bell_count_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->cashier)->getJson('/api/v1/reminders/bell-count');

        $this->assertContract($response, 'GET api/v1/reminders/bell-count');
    }

    public function test_eligible_parents_match_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reminders/eligible-parents');

        $this->assertContract($response, 'GET api/v1/reminders/eligible-parents');
    }

    public function test_eligible_parents_with_an_unknown_status_match_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reminders/eligible-parents?status=unknown');

        $this->assertContract($response, 'GET api/v1/reminders/eligible-parents', '422-validation');
    }

    public function test_reminder_parent_detail_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/reminders/parents/{$this->scenario->parent->id}");

        $this->assertContract($response, 'GET api/v1/reminders/parents/{parent}');
    }

    public function test_reminder_detail_for_a_parent_without_subscription_students_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/reminders/parents/{$this->scenario->unactivatedParent->id}");

        $this->assertContract($response, 'GET api/v1/reminders/parents/{parent}', '403-no-subscription-students');
    }

    public function test_reminder_detail_for_an_other_branch_parent_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/reminders/parents/{$this->scenario->branchBParent->id}");

        $this->assertContract($response, 'GET api/v1/reminders/parents/{parent}', 'other-branch');
    }

    public function test_sending_reminders_matches_contract(): void
    {
        Notification::fake();

        $response = $this->scenario->asStaff($this->scenario->manager)->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->scenario->parent->id],
            'force' => true,
        ]);

        $this->assertContract($response, 'POST api/v1/reminders/send');
    }

    public function test_sending_reminders_that_were_already_sent_matches_contract(): void
    {
        Notification::fake();

        $response = $this->scenario->asStaff($this->scenario->manager)->postJson('/api/v1/reminders/send', [
            'parent_ids' => [$this->scenario->parent->id],
        ]);

        $this->assertContract($response, 'POST api/v1/reminders/send', 'ok-skipped');
    }

    public function test_sending_reminders_without_parents_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->postJson('/api/v1/reminders/send', []);

        $this->assertContract($response, 'POST api/v1/reminders/send', '422-validation');
    }
}
