<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password — Sunbites');
    }

    public function content(): Content
    {
        // http_build_query produces literal & separators. The view uses {!! !!}
        // intentionally to avoid &amp; in the href, which breaks link parsing in
        // Outlook on Windows and some email security scanners.
        $resetUrl = rtrim(config('app.pos_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return new Content(
            view: 'emails.reset-password',
            with: [
                'firstName' => $this->user->first_name,
                'resetUrl' => $resetUrl,
                'accountLabel' => 'Sunbites staff account',
                'expiresInMinutes' => config('auth.passwords.users.expire'),
            ],
        );
    }
}
