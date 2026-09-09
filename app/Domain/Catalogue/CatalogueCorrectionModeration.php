<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueModerationDecision;
use App\Models\CatalogueNutrientObservation as ObservationModel;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CatalogueCorrectionModeration
{
    public function __construct(
        private CatalogueModerationAuthorization $authorization,
        private CatalogueCorrectionFields $fields,
        private CatalogueNutritionNormalizer $nutrition,
        private AuditEventRecorder $audit,
    ) {}

    public function review(CatalogueCorrectionProposal $proposal): CatalogueCorrectionReview
    {
        $proposal->loadMissing(['changes', 'catalogueItem.currentVersion', 'baseVersion']);
        $current = $proposal->catalogueItem->currentVersion;
        $rows = [];
        foreach ($proposal->changes as $change) {
            $currentValue = $current === null ? null : $this->fields->current($current, $change->field_key);
            $rows[] = [
                'field' => $change->field_key,
                'before' => $change->before_value,
                'current' => $currentValue,
                'proposed' => $change->proposed_value,
                'conflict' => ! $this->fields->equal($change->field_key, $change->before_value, $currentValue),
            ];
        }

        return new CatalogueCorrectionReview(
            $proposal,
            $current?->id,
            $current?->id !== $proposal->base_catalogue_item_version_id,
            $rows,
        );
    }

    public function accept(string $proposalId, User $actor, Session $session, bool $staleReviewed = false): CatalogueModerationDecision
    {
        $this->authorization->authorize($actor, $session);

        return DB::transaction(function () use ($proposalId, $actor, $staleReviewed): CatalogueModerationDecision {
            $proposal = CatalogueCorrectionProposal::query()->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->state !== CatalogueCorrectionProposalState::Pending) {
                return CatalogueModerationDecision::query()->where('correction_proposal_id', $proposal->id)->firstOrFail();
            }
            $item = CatalogueItem::query()->lockForUpdate()->findOrFail($proposal->catalogue_item_id);
            $base = CatalogueItemVersion::query()->whereKey($proposal->base_catalogue_item_version_id)
                ->where('catalogue_item_id', $item->id)->first();
            $current = CatalogueItemVersion::query()->lockForUpdate()->find($item->current_catalogue_item_version_id);
            if ($base === null || $current === null || $item->status !== CatalogueItemStatus::Approved) {
                $this->conflict('The proposal target or base version is no longer eligible.');
            }

            $proposal->load('changes');
            $review = $this->review($proposal->setRelation('catalogueItem', $item->setRelation('currentVersion', $current))->setRelation('baseVersion', $base));
            if ($review->stale && ! $staleReviewed) {
                $this->conflict('This proposal is stale. Review base, current, proposed values and conflicts explicitly before acceptance.');
            }

            $decisionId = strtolower((string) Str::ulid());
            $newVersionId = strtolower((string) Str::ulid());
            $event = $this->audit->record(
                AuditAction::CatalogueCorrectionAccepted,
                AuditActor::administrator($actor),
                AuditSubject::resource(AuditSubjectType::CatalogueProposal, $proposal->id),
                [
                    'catalogue_item_id' => $item->id,
                    'base_version_id' => $base->id,
                    'current_version_id' => $current->id,
                    'new_version_id' => $newVersionId,
                    'decision_id' => $decisionId,
                    'outcome' => 'accepted',
                ],
                evidenceReference: $proposal->id,
            );
            $decision = CatalogueModerationDecision::query()->forceCreate([
                'id' => $decisionId,
                'action' => 'correction_accept',
                'catalogue_item_id' => $item->id,
                'correction_proposal_id' => $proposal->id,
                'actor_identity_id' => $event->actor_identity_id,
                'reason_code' => 'reviewed',
                'note' => null,
                'evidence' => [
                    'base_version_id' => $base->id,
                    'current_version_id' => $current->id,
                    'new_version_id' => $newVersionId,
                    'stale' => $review->stale,
                    'conflicts' => $review->conflicts(),
                ],
            ]);

            $attributes = $this->versionAttributes($current);
            foreach ($proposal->changes as $change) {
                if (in_array($change->field_key, CatalogueCorrectionFields::TEXT_FIELDS, true)) {
                    $attributes[$change->field_key] = $change->proposed_value['value'] ?? null;
                } elseif ($change->field_key === CatalogueCorrectionFields::PACKAGE) {
                    $attributes = [...$attributes, ...$change->proposed_value];
                }
            }
            $newVersion = new CatalogueItemVersion;
            $newVersion->forceFill([
                ...$attributes,
                'id' => $newVersionId,
                'catalogue_item_id' => $item->id,
                'version_number' => ((int) CatalogueItemVersion::query()->where('catalogue_item_id', $item->id)->max('version_number')) + 1,
                'correction_proposal_id' => $proposal->id,
                'correction_decision_id' => $decision->id,
                'corrected_fields' => $proposal->changes->pluck('field_key')->values()->all(),
            ]);
            $newVersion->save();

            $changedNutrition = $proposal->changes->keyBy('field_key');
            $observations = [];
            foreach ($current->nutrientObservations()->get() as $source) {
                $field = "nutrition.{$source->nutrient->value}.{$source->basis->value}";
                if (! $changedNutrition->has($field)) {
                    $observations[] = $this->copyObservation($source);
                }
            }
            foreach ($proposal->changes as $change) {
                if (str_starts_with($change->field_key, 'nutrition.')) {
                    $observation = $this->fields->observation($change->field_key, $change->proposed_value);
                    if ($observation !== null) {
                        $observations[] = new CatalogueNutrientObservation(
                            $observation->nutrient,
                            $observation->basis,
                            $observation->value,
                            $observation->unit,
                            $observation->provenance,
                            $observation->status,
                            $observation->thresholdValue,
                            correctionProposalId: $proposal->id,
                            correctionDecisionId: $decision->id,
                        );
                    }
                }
            }
            $this->nutrition->store($newVersion, $observations);
            $item->setCurrentVersion($newVersion);
            $proposal->forceFill(['state' => CatalogueCorrectionProposalState::Accepted, 'decided_at' => Date::now()->utc()])->save();

            return $decision;
        }, 3);
    }

    public function reject(string $proposalId, User $actor, Session $session, string $reasonCode = 'insufficient_evidence', ?string $note = null): CatalogueModerationDecision
    {
        $this->authorization->authorize($actor, $session);

        return DB::transaction(function () use ($proposalId, $actor, $reasonCode, $note): CatalogueModerationDecision {
            $proposal = CatalogueCorrectionProposal::query()->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->state !== CatalogueCorrectionProposalState::Pending) {
                return CatalogueModerationDecision::query()->where('correction_proposal_id', $proposal->id)->firstOrFail();
            }
            if (! in_array($reasonCode, ['reviewed', 'insufficient_evidence'], true)) {
                throw ValidationException::withMessages(['reason_code' => 'Choose a supported rejection reason.']);
            }
            $item = CatalogueItem::query()->lockForUpdate()->findOrFail($proposal->catalogue_item_id);
            $currentId = $item->current_catalogue_item_version_id;
            if ($currentId === null) {
                $this->conflict('The proposal target no longer has a current version.');
            }

            $decisionId = strtolower((string) Str::ulid());
            $event = $this->audit->record(
                AuditAction::CatalogueCorrectionRejected,
                AuditActor::administrator($actor),
                AuditSubject::resource(AuditSubjectType::CatalogueProposal, $proposal->id),
                [
                    'catalogue_item_id' => $item->id,
                    'base_version_id' => $proposal->base_catalogue_item_version_id,
                    'current_version_id' => $currentId,
                    'decision_id' => $decisionId,
                    'outcome' => 'rejected',
                ],
                evidenceReference: $proposal->id,
            );
            $decision = CatalogueModerationDecision::query()->forceCreate([
                'id' => $decisionId,
                'action' => 'correction_reject',
                'catalogue_item_id' => $item->id,
                'correction_proposal_id' => $proposal->id,
                'actor_identity_id' => $event->actor_identity_id,
                'reason_code' => $reasonCode,
                'note' => CatalogueName::optional($note, 500),
                'evidence' => ['base_version_id' => $proposal->base_catalogue_item_version_id, 'current_version_id' => $currentId],
            ]);
            $proposal->forceFill(['state' => CatalogueCorrectionProposalState::Rejected, 'decided_at' => Date::now()->utc()])->save();

            return $decision;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function versionAttributes(CatalogueItemVersion $version): array
    {
        return $version->only([
            'name', 'keywords', 'categories', 'image_url', 'name_source', 'keywords_source', 'categories_source',
            'package_source', 'serving_source', 'image_source', 'manual_food_classification', 'brand', 'manufacturer',
            'food_form', 'preparation', 'treatment', 'composition', 'package_count', 'item_type', 'amount_per_item',
            'amount_per_item_unit', 'servings_per_item', 'serving_amount', 'serving_amount_unit', 'serving_amount_basis',
        ]);
    }

    private function copyObservation(ObservationModel $source): CatalogueNutrientObservation
    {
        return new CatalogueNutrientObservation(
            $source->nutrient, $source->basis, $this->sourceLexical($source->value, $source->source_scale),
            $source->unit, $source->provenance, $source->status,
            $this->sourceLexical($source->threshold_value, $source->source_scale),
            $source->source, $source->source_field, $source->source_observed_at, $source->imported_at,
            $source->correction_proposal_id, $source->correction_decision_id,
        );
    }

    private function sourceLexical(?string $value, ?int $scale): ?string
    {
        if ($value === null || $scale === null) {
            return $value;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $scale === 0 ? $whole : $whole.'.'.substr(str_pad($fraction, $scale, '0'), 0, $scale);
    }

    private function conflict(string $message): never
    {
        throw ValidationException::withMessages(['moderation' => $message]);
    }
}
