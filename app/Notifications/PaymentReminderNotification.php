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
     * @param  Collection<int, array{school_month: string, school_year: int, due_date: Carbon, students: Collection<int, array{id: int, name: string, amount: float}>}>  $periods
     */
    public function __construct(
        public readonly ParentUser $parent,
        public readonly Collection $periods,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $primary = $this->periods->first();

        return [
            'school_month' => $primary['school_month'],
            'school_year' => $primary['school_year'],
            'due_date' => $primary['due_date']->toDateString(),
            'students' => $primary['students']->toArray(),
            'total_amount' => $this->periods->sum(fn ($p) => $p['students']->sum('amount')),
            'periods' => $this->periods->map(fn ($p) => [
                'school_month' => $p['school_month'],
                'school_year' => $p['school_year'],
                'due_date' => $p['due_date']->toDateString(),
                'students' => $p['students']->toArray(),
                'amount' => $p['students']->sum('amount'),
            ])->values()->toArray(),
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
