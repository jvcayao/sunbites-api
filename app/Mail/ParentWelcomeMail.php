<?php

namespace App\Mail;

use App\Models\ParentUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParentWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Sunbites! Please activate your account');
    }

    public function content(): Content
    {
        $portalUrl = config('app.portal_url');
        $activationUrl = $portalUrl.'/activate?token='.urlencode($this->token).'&email='.urlencode($this->parent->email);

        return new Content(
            view: 'emails.parent-welcome',
            with: [
                'parent' => $this->parent,
                'activationUrl' => $activationUrl,
            ],
        );
    }
}
