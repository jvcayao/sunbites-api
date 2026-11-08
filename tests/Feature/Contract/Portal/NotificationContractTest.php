<?php

namespace Tests\Feature\Contract\Portal;

use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class NotificationContractTest extends ContractTestCase
{
    private const string UNKNOWN_NOTIFICATION_ID = '00000000-0000-0000-0000-000000000000';

    public function test_parent_notification_list_matches_contract(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/notifications');

        $this->assertContract($response, 'GET api/v1/portal/notifications');
    }

    public function test_parent_unread_count_matches_contract(): void
    {
        $response = $this->asParent()->getJson('/api/v1/portal/notifications/unread-count');

        $this->assertContract($response, 'GET api/v1/portal/notifications/unread-count');
    }

    public function test_marking_all_parent_notifications_read_matches_contract(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/notifications/mark-all-read');

        $this->assertContract($response, 'POST api/v1/portal/notifications/mark-all-read');
    }

    public function test_clearing_parent_notifications_matches_contract(): void
    {
        $response = $this->asParent()->deleteJson('/api/v1/portal/notifications');

        $this->assertContract($response, 'DELETE api/v1/portal/notifications');
    }

    public function test_marking_a_parent_notification_read_matches_contract(): void
    {
        $response = $this->asParent()->patchJson("/api/v1/portal/notifications/{$this->firstNotificationId()}/read");

        $this->assertContract($response, 'PATCH api/v1/portal/notifications/{id}/read');
    }

    public function test_marking_an_unknown_parent_notification_read_matches_contract(): void
    {
        $response = $this->asParent()->patchJson('/api/v1/portal/notifications/'.self::UNKNOWN_NOTIFICATION_ID.'/read');

        $this->assertContract($response, 'PATCH api/v1/portal/notifications/{id}/read', '404');
    }

    public function test_deleting_a_parent_notification_matches_contract(): void
    {
        $response = $this->asParent()->deleteJson("/api/v1/portal/notifications/{$this->firstNotificationId()}");

        $this->assertContract($response, 'DELETE api/v1/portal/notifications/{id}');
    }

    public function test_deleting_an_unknown_parent_notification_matches_contract(): void
    {
        $response = $this->asParent()->deleteJson('/api/v1/portal/notifications/'.self::UNKNOWN_NOTIFICATION_ID);

        $this->assertContract($response, 'DELETE api/v1/portal/notifications/{id}', '404');
    }

    private function asParent(): TestCase
    {
        return $this->scenario->asParent($this->scenario->parent);
    }

    private function firstNotificationId(): string
    {
        return $this->scenario->parent->notifications()->firstOrFail()->id;
    }
}
