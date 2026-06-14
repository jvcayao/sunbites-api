<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class StaffResetPasswordNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password — Sunbites')
            ->view('emails.staff-reset-password', [
                'resetUrl' => $this->resetUrl($notifiable),
                'name' => $notifiable->first_name,
            ]);
    }
}
