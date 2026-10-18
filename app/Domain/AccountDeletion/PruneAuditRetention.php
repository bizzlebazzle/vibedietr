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

        DB::table('catalogue_moderation_decisions')->whereNotNull('note')
            ->where('created_at', '<=', now()->utc()->subDays(30))->update(['note' => null]);
        foreach (DB::table('catalogue_moderation_decisions')->whereNotNull('actor_identity_id')
            ->where('created_at', '<=', now()->utc()->subMonthsNoOverflow(12)->addDay())->orderBy('created_at')->limit(200)->get() as $decision) {
            if (CarbonImmutable::parse($decision->created_at)->addMonthsNoOverflow(12)->lessThanOrEqualTo(now()->utc())) {
                DB::table('catalogue_moderation_decisions')->where('id', $decision->id)->update(['actor_identity_id' => null]);
            }
        }
        $identities = DB::table('audit_actor_identities')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('audit_events')
            ->whereColumn('audit_events.actor_identity_id', 'audit_actor_identities.id')
            ->orWhereColumn('audit_events.subject_identity_id', 'audit_actor_identities.id'))->limit(200)->pluck('id');
        foreach ($identities as $id) {
            DB::transaction(function () use ($id): void {
                $identity = DB::table('audit_actor_identities')->where('id', $id)->lockForUpdate()->first();
                if (! $identity || ($identity->user_id !== null && DB::table('account_deletions')->where('user_id', $identity->user_id)->exists())) {
                    return;
                }
                if (DB::table('audit_events')->where('actor_identity_id', $id)->orWhere('subject_identity_id', $id)->exists()
                    || DB::table('catalogue_moderation_decisions')->where('actor_identity_id', $id)->exists()) {
                    return;
                }
                DB::table('audit_actor_identities')->where('id', $id)->delete();
            }, 3);
        }

        return ['deleted' => $deleted, 'missing_clocks' => $missing];
    }
}
