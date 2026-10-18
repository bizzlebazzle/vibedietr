<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditIntegrity;
use App\Models\AuditEvent;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\RecipeVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditProvenanceClock
{
    public function withdraw(RecipeVersion $version): void
    {
        $next = $version->recipe->versions()->where('version_number', '>', $version->version_number)->orderBy('version_number')->first();
        $end = $next->finalized_at ?? now()->utc()->toImmutable();
        foreach (AuditEvent::query()->where('payload->version_id', $version->id)->cursor() as $event) {
            $attributes = ['event_id' => $event->id, 'ended_at' => $end->utc()->format('Y-m-d\\TH:i:s\\Z')];
            DB::table('audit_provenance_clocks')->insertOrIgnore([
                'event_id' => $event->id, 'ended_at' => $end,
                'integrity_hash' => app(AuditIntegrity::class)->sign($attributes),
            ]);
        }
    }

    public function end(AuditEvent $event): ?CarbonImmutable
    {
        $clock = DB::table('audit_provenance_clocks')->where('event_id', $event->id)->first();
        if ($clock) {
            $end = CarbonImmutable::parse($clock->ended_at)->utc();
            if (! app(AuditIntegrity::class)->verifyAttributes(
                ['event_id' => $event->id, 'ended_at' => $end->format('Y-m-d\\TH:i:s\\Z')], $clock->integrity_hash)) {
                throw new RuntimeException('audit_retention_clock_invalid');
            }

            return $end;
        }
        $versionId = $event->payload['version_id'] ?? null;
        if (is_string($versionId)) {
            $version = RecipeVersion::query()->with('recipe')->find($versionId);
            if (! $version) {
                throw new RuntimeException('audit_retention_clock_missing');
            }
            $next = $version->recipe->versions()->where('version_number', '>', $version->version_number)->orderBy('version_number')->first();
            if ($next) {
                return $next->finalized_at;
            }
            if ($version->recipe->visibility->value === 'public') {
                return null;
            }
            $withdrawal = AuditEvent::query()->where('payload->version_id', $versionId)
                ->where('payload->resulting_visibility', 'private')->orderByDesc('occurred_at')->first();

            return $withdrawal->occurred_at ?? $version->finalized_at;
        }
        $id = (string) $event->subject_identifier;
        if ($event->subject_type->value === 'catalogue_item') {
            $item = CatalogueItem::query()->find((int) preg_replace('/^catalogue(-item)?:/', '', $id));
            if (! $item) {
                throw new RuntimeException('audit_retention_clock_missing');
            }
            $first = $item->versions()->orderBy('version_number')->first();
            $next = $item->versions()->where('version_number', '>', 1)->orderBy('version_number')->first();
            if ($next) {
                return $next->created_at->toImmutable();
            }
            if (in_array($item->status->value, ['approved', 'pending'], true)) {
                return null;
            }
            $decision = DB::table('catalogue_moderation_decisions')->where('catalogue_item_id', $item->id)->orderBy('created_at')->first();

            return $decision ? CarbonImmutable::parse($decision->created_at) : $first?->created_at?->toImmutable();
        }
        if ($event->subject_type->value === 'catalogue_proposal') {
            $proposal = CatalogueCorrectionProposal::query()->find(preg_replace('/^proposal:/', '', $id));
            if (! $proposal) {
                throw new RuntimeException('audit_retention_clock_missing');
            }
            if ($proposal->state->value === 'pending') {
                return null;
            }
            $version = DB::table('catalogue_item_versions')->where('correction_proposal_id', $proposal->id)->first();
            if ($version) {
                $next = DB::table('catalogue_item_versions')->where('catalogue_item_id', $version->catalogue_item_id)
                    ->where('version_number', '>', $version->version_number)->orderBy('version_number')->first();
                if (! $next) {
                    return null;
                }

                return CarbonImmutable::parse($next->created_at);
            }

            return $proposal->decided_at === null ? null : CarbonImmutable::parse($proposal->decided_at);
        }
        throw new RuntimeException('audit_retention_clock_missing');
    }
}
