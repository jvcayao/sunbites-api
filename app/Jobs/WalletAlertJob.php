<?php

namespace App\Jobs;

use App\Mail\WalletAlertMail;
use App\Models\Student;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

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
            ->wherePivot('wallet_alert_threshold', '>', 0)
            ->get()
            ->each(function ($parent) use ($student) {
                $threshold = (float) $parent->pivot->wallet_alert_threshold;

                if ($this->currentBalance < $threshold) {
                    Mail::to($parent->email)->queue(
                        new WalletAlertMail($parent, $student, $this->currentBalance, $threshold)
                    );
                }
            });
    }
}
