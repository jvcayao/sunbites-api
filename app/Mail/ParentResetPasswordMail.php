<?php

namespace App\Mail;

use App\Models\ParentUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParentResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ParentUser $parent,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password — Sunbites Parent Portal');
    }

    public function content(): Content
    {
        // http_build_query produces literal & separators. The view uses {!! !!}
        // intentionally to avoid &amp; in the href, which breaks link parsing in
        // Outlook on Windows and some email security scanners.
        $resetUrl = rtrim(config('app.portal_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->parent->email,
        ]);

        return new Content(
            view: 'emails.parent-reset-password',
            with: [
                'firstName' => $this->parent->first_name,
                'resetUrl' => $resetUrl,
            ],
        );
    }
}
