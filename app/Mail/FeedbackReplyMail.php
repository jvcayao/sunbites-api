<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Feedback $feedback) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A reply to your feedback — Sunbites');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback-reply',
            with: ['feedback' => $this->feedback],
        );
    }
}
