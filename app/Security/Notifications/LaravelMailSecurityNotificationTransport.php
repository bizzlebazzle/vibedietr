<?php

namespace App\Security\Notifications;

use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Notifications\Channels\MailChannel;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final class LaravelMailSecurityNotificationTransport implements SecurityNotificationTransport
{
    public function __construct(private readonly MailChannel $mail) {}

    public function send(User $recipient, SecurityNotificationIntent $intent): string
    {
        if ($recipient->email_verified_at === null) {
            throw new NonRetryableJobException('security_destination_unverified');
        }

        $notification = new SecurityEventNotification(
            SecurityEventType::from($intent->event_type),
            $intent->correlation_id,
            $intent->environment,
            $intent->application_instance,
        );

        try {
            $sent = $this->mail->send($recipient, $notification);
        } catch (TransportExceptionInterface) {
            throw new RetryableJobException('security_provider_temporarily_unavailable');
        } catch (Throwable) {
            throw new RetryableJobException('security_delivery_unavailable');
        }

        if ($sent === null) {
            throw new NonRetryableJobException('security_destination_missing');
        }

        return $sent->getMessageId();
    }
}
