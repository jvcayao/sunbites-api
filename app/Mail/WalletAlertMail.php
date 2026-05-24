<?php

namespace App\Mail;

use App\Models\ParentUser;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalletAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly Student $student,
        public readonly float $currentBalance,
        public readonly float $threshold,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Low Wallet Balance Alert — '.$this->student->full_name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.wallet-alert',
            with: [
                'parent' => $this->parent,
                'student' => $this->student,
                'currentBalance' => $this->currentBalance,
                'threshold' => $this->threshold,
                'portalUrl' => config('app.portal_url'),
            ],
        );
    }
}
