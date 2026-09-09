<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\CatalogueModerationDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueModerationDecision> */
class CatalogueModerationDecisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'action' => 'approve',
            'catalogue_item_id' => CatalogueItem::factory()->approved(),
            'candidate_id' => null,
            'canonical_catalogue_item_id' => null,
            'corrects_decision_id' => null,
            'actor_identity_id' => null,
            'reason_code' => 'reviewed',
            'note' => null,
            'evidence' => [],
            'created_at' => now()->utc(),
        ];
    }

    public function mergeOperation(
        ?CatalogueItem $source = null,
        ?CatalogueItem $canonical = null,
        ?CatalogueDuplicateCandidate $candidate = null,
    ): static {
        return $this
            ->state(fn (): array => [
                'action' => 'merge',
                'catalogue_item_id' => $source?->getKey() ?? CatalogueItem::factory()->approved(),
                'candidate_id' => $candidate?->getKey(),
                'canonical_catalogue_item_id' => $canonical?->getKey() ?? CatalogueItem::factory()->approved(),
                'reason_code' => 'duplicate',
            ])
            ->afterCreating(function (CatalogueModerationDecision $decision): void {
                CatalogueItem::query()->whereKey($decision->catalogue_item_id)->update([
                    'status' => CatalogueItemStatus::Merged,
                    'canonical_catalogue_item_id' => $decision->canonical_catalogue_item_id,
                ]);
            });
    }

    public function correcting(CatalogueModerationDecision $original): static
    {
        return $this->state(fn (): array => [
            'action' => 'correct',
            'catalogue_item_id' => $original->catalogue_item_id,
            'candidate_id' => $original->candidate_id,
            'canonical_catalogue_item_id' => $original->canonical_catalogue_item_id,
            'corrects_decision_id' => $original->getKey(),
            'reason_code' => 'incorrect_decision',
        ]);
    }
}
