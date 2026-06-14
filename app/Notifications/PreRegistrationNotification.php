<?php

namespace App\Notifications;

use App\Models\PreRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PreRegistrationNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PreRegistration $preRegistration) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'pre_registration_id' => $this->preRegistration->id,
            'student_name' => $this->preRegistration->full_name,
            'branch_name' => $this->preRegistration->branch->name ?? '',
            'enrollment_type' => $this->preRegistration->enrollment_type,
            'submitted_at' => $this->preRegistration->created_at,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function broadcastAs(): string
    {
        return 'PreRegistrationNotification';
    }
}
