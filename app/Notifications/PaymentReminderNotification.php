<?php

namespace App\Notifications;

use App\Models\ParentUser;
use Carbon\Carbon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class PaymentReminderNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly string $school_month,
        public readonly int $school_year,
        public readonly Collection $students,
        public readonly Carbon $due_date,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'school_month' => $this->school_month,
            'school_year' => $this->school_year,
            'due_date' => $this->due_date->toDateString(),
            'students' => $this->students->map(fn ($s) => [
                'name' => $s['name'],
                'amount' => $s['amount'],
            ])->toArray(),
            'total_amount' => $this->students->sum('amount'),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("parents.{$this->parent->id}")];
    }

    public function broadcastAs(): string
    {
        return 'PaymentReminderNotification';
    }
}
