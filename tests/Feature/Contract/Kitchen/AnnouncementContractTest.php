<?php

namespace Tests\Feature\Contract\Kitchen;

use Illuminate\Support\Facades\Notification;
use Tests\Feature\Contract\ContractTestCase;

class AnnouncementContractTest extends ContractTestCase
{
    public function test_announcement_list_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/announcements');

        $this->assertContract($response, 'GET api/v1/announcements');
    }

    public function test_sending_an_announcement_matches_contract(): void
    {
        Notification::fake();

        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/announcements', [
            'title' => 'Water interruption',
            'message' => 'The canteen will serve packed meals on Friday.',
            'recipient_type' => 'parents',
            'recipient_ids' => [$this->scenario->parent->id],
        ]);

        $this->assertContract($response, 'POST api/v1/announcements');
    }

    public function test_sending_an_empty_announcement_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->postJson('/api/v1/announcements', []);

        $this->assertContract($response, 'POST api/v1/announcements', '422-validation');
    }

    public function test_announcement_detail_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/announcements/{$this->scenario->announcement->id}");

        $this->assertContract($response, 'GET api/v1/announcements/{announcement}');
    }

    public function test_other_branch_announcement_detail_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)
            ->getJson("/api/v1/announcements/{$this->scenario->branchBAnnouncement->id}");

        $this->assertContract($response, 'GET api/v1/announcements/{announcement}', 'other-branch');
    }
}
