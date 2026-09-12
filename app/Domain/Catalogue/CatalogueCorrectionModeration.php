<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\RecipeNutritionRecalculationDispatcher;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueModerationDecision;
use App\Models\CatalogueNutrientObservation as ObservationModel;
use App\Models\CatalogueProviderRefresh;
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
        private RecipeNutritionRecalculationDispatcher $nutritionRecalculations,
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
                'provenance' => $change->provenance,
                'conflict' => ! $this->fields->equal(
                    $change->field_key,
                    $change->before_value,
                    $currentValue,
                    includeSourcePrecision: $proposal->proposal_type === CatalogueChangeProposalType::ProviderRefresh,
                ),
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

        $decision = DB::transaction(function () use ($proposalId, $actor, $staleReviewed): CatalogueModerationDecision {
            $proposal = CatalogueCorrectionProposal::query()->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->state !== CatalogueCorrectionProposalState::Pending) {
                return CatalogueModerationDecision::query()->where('correction_proposal_id', $proposal->id)->firstOrFail();
            }
            $providerRefresh = $this->lockProviderRefresh($proposal);
            $isProviderRefresh = $providerRefresh !== null;
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
                $isProviderRefresh ? AuditAction::CatalogueProviderRefreshAccepted : AuditAction::CatalogueCorrectionAccepted,
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
                'action' => $isProviderRefresh ? 'provider_refresh_accept' : 'correction_accept',
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
                    if ($isProviderRefresh && $change->field_key === 'name') {
                        $attributes['name_source'] = CatalogueItemSource::OpenFoodFacts;
                    }
                } elseif (in_array($change->field_key, [CatalogueCorrectionFields::KEYWORDS, CatalogueCorrectionFields::CATEGORIES], true)) {
                    $attributes[$change->field_key] = $change->proposed_value['values'];
                    $attributes[$change->field_key.'_source'] = CatalogueItemSource::OpenFoodFacts;
                } elseif ($change->field_key === CatalogueCorrectionFields::IMAGE) {
                    $attributes['image_url'] = $change->proposed_value['value'];
                    $attributes['image_source'] = CatalogueItemSource::OpenFoodFacts;
                } elseif ($change->field_key === CatalogueCorrectionFields::PACKAGE) {
                    $attributes = [...$attributes, ...$change->proposed_value];
                    if ($isProviderRefresh) {
                        $supplied = $change->provenance['supplied_fields'] ?? [];
                        if (array_intersect($supplied, ['package_count', 'item_type', 'amount_per_item', 'amount_per_item_unit']) !== []) {
                            $attributes['package_source'] = CatalogueItemSource::OpenFoodFacts;
                        }
                        if (array_intersect($supplied, ['servings_per_item', 'serving_amount', 'serving_amount_unit']) !== []
                            || ($change->proposed_value['serving_amount_basis'] ?? null) === ServingAmountBasis::AmountPerItemDividedByServingsPerItem->value) {
                            $attributes['serving_source'] = CatalogueItemSource::OpenFoodFacts;
                        }
                    }
                }
            }
            $newVersion = new CatalogueItemVersion;
            $newVersion->forceFill([
                ...$attributes,
                'id' => $newVersionId,
                'catalogue_item_id' => $item->id,
                'version_number' => ((int) CatalogueItemVersion::query()->where('catalogue_item_id', $item->id)->max('version_number')) + 1,
                'correction_proposal_id' => $isProviderRefresh ? null : $proposal->id,
                'correction_decision_id' => $isProviderRefresh ? null : $decision->id,
                'provider_refresh_id' => $providerRefresh?->id,
                'corrected_fields' => $isProviderRefresh ? null : $proposal->changes->pluck('field_key')->values()->all(),
                'refreshed_fields' => $isProviderRefresh ? $proposal->changes->pluck('field_key')->values()->all() : null,
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
                    if ($isProviderRefresh) {
                        $observations[] = $this->fields->providerObservationFromPayload(
                            $change->field_key,
                            $change->proposed_value,
                            $change->provenance ?? [],
                            $providerRefresh->id,
                        );
                    } else {
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
            }
            $this->nutrition->store($newVersion, $observations);
            $item->setCurrentVersion($newVersion);
            $proposal->forceFill(['state' => CatalogueCorrectionProposalState::Accepted, 'decided_at' => Date::now()->utc()])->save();
            if ($providerRefresh !== null) {
                $providerRefresh->forceFill([
                    'state' => CatalogueProviderRefreshState::Accepted,
                    'active_key' => null,
                    'completed_at' => Date::now()->utc(),
                ])->save();
            }

            return $decision;
        }, 3);

        $approvedVersionId = $decision->evidence['new_version_id'] ?? null;
        if (in_array($decision->action, ['correction_accept', 'provider_refresh_accept'], true)
            && is_string($approvedVersionId)) {
            $approvedVersion = CatalogueItemVersion::query()->findOrFail($approvedVersionId);
            $this->nutritionRecalculations->dispatchForApprovedVersion($approvedVersion, $decision->id);
        }

        return $decision;
    }

    public function reject(string $proposalId, User $actor, Session $session, string $reasonCode = 'insufficient_evidence', ?string $note = null): CatalogueModerationDecision
    {
        $this->authorization->authorize($actor, $session);

        return DB::transaction(function () use ($proposalId, $actor, $reasonCode, $note): CatalogueModerationDecision {
            $proposal = CatalogueCorrectionProposal::query()->lockForUpdate()->findOrFail($proposalId);
            if ($proposal->state !== CatalogueCorrectionProposalState::Pending) {
                return CatalogueModerationDecision::query()->where('correction_proposal_id', $proposal->id)->firstOrFail();
            }
            $providerRefresh = $this->lockProviderRefresh($proposal);
            $isProviderRefresh = $providerRefresh !== null;
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
                $isProviderRefresh ? AuditAction::CatalogueProviderRefreshRejected : AuditAction::CatalogueCorrectionRejected,
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
                'action' => $isProviderRefresh ? 'provider_refresh_reject' : 'correction_reject',
                'catalogue_item_id' => $item->id,
                'correction_proposal_id' => $proposal->id,
                'actor_identity_id' => $event->actor_identity_id,
                'reason_code' => $reasonCode,
                'note' => CatalogueName::optional($note, 500),
                'evidence' => ['base_version_id' => $proposal->base_catalogue_item_version_id, 'current_version_id' => $currentId],
            ]);
            $proposal->forceFill(['state' => CatalogueCorrectionProposalState::Rejected, 'decided_at' => Date::now()->utc()])->save();
            if ($providerRefresh !== null) {
                $providerRefresh->forceFill([
                    'state' => CatalogueProviderRefreshState::Rejected,
                    'active_key' => null,
                    'completed_at' => Date::now()->utc(),
                ])->save();
            }

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
            $source->provider_refresh_id,
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

    private function lockProviderRefresh(CatalogueCorrectionProposal $proposal): ?CatalogueProviderRefresh
    {
        if ($proposal->proposal_type === CatalogueChangeProposalType::UserCorrection) {
            if ($proposal->provider_refresh_id !== null) {
                $this->conflict('A user correction cannot reference provider refresh work.');
            }

            return null;
        }

        $refresh = CatalogueProviderRefresh::query()->lockForUpdate()->find($proposal->provider_refresh_id);
        if ($refresh === null
            || $refresh->state !== CatalogueProviderRefreshState::Staged
            || $refresh->catalogue_item_id !== $proposal->catalogue_item_id
            || $refresh->base_catalogue_item_version_id !== $proposal->base_catalogue_item_version_id
        ) {
            $this->conflict('The staged provider refresh is no longer eligible for this decision.');
        }

        return $refresh;
    }

    private function conflict(string $message): never
    {
        throw ValidationException::withMessages(['moderation' => $message]);
    }
}
