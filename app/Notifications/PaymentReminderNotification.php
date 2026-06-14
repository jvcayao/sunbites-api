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

    /**
     * @param  Collection<int, array{id: int, full_name: string, amount: float}>  $students
     */
    public function __construct(
        public readonly ParentUser $parent,
        public readonly string $schoolMonth,
        public readonly int $schoolYear,
        public readonly Carbon $dueDate,
        public readonly Collection $students,
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
            'school_month' => $this->schoolMonth,
            'school_year' => $this->schoolYear,
            'due_date' => $this->dueDate->toDateString(),
            'students' => $this->students->toArray(),
            'total_amount' => $this->students->sum('amount'),
            'note' => 'If you have already paid, please disregard this notification.',
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
