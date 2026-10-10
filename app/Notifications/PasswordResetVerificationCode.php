<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetVerificationCode extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password reset verification code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use the verification code below to continue resetting your Farming password:')
            ->line('**'.$this->code.'**')
            ->line('This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.');
    }
}
