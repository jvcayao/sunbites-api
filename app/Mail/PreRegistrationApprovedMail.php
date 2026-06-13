<?php

namespace App\Mail;

use App\Models\PreRegistration;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PreRegistrationApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PreRegistration $preRegistration,
        public readonly Student $student,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->preRegistration->full_name}'s enrollment has been approved!",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pre-registration.approved',
            with: [
                'preRegistration' => $this->preRegistration,
                'student' => $this->student,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
