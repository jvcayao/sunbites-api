<?php

namespace App\Jobs;

use App\Mail\WalletAlertMail;
use App\Models\Student;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;

#[Tries(3)]
#[Backoff(10, 60)]
class WalletAlertJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $studentId,
        public readonly float $currentBalance,
    ) {}

    public function handle(): void
    {
        $student = Student::find($this->studentId);

        if (! $student) {
            return;
        }

        $student->parents()
            ->wherePivot('wallet_alert_threshold', '>', $this->currentBalance)
            ->get()
            ->each(function ($parent) use ($student) {
                Mail::to($parent->email)->queue(
                    new WalletAlertMail($parent, $student, $this->currentBalance, (float) $parent->pivot->wallet_alert_threshold)
                );
            });
    }
}
