<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditIntegrity;
use App\Audit\Enums\AuditRetentionClass;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PruneAuditRetention
{
    /** @return array{deleted: int, missing_clocks: int} */
    public function run(): array
    {
        $deleted = 0;
        $missing = 0;
        $cursor = Cache::get('audit-retention:cursor');
        $events = AuditEvent::query()->where('retention_class', '!=', AuditRetentionClass::PrivateContentUntilFinalPurge->value)
            ->when(is_string($cursor), fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit(200)->get();
        foreach ($events as $event) {
            if (! app(AuditIntegrity::class)->verify($event)) {
                $missing++;

                continue;
            }
            $start = $event->occurred_at;
            try {
                $expiry = match ($event->retention_class) {
                    AuditRetentionClass::SecurityEventSixMonths => $start->addMonthsNoOverflow(6),
                    AuditRetentionClass::PrivilegedIdentityTwelveMonths,
                    AuditRetentionClass::ModerationDecisionTwelveMonths,
                    AuditRetentionClass::PurgeReceiptTwelveMonths,
                    AuditRetentionClass::OperationalEvidenceTwelveMonths => $start->addMonthsNoOverflow(12),
                    AuditRetentionClass::ModerationPartyThirtyDays => app(AuditProvenanceClock::class)->end($event)?->addDays(30),
                    AuditRetentionClass::ProvenanceActiveVersionPlusTwelveMonths => app(AuditProvenanceClock::class)->end($event)?->addMonthsNoOverflow(12),
                    AuditRetentionClass::PrivateContentUntilFinalPurge => null,
                };
            } catch (\RuntimeException) {
                $missing++;

                continue;
            }
            if ($expiry !== null && now()->utc()->greaterThanOrEqualTo($expiry)) {
                DB::table('audit_events')->where('id', $event->id)->delete();
                $deleted++;
            }
        }
        if ($events->count() === 200) {
            Cache::forever('audit-retention:cursor', $events->last()->id);
        } else {
            Cache::forget('audit-retention:cursor');
        }
        foreach (DB::table('erasure_operator_mappings')->where('expires_at', '<=', now()->utc())->cursor() as $mapping) {
            $event = AuditEvent::query()->find($mapping->event_id);
            if ($event && $event->actor_identity_id === $mapping->identity_id) {
                $event->forceFill(['actor_identity_id' => null]);
                DB::table('audit_events')->where('id', $event->id)->update([
                    'actor_identity_id' => null, 'integrity_hash' => app(AuditIntegrity::class)->sign(app(AuditIntegrity::class)->attributesFor($event)),
                ]);
            }
            DB::table('catalogue_moderation_decisions')->where('id', $mapping->event_id)->update(['actor_identity_id' => null]);
            DB::table('erasure_operator_mappings')->where('event_id', $mapping->event_id)->delete();
        }
        foreach (DB::table('account_erasure_receipts')->cursor() as $receipt) {
            if (CarbonImmutable::parse($receipt->completed_at)->addMonthsNoOverflow(12)->lessThanOrEqualTo(now()->utc())) {
                DB::table('account_erasure_receipts')->where('id', $receipt->id)->delete();
            }
        }

        return ['deleted' => $deleted, 'missing_clocks' => $missing];
    }
}
