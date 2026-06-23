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
        $resetUrl = rtrim(config('app.pos_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return new Content(
            view: 'emails.staff-reset-password',
            with: [
                'name' => $this->user->first_name,
                'resetUrl' => $resetUrl,
            ],
        );
    }
}
