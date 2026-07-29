<?php

namespace App\Notifications;

use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CreditChargedNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly Student $student,
        public readonly float $amount,
        public readonly float $outstandingBalance,
    ) {
        // Hold the queued notification until the enclosing transaction commits, so a
        // rolled-back checkout never tells a parent their child borrowed money.
        //
        // Set here rather than as a property declaration: Queueable already declares
        // `public $afterCommit;` with no default, and PHP rejects any redeclaration whose
        // definition differs — including one that only adds a default — with a fatal
        // "define the same property ... considered incompatible" error.
        $this->afterCommit = true;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'student_id' => $this->student->id,
            'student_name' => $this->student->full_name,
            'amount' => round($this->amount, 2),
            'outstanding_balance' => round($this->outstandingBalance, 2),
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
        return 'CreditChargedNotification';
    }
}
