<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActorIdentityEraser;
use App\Audit\AuditIntegrity;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditRetentionClass;
use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditErasure
{
    /** @param list<int> $publicRecipeIds
     * @param  list<string>  $privateEventIds
     * @param  list<string>  $restoredIdentities
     */
    public function erase(?User $user, array $publicRecipeIds, array $privateEventIds, array $restoredIdentities = []): void
    {
        $identities = array_values(array_unique([...$restoredIdentities, ...($user
            ? DB::table('audit_actor_identities')->where('user_id', $user->getKey())->pluck('id')->all() : [])]));
        DB::table('audit_events')->whereIn('id', $privateEventIds)->delete();
        AuditEvent::query()->where(fn ($query) => $query->whereIn('actor_identity_id', $identities)
            ->orWhereIn('subject_identity_id', $identities))->orderBy('id')->chunkById(200, function ($events) use ($user, $identities, $publicRecipeIds): void {
                foreach ($events as $event) {
                    $attributes = [];
                    if ($event->retention_class === AuditRetentionClass::PrivateContentUntilFinalPurge) {
                        $recipeId = (int) preg_replace('/^recipe:/', '', (string) $event->subject_identifier);
                        $publicProvenance = $event->subject_type->value === 'recipe' && in_array($recipeId, $publicRecipeIds, true)
                            && in_array($event->action, [AuditAction::RecipeFinalized, AuditAction::RecipeRevisionPublished, AuditAction::RecipeVisibilityChanged, AuditAction::RecipeRemixed], true);
                        if ($publicProvenance) {
                            $attributes['retention_class'] = AuditRetentionClass::ProvenanceActiveVersionPlusTwelveMonths;
                        } else {
                            DB::table('audit_events')->where('id', $event->id)->delete();

                            continue;
                        }
                    }
                    if (in_array($event->actor_identity_id, $identities, true)) {
                        $privileged = $event->retention_class === AuditRetentionClass::PrivilegedIdentityTwelveMonths
                            || ($event->actor_type->value === 'administrator'
                                && $event->retention_class === AuditRetentionClass::ModerationDecisionTwelveMonths);
                        if ($privileged && $event->occurred_at->addMonthsNoOverflow(12)->isFuture()) {
                            $this->operator($user, $event->actor_identity_id, $event->id, $event->occurred_at->addMonthsNoOverflow(12));
                        } else {
                            $attributes['actor_identity_id'] = null;
                        }
                    }
                    if (in_array($event->subject_identity_id, $identities, true)) {
                        $attributes['subject_identity_id'] = null;
                    }
                    $event->forceFill($attributes);
                    $attributes['integrity_hash'] = app(AuditIntegrity::class)->sign(app(AuditIntegrity::class)->attributesFor($event));
                    if (isset($attributes['retention_class'])) {
                        $attributes['retention_class'] = $attributes['retention_class']->value;
                    }
                    DB::table('audit_events')->where('id', $event->id)->update($attributes);
                }
            });
        foreach (DB::table('catalogue_moderation_decisions')->whereIn('actor_identity_id', $identities)->get() as $decision) {
            $expiry = CarbonImmutable::parse($decision->created_at)->addMonthsNoOverflow(12);
            if ($expiry->isFuture()) {
                $this->operator($user, $decision->actor_identity_id, $decision->id, $expiry);
            }
            DB::table('catalogue_moderation_decisions')->where('id', $decision->id)->update([
                'note' => null, 'actor_identity_id' => $expiry->isFuture() ? $decision->actor_identity_id : null,
            ]);
        }
        DB::table('audit_actor_identities')->whereIn('id', $identities)->delete();
        if ($user) {
            app(AuditActorIdentityEraser::class)->eraseForUser($user);
        }
    }

    private function operator(?User $user, string $identity, string $event, CarbonInterface $expiry): void
    {
        if (DB::table('erasure_operator_mappings')->where('event_id', $event)->exists()) {
            return;
        }
        if (! $user) {
            throw new RuntimeException('operator_mapping_evidence_missing');
        }
        DB::table('erasure_operator_mappings')->insert([
            'identity_id' => $identity, 'event_id' => $event,
            // Dedicated accountability purpose; ordinary audit cannot resolve this table.
            'protected_reference' => Crypt::encryptString($user->email),
            'expires_at' => $expiry,
        ]);
    }
}
