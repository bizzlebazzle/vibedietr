<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;

final readonly class CatalogueProviderRefreshDiffer
{
    public function __construct(private CatalogueCorrectionFields $fields) {}

    /**
     * @return list<array{field:string,before:array<string,mixed>|null,proposed:array<string,mixed>,provenance:array<string,mixed>}>
     */
    public function diff(
        CatalogueProviderRefresh $refresh,
        CatalogueItemVersion $base,
        CatalogueImportData $candidate,
    ): array {
        $changes = [];
        $common = [
            'provider' => CatalogueItemSource::OpenFoodFacts->value,
            'source_identifier' => $refresh->source_identifier,
        ];

        if ($candidate->name !== null) {
            $this->append($changes, $base, 'name', ['value' => $candidate->name], $common);
        }

        foreach ([
            CatalogueCorrectionFields::KEYWORDS => $candidate->keywords,
            CatalogueCorrectionFields::CATEGORIES => $candidate->categories,
        ] as $field => $values) {
            if ($values !== []) {
                $this->append($changes, $base, $field, ['values' => array_values($values)], $common);
            }
        }

        if ($candidate->imageUrl !== null) {
            $this->append($changes, $base, CatalogueCorrectionFields::IMAGE, ['value' => $candidate->imageUrl], $common);
        }

        $package = $this->packageCandidate($base, $candidate->package);
        if ($package !== null) {
            $this->append(
                $changes,
                $base,
                CatalogueCorrectionFields::PACKAGE,
                $package['value'],
                [...$common, 'supplied_fields' => $package['supplied_fields']],
            );
        }

        foreach ($candidate->nutrition as $observation) {
            $field = "nutrition.{$observation->nutrient->value}.{$observation->basis->value}";
            $proposed = $this->fields->providerObservation($observation);
            $before = $this->fields->current($base, $field);

            if ($this->fields->equal($field, $before, $proposed, includeSourcePrecision: true)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'before' => $before,
                'proposed' => $proposed,
                'provenance' => [
                    ...$common,
                    'source_field' => $observation->sourceField,
                    'source_scale' => $proposed['source_scale'],
                    'observed_at' => $observation->importedAt?->toIso8601String(),
                    'value_origin' => 'provider_supplied',
                ],
            ];
        }

        return $changes;
    }

    /**
     * @param  list<array{field:string,before:array<string,mixed>|null,proposed:array<string,mixed>,provenance:array<string,mixed>}>  $changes
     * @param  array<string,mixed>  $proposed
     * @param  array<string,mixed>  $provenance
     */
    private function append(array &$changes, CatalogueItemVersion $base, string $field, array $proposed, array $provenance): void
    {
        $before = $this->fields->current($base, $field);

        if ($this->fields->equal($field, $before, $proposed)) {
            return;
        }

        $changes[] = compact('field', 'before', 'proposed', 'provenance');
    }

    /**
     * @return array{value:array<string,int|string|null>,supplied_fields:list<string>}|null
     */
    private function packageCandidate(CatalogueItemVersion $base, PackageStructure $candidate): ?array
    {
        $candidateAttributes = $candidate->toAttributes();
        $sourceFields = ['package_count', 'item_type', 'amount_per_item', 'amount_per_item_unit', 'servings_per_item'];
        $supplied = array_values(array_filter(
            $sourceFields,
            static fn (string $field): bool => $candidateAttributes[$field] !== null,
        ));

        if ($candidate->servingAmountBasis === ServingAmountBasis::Source) {
            $supplied[] = 'serving_amount';
            $supplied[] = 'serving_amount_unit';
        }

        if ($supplied === []) {
            return null;
        }

        $baseStructure = $base->packageStructure();
        $baseAttributes = $baseStructure->toAttributes();
        foreach ($sourceFields as $field) {
            if ($candidateAttributes[$field] !== null) {
                $baseAttributes[$field] = $candidateAttributes[$field];
            }
        }

        $servingAmount = $baseStructure->servingAmountBasis === ServingAmountBasis::Source
            ? $baseAttributes['serving_amount']
            : null;
        $servingUnit = $baseStructure->servingAmountBasis === ServingAmountBasis::Source
            ? $baseAttributes['serving_amount_unit']
            : null;

        if ($candidate->servingAmountBasis === ServingAmountBasis::Source) {
            $servingAmount = $candidateAttributes['serving_amount'];
            $servingUnit = $candidateAttributes['serving_amount_unit'];
        }

        $value = PackageStructure::make(
            packageCount: $baseAttributes['package_count'],
            itemType: $baseAttributes['item_type'],
            amountPerItem: $baseAttributes['amount_per_item'],
            amountPerItemUnit: $baseAttributes['amount_per_item_unit'],
            servingsPerItem: $baseAttributes['servings_per_item'],
            servingAmount: $servingAmount,
            servingAmountUnit: $servingUnit,
        )->toAttributes();

        return ['value' => $value, 'supplied_fields' => array_values(array_unique($supplied))];
    }
}
