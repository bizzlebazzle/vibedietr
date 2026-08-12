<?php

namespace App\Security\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SecurityEventNotification extends Notification
{
    public function __construct(
        public readonly SecurityEventType $event,
        public readonly string $correlationId,
        public readonly string $environment,
        public readonly string $applicationInstance,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer((string) config('administrator-security.notifications.mailer'))
            ->subject('[Security] '.$this->event->label())
            ->greeting('Security notification')
            ->line($this->event->label().'.')
            ->line('Time: '.now()->utc()->toIso8601String())
            ->line('Environment: '.$this->environment)
            ->line('Application instance: '.$this->applicationInstance)
            ->line('Reference: '.$this->correlationId)
            ->line('If you did not expect this event, secure your account and contact the application operator.');
    }
}
