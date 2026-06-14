<?php

namespace App\Mail;

use App\Models\PreRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PreRegistrationRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PreRegistration $preRegistration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Update on your pre-registration for {$this->preRegistration->full_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pre-registration.rejected',
            with: ['preRegistration' => $this->preRegistration],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
