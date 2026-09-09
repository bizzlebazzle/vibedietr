<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use Illuminate\Database\Eloquent\Builder;

final class ManualCatalogueDuplicateDetector
{
    /** @return list<ManualCatalogueDuplicateMatch> */
    public function strongMatches(ManualCatalogueSubmissionData $submission): array
    {
        $normalizedName = CatalogueName::normalize($submission->name);

        return CatalogueItem::query()
            ->with('currentVersion')
            ->where('status', CatalogueItemStatus::Approved)
            ->whereNotNull('current_catalogue_item_version_id')
            ->where(function (Builder $query) use ($normalizedName): void {
                $query->whereHas(
                    'currentVersion',
                    fn (Builder $version) => $version->where('normalized_name', $normalizedName),
                )->orWhereHas(
                    'aliases',
                    fn (Builder $alias) => $alias->where('normalized_alias', $normalizedName),
                );
            })
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->filter(fn (CatalogueItem $item): bool => $this->compatible($submission, $item->currentVersion))
            ->map(function (CatalogueItem $item) use ($normalizedName): ManualCatalogueDuplicateMatch {
                $version = $item->currentVersion;
                assert($version instanceof CatalogueItemVersion);

                return new ManualCatalogueDuplicateMatch(
                    (int) $item->getKey(),
                    (string) $version->getKey(),
                    trim((string) $version->name),
                    $version->normalized_name === $normalizedName
                        ? CatalogueDuplicateEvidence::ExactPrimaryName
                        : CatalogueDuplicateEvidence::ApprovedAlias,
                );
            })
            ->values()
            ->all();
    }

    /** @return list<ManualCatalogueDuplicateMatch> */
    public function fuzzySuggestions(ManualCatalogueSubmissionData $submission): array
    {
        $normalizedName = CatalogueName::normalize($submission->name);
        $prefix = mb_substr($normalizedName, 0, min(3, mb_strlen($normalizedName)));

        return CatalogueItem::query()
            ->with('currentVersion')
            ->where('status', CatalogueItemStatus::Approved)
            ->whereHas(
                'currentVersion',
                fn (Builder $version) => $version
                    ->whereNotNull('normalized_name')
                    ->where('normalized_name', 'like', $prefix.'%')
                    ->where('normalized_name', '<>', $normalizedName),
            )
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(function (CatalogueItem $item): ManualCatalogueDuplicateMatch {
                $version = $item->currentVersion;
                assert($version instanceof CatalogueItemVersion);

                return new ManualCatalogueDuplicateMatch(
                    (int) $item->getKey(),
                    (string) $version->getKey(),
                    trim((string) $version->name),
                    CatalogueDuplicateEvidence::FuzzySuggestion,
                );
            })
            ->all();
    }

    private function compatible(
        ManualCatalogueSubmissionData $submission,
        ?CatalogueItemVersion $candidate,
    ): bool {
        if ($candidate === null || $candidate->manual_food_classification !== $submission->classification) {
            return false;
        }

        $pairs = [
            [$submission->brand, $candidate->brand],
            [$submission->manufacturer, $candidate->manufacturer],
            [$submission->foodForm, $candidate->food_form],
            [$submission->preparation, $candidate->preparation],
            [$submission->treatment, $candidate->treatment],
            [$submission->composition, $candidate->composition],
        ];

        foreach ($pairs as [$submitted, $known]) {
            if ($submitted !== null && $known !== null
                && $this->normalizeComparable($submitted) !== $this->normalizeComparable($known)) {
                return false;
            }
        }

        if ($submission->classification === ManualFoodClassification::Branded) {
            return $this->sameKnown($submission->brand, $candidate->brand)
                || $this->sameKnown($submission->manufacturer, $candidate->manufacturer);
        }

        return true;
    }

    private function sameKnown(?string $submitted, ?string $known): bool
    {
        return $submitted !== null
            && $known !== null
            && $this->normalizeComparable($submitted) === $this->normalizeComparable($known);
    }

    private function normalizeComparable(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}
