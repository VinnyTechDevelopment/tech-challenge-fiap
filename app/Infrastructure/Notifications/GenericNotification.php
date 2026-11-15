<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Notification\Entities\Notification as EntityNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GenericNotification extends Notification
{
    public function __construct(public EntityNotification $notification) {}

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->notification->getSubject())
            ->line($this->notification->getContent());
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subject' => $this->notification->getSubject(),
            'content' => $this->notification->getContent(),
        ];
    }
}
