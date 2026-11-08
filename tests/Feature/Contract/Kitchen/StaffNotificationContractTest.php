<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Notifications\PreRegistrationNotification;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class StaffNotificationContractTest extends ContractTestCase
{
    private const string UNKNOWN_NOTIFICATION_ID = '00000000-0000-0000-0000-000000000000';

    public function test_staff_notification_list_matches_contract(): void
    {
        $this->notifyCashier();

        $response = $this->asCashier()->getJson('/api/v1/staff/notifications');

        $this->assertContract($response, 'GET api/v1/staff/notifications');
    }

    public function test_staff_unread_count_matches_contract(): void
    {
        $this->notifyCashier();

        $response = $this->asCashier()->getJson('/api/v1/staff/notifications/unread-count');

        $this->assertContract($response, 'GET api/v1/staff/notifications/unread-count');
    }

    public function test_marking_all_staff_notifications_read_matches_contract(): void
    {
        $this->notifyCashier();

        $response = $this->asCashier()->postJson('/api/v1/staff/notifications/mark-all-read');

        $this->assertContract($response, 'POST api/v1/staff/notifications/mark-all-read');
    }

    public function test_marking_a_staff_notification_read_matches_contract(): void
    {
        $id = $this->notifyCashier();

        $response = $this->asCashier()->patchJson("/api/v1/staff/notifications/{$id}/read");

        $this->assertContract($response, 'PATCH api/v1/staff/notifications/{id}/read');
    }

    public function test_marking_an_unknown_staff_notification_read_matches_contract(): void
    {
        $response = $this->asCashier()->patchJson('/api/v1/staff/notifications/'.self::UNKNOWN_NOTIFICATION_ID.'/read');

        $this->assertContract($response, 'PATCH api/v1/staff/notifications/{id}/read', '404');
    }

    public function test_deleting_a_staff_notification_matches_contract(): void
    {
        $id = $this->notifyCashier();

        $response = $this->asCashier()->deleteJson("/api/v1/staff/notifications/{$id}");

        $this->assertContract($response, 'DELETE api/v1/staff/notifications/{id}');
    }

    public function test_deleting_an_unknown_staff_notification_matches_contract(): void
    {
        $response = $this->asCashier()->deleteJson('/api/v1/staff/notifications/'.self::UNKNOWN_NOTIFICATION_ID);

        $this->assertContract($response, 'DELETE api/v1/staff/notifications/{id}', '404');
    }

    private function asCashier(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->cashier);
    }

    private function notifyCashier(): string
    {
        $this->scenario->cashier->notify(new PreRegistrationNotification($this->scenario->pendingPreRegistration));

        return $this->scenario->cashier->notifications()->firstOrFail()->id;
    }
}
