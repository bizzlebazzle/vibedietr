<?php

namespace App\Security\Notifications;

use App\Jobs\DeliverSecurityNotification;
use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Security\SecurityAuditService;
use Illuminate\Support\Facades\DB;

final class SecurityNotificationIntentService
{
    public function __construct(private readonly SecurityAuditService $audit) {}

    /** @return list<SecurityNotificationIntent> */
    public function create(SecurityEventType $event, User $affected, string $correlationId): array
    {
        $recipients = collect([$affected->getKey() => ['user' => $affected, 'category' => 'affected_account']]);

        if ($event->notifyAllActiveAdministrators()) {
            User::query()->where('is_administrator', true)->whereNotNull('email_verified_at')->get()
                ->each(function (User $administrator) use ($recipients, $affected): void {
                    if ($administrator->is($affected)) {
                        return;
                    }

                    $recipients->put($administrator->getKey(), ['user' => $administrator, 'category' => 'active_administrator']);
                });
        }

        return DB::transaction(function () use ($recipients, $event, $correlationId): array {
            $intents = [];

            foreach ($recipients as $entry) {
                $recipient = $entry['user'];
                $destinationVersion = hash_hmac('sha256', strtolower($recipient->email).'|'.($recipient->email_verified_at === null ? 'unverified' : \Illuminate\Support\Facades\Date::parse($recipient->email_verified_at)->toIso8601String()), (string) config('app.key'));
                $key = hash('sha256', implode('|', [$event->value, $recipient->getKey(), 'mail', $destinationVersion, $correlationId]));
                $intent = SecurityNotificationIntent::query()->firstOrCreate(
                    ['idempotency_key' => $key],
                    [
                        'recipient_user_id' => $recipient->getKey(),
                        'event_type' => $event->value,
                        'recipient_category' => $entry['category'],
                        'destination_version' => $destinationVersion,
                        'correlation_id' => $correlationId,
                        'environment' => (string) config('app.env'),
                        'application_instance' => (string) config('administrator-security.notifications.application_instance'),
                        'status' => 'created',
                    ],
                );

                if ($intent->wasRecentlyCreated) {
                    $intent->update(['status' => 'queued']);
                    $this->audit->notification($recipient, $event->value, 'requested', $entry['category'], 'queued', $correlationId);
                    DeliverSecurityNotification::dispatch($intent->getKey())->afterCommit();
                }

                $intents[] = $intent;
            }

            return $intents;
        });
    }
}
