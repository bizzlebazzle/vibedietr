<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueDuplicateCandidateStatus;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueDuplicateCandidate> */
class CatalogueDuplicateCandidateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'first_catalogue_item_id' => CatalogueItem::factory()->approved(),
            'second_catalogue_item_id' => CatalogueItem::factory()->approved(),
            'evidence' => CatalogueDuplicateEvidence::ExactPrimaryName,
            'status' => CatalogueDuplicateCandidateStatus::PendingReview,
            'distinction_explanation' => null,
            'submitted_by_user_id' => null,
            'canonical_catalogue_item_id' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => [
            'status' => CatalogueDuplicateCandidateStatus::PendingReview,
            'canonical_catalogue_item_id' => null,
        ]);
    }

    public function distinct(): static
    {
        return $this->state(fn (): array => [
            'status' => CatalogueDuplicateCandidateStatus::ConfirmedDistinct,
            'canonical_catalogue_item_id' => null,
        ]);
    }

    public function duplicate(): static
    {
        return $this
            ->state(fn (): array => ['status' => CatalogueDuplicateCandidateStatus::ConfirmedDuplicate])
            ->afterCreating(function (CatalogueDuplicateCandidate $candidate): void {
                $candidate->forceFill([
                    'canonical_catalogue_item_id' => $candidate->first_catalogue_item_id,
                ])->save();
            });
    }

    public function dismissed(): static
    {
        return $this->state(fn (): array => [
            'status' => CatalogueDuplicateCandidateStatus::Dismissed,
            'canonical_catalogue_item_id' => null,
        ]);
    }
}
