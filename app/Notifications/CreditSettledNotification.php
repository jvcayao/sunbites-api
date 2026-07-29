<?php

namespace App\Notifications;

use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CreditSettledNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly Student $student,
        public readonly float $amount,
        public readonly float $outstandingBalance,
        public readonly bool $wasWaived = false,
    ) {
        // See CreditChargedNotification: Queueable already declares $afterCommit, so it must
        // be assigned here rather than redeclared as a property.
        $this->afterCommit = true;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * The waive reason is deliberately absent. It can record personal circumstances and
     * stays staff-only; parents see only that the balance was cleared.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'student_id' => $this->student->id,
            'student_name' => $this->student->full_name,
            'amount' => round($this->amount, 2),
            'outstanding_balance' => round($this->outstandingBalance, 2),
            'was_waived' => $this->wasWaived,
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
        return 'CreditSettledNotification';
    }
}
