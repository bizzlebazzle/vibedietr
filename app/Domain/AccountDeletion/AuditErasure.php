<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActorIdentityEraser;
use App\Audit\AuditIntegrity;
use App\Audit\Enums\AuditRetentionClass;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class AuditErasure
{
    /** @param list<int> $publicRecipeIds
     * @param  list<string>  $privateEventIds
     */
    public function erase(User $user, array $publicRecipeIds, array $privateEventIds): void
    {
        $identities = DB::table('audit_actor_identities')->where('user_id', $user->getKey())->pluck('id')->all();
        DB::table('audit_events')->whereIn('id', $privateEventIds)->delete();
        AuditEvent::query()->where(fn ($query) => $query->whereIn('actor_identity_id', $identities)
            ->orWhereIn('subject_identity_id', $identities))->orderBy('id')->chunkById(200, function ($events) use ($user, $identities, $publicRecipeIds): void {
                foreach ($events as $event) {
                    $attributes = [];
                    if ($event->retention_class === AuditRetentionClass::PrivateContentUntilFinalPurge) {
                        if ($event->subject_type->value === 'recipe' && in_array((int) $event->subject_identifier, $publicRecipeIds, true)) {
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
                            DB::table('erasure_operator_mappings')->updateOrInsert(
                                ['identity_id' => $event->actor_identity_id, 'event_id' => $event->id],
                                ['protected_reference' => Crypt::encryptString($user->account_generation),
                                    'expires_at' => $event->occurred_at->addMonthsNoOverflow(12)],
                            );
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
        app(AuditActorIdentityEraser::class)->eraseForUser($user);
    }
}
