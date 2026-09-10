<?php

namespace App\Domain\Catalogue;

use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Domain\Shared\Decimal;
use Carbon\CarbonImmutable;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientObservation as ObservationModel;
use InvalidArgumentException;

final readonly class CatalogueCorrectionFields
{
    public const TEXT_FIELDS = ['name', 'brand', 'manufacturer'];

    public const PACKAGE = 'package';

    public const KEYWORDS = 'keywords';

    public const CATEGORIES = 'categories';

    public const IMAGE = 'image';

    public function __construct(private CatalogueNutritionNormalizer $nutrition) {}

    /** @return array<string, mixed>|null */
    public function current(CatalogueItemVersion $version, string $field): ?array
    {
        if (in_array($field, self::TEXT_FIELDS, true)) {
            $value = $version->{$field};

            return $value === null ? null : ['value' => $value];
        }

        if (in_array($field, [self::KEYWORDS, self::CATEGORIES], true)) {
            $values = $version->{$field};

            return $values === null || $values === [] ? null : ['values' => array_values($values)];
        }

        if ($field === self::IMAGE) {
            return $version->image_url === null ? null : ['value' => $version->image_url];
        }

        if ($field === self::PACKAGE) {
            return $version->packageStructure()->toAttributes();
        }

        [$nutrient, $basis] = $this->nutritionIdentity($field);
        $observation = $version->nutrientObservations()
            ->where('nutrient', $nutrient)->where('basis', $basis)->first();

        return $observation === null ? null : $this->observationPayload($observation);
    }

    /** @return array<string, mixed>|null */
    public function proposed(string $field, mixed $value): ?array
    {
        if (in_array($field, self::TEXT_FIELDS, true)) {
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException('Corrected text must be a string or null.');
            }
            $normalized = $field === 'name'
                ? CatalogueName::display((string) $value)
                : CatalogueName::optional($value);

            return $normalized === null ? null : ['value' => $normalized];
        }

        if ($field === self::PACKAGE) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Package corrections require a structured package value.');
            }
            $allowed = ['package_count', 'item_type', 'amount_per_item', 'amount_per_item_unit', 'servings_per_item', 'serving_amount', 'serving_amount_unit'];
            if (array_diff(array_keys($value), $allowed) !== []) {
                throw new InvalidArgumentException('Package correction contains protected fields.');
            }

            return PackageStructure::make(
                $value['package_count'] ?? null,
                $value['item_type'] ?? null,
                $value['amount_per_item'] ?? null,
                $value['amount_per_item_unit'] ?? null,
                $value['servings_per_item'] ?? null,
                $value['serving_amount'] ?? null,
                $value['serving_amount_unit'] ?? null,
            )->toAttributes();
        }

        [$nutrient, $basis] = $this->nutritionIdentity($field);
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_diff(array_keys($value), ['value', 'threshold_value', 'unit', 'status']) !== []) {
            throw new InvalidArgumentException('Nutrition corrections require a bounded observation value.');
        }

        $observation = new CatalogueNutrientObservation(
            $nutrient,
            $basis,
            $value['value'] ?? null,
            NutrientUnit::from((string) ($value['unit'] ?? '')),
            NutrientProvenance::Corrected,
            NutrientValueStatus::from((string) ($value['status'] ?? 'known')),
            $value['threshold_value'] ?? null,
        );
        $this->nutrition->validate([$observation]);

        return [
            'value' => $observation->value === null ? null : (string) $observation->value,
            'threshold_value' => $observation->thresholdValue === null ? null : (string) $observation->thresholdValue,
            'unit' => $observation->unit->value,
            'status' => $observation->status->value,
            'source_scale' => $this->scale($observation->value ?? $observation->thresholdValue),
        ];
    }

    /** @return array<string, mixed> */
    public function providerObservation(CatalogueNutrientObservation $observation): array
    {
        $this->nutrition->validate([$observation]);

        return [
            'value' => $observation->value === null ? null : (string) $observation->value,
            'threshold_value' => $observation->thresholdValue === null ? null : (string) $observation->thresholdValue,
            'unit' => $observation->unit->value,
            'status' => $observation->status->value,
            'source_scale' => $this->scale($observation->value ?? $observation->thresholdValue),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $provenance
     */
    public function providerObservationFromPayload(
        string $field,
        array $payload,
        array $provenance,
        string $providerRefreshId,
    ): CatalogueNutrientObservation {
        [$nutrient, $basis] = $this->nutritionIdentity($field);
        $observedAt = isset($provenance['observed_at']) && is_string($provenance['observed_at'])
            ? CarbonImmutable::parse($provenance['observed_at'])->utc()
            : null;

        $observation = new CatalogueNutrientObservation(
            $nutrient,
            $basis,
            $payload['value'] ?? null,
            NutrientUnit::from((string) ($payload['unit'] ?? '')),
            NutrientProvenance::Imported,
            NutrientValueStatus::from((string) ($payload['status'] ?? 'known')),
            $payload['threshold_value'] ?? null,
            CatalogueItemSource::OpenFoodFacts,
            isset($provenance['source_field']) && is_string($provenance['source_field'])
                ? $provenance['source_field']
                : null,
            $observedAt,
            $observedAt,
            providerRefreshId: $providerRefreshId,
        );
        $this->nutrition->validate([$observation]);

        return $observation;
    }

    /** @param array<string, mixed>|null $payload */
    public function observation(string $field, ?array $payload): ?CatalogueNutrientObservation
    {
        if ($payload === null) {
            return null;
        }
        [$nutrient, $basis] = $this->nutritionIdentity($field);

        return new CatalogueNutrientObservation(
            $nutrient, $basis, $payload['value'], NutrientUnit::from($payload['unit']),
            NutrientProvenance::Corrected, NutrientValueStatus::from($payload['status']),
            $payload['threshold_value'],
        );
    }

    /** @param array<string, mixed>|null $left
     * @param  array<string, mixed>|null  $right
     */
    public function equal(string $field, ?array $left, ?array $right, bool $includeSourcePrecision = false): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (in_array($field, [self::KEYWORDS, self::CATEGORIES], true)) {
            $a = $left['values'] ?? null;
            $b = $right['values'] ?? null;
            if (! is_array($a) || ! is_array($b)) {
                return false;
            }

            $a = array_values(array_unique($a));
            $b = array_values(array_unique($b));
            sort($a);
            sort($b);

            return $a === $b;
        }

        if (! str_starts_with($field, 'nutrition.')) {
            return $left === $right;
        }

        foreach (['unit', 'status'] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }

        foreach (['value', 'threshold_value'] as $key) {
            $a = $left[$key] ?? null;
            $b = $right[$key] ?? null;
            if ($a === null || $b === null) {
                if ($a !== $b) {
                    return false;
                }
            } elseif (! Decimal::parse($a)->isEqualTo(Decimal::parse($b))) {
                return false;
            }
        }

        if ($includeSourcePrecision && ($left['source_scale'] ?? null) !== ($right['source_scale'] ?? null)) {
            return false;
        }

        return true;
    }

    /** @return array{Nutrient, NutrientBasis} */
    private function nutritionIdentity(string $field): array
    {
        $parts = explode('.', $field);
        if (count($parts) !== 3 || $parts[0] !== 'nutrition') {
            throw new InvalidArgumentException('Correction field is not allowlisted.');
        }
        $nutrient = Nutrient::tryFrom($parts[1]);
        $basis = NutrientBasis::tryFrom($parts[2]);
        if ($nutrient === null || ! in_array($basis, [NutrientBasis::Per100Gram, NutrientBasis::Per100Millilitre, NutrientBasis::PerServing], true)) {
            throw new InvalidArgumentException('Correction nutrition identity is not supported.');
        }

        return [$nutrient, $basis];
    }

    /** @return array<string, mixed> */
    private function observationPayload(ObservationModel $observation): array
    {
        return [
            'value' => $this->lexical($observation->value, $observation->source_scale),
            'threshold_value' => $this->lexical($observation->threshold_value, $observation->source_scale),
            'unit' => $observation->unit->value,
            'status' => $observation->status->value,
            'source_scale' => $observation->source_scale,
        ];
    }

    private function lexical(?string $value, ?int $scale): ?string
    {
        if ($value === null || $scale === null) {
            return $value;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $scale === 0 ? $whole : $whole.'.'.substr(str_pad($fraction, $scale, '0'), 0, $scale);
    }

    private function scale(string|int|null $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $parts = explode('.', (string) $value, 2);

        return isset($parts[1]) ? strlen($parts[1]) : 0;
    }
}
