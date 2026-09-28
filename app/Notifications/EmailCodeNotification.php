<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailCodeNotification extends Notification
{
    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your ".config('app.name').' code')
            ->line('Use this code to confirm it\'s you before adding a passkey:')
            ->line("**{$this->code}**")
            ->line("It expires in {$this->minutes} minutes. If you didn't try to sign in, someone may have your password: change it.");
    }
}
