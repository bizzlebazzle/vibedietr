<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientObservation as ObservationModel;
use App\Models\CatalogueNutrientValue;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class CatalogueNutritionNormalizer
{
    public const POLICY_VERSION = 1;

    private const CATALOGUE_BASES = [
        NutrientBasis::Per100Gram,
        NutrientBasis::Per100Millilitre,
        NutrientBasis::PerServing,
    ];

    public function __construct(private NutrientUnitConverter $unitConverter = new NutrientUnitConverter) {}

    /**
     * @param  list<mixed>  $inputs
     * @return Collection<int, CatalogueNutrientValue>
     */
    public function store(CatalogueItemVersion $version, array $inputs): Collection
    {
        if (! $version->exists) {
            throw new InvalidArgumentException('Catalogue nutrition requires a persisted catalogue version.');
        }

        if ($version->nutrientValues()->exists() || $version->nutrientObservations()->exists()) {
            throw new LogicException('Catalogue nutrition is immutable; create a new catalogue version.');
        }

        $validated = $this->validateInputs($inputs);

        return DB::transaction(function () use ($version, $validated): Collection {
            /** @var array<string, ObservationModel> $observations */
            $observations = [];

            foreach ($validated as $key => $input) {
                $observations[$key] = $this->storeObservation($version, $input);
            }

            $facts = collect();

            foreach ($validated as $key => $input) {
                if (in_array($input->nutrient, [Nutrient::EnergyKcal, Nutrient::EnergyKj], true)) {
                    continue;
                }

                $facts->push($this->storeDirectFact($version, $input, $observations[$key]));
            }

            foreach (self::CATALOGUE_BASES as $basis) {
                $facts->push(...$this->storeEnergyFacts($version, $basis, $validated, $observations));
            }

            return $facts->values();
        });
    }

    /**
     * @param  list<mixed>  $inputs
     * @return array<string, CatalogueNutrientObservation>
     */
    private function validateInputs(array $inputs): array
    {
        $validated = [];

        foreach ($inputs as $input) {
            if (! $input instanceof CatalogueNutrientObservation) {
                throw new InvalidArgumentException('Nutrition inputs must use the catalogue observation contract.');
            }

            if (! in_array($input->basis, self::CATALOGUE_BASES, true)) {
                throw new InvalidArgumentException("Unsupported catalogue nutrition basis: {$input->basis->value}.");
            }

            if ($input->provenance === NutrientProvenance::Derived) {
                throw new InvalidArgumentException('Derived values are created only by the normalizer.');
            }

            if ($input->provenance === NutrientProvenance::Imported && $input->source === null) {
                throw new InvalidArgumentException('Imported nutrition requires a bounded provider source.');
            }

            $this->validateSourceField($input->sourceField);
            $this->validateUnit($input);
            $this->validateStatus($input);

            $key = $this->key($input->nutrient, $input->basis);

            if (array_key_exists($key, $validated)) {
                throw new InvalidArgumentException("Duplicate source observation: {$key}.");
            }

            $validated[$key] = $input;
        }

        return $validated;
    }

    private function validateSourceField(?string $sourceField): void
    {
        if ($sourceField === null) {
            return;
        }

        if ($sourceField === '' || trim($sourceField) !== $sourceField || mb_strlen($sourceField) > 64) {
            throw new InvalidArgumentException('Source field identifiers must be non-blank, trim-stable, and at most 64 characters.');
        }
    }

    private function validateUnit(CatalogueNutrientObservation $input): void
    {
        $valid = match ($input->nutrient) {
            Nutrient::EnergyKcal => $input->unit === NutrientUnit::Kilocalorie,
            Nutrient::EnergyKj => $input->unit === NutrientUnit::Kilojoule,
            default => in_array($input->unit, [NutrientUnit::Gram, NutrientUnit::Milligram], true),
        };

        if (! $valid) {
            throw new InvalidArgumentException(
                "Invalid unit {$input->unit->value} for nutrient {$input->nutrient->value}.",
            );
        }
    }

    private function validateStatus(CatalogueNutrientObservation $input): void
    {
        $hasValue = $input->value !== null;
        $hasThreshold = $input->thresholdValue !== null;

        $valid = match ($input->status) {
            NutrientValueStatus::Known,
            NutrientValueStatus::Approximate => $hasValue && ! $hasThreshold,
            NutrientValueStatus::BelowLimit => ! $hasValue && $hasThreshold,
            NutrientValueStatus::Missing,
            NutrientValueStatus::Trace,
            NutrientValueStatus::NotSignificantSource => ! $hasValue && ! $hasThreshold,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Invalid amount/status combination for {$input->nutrient->value}.");
        }

        if ($input->value !== null) {
            $this->sourceDecimal($input->value);
        }

        if ($input->thresholdValue !== null) {
            $this->sourceDecimal($input->thresholdValue);
        }
    }

    private function storeObservation(
        CatalogueItemVersion $version,
        CatalogueNutrientObservation $input,
    ): ObservationModel {
        $sourceNumber = $input->value ?? $input->thresholdValue;
        $sourceScale = $sourceNumber === null ? null : $this->sourceScale($sourceNumber);
        $precisionReduced = $sourceScale !== null && $sourceScale > 18;

        return ObservationModel::query()->forceCreate([
            'catalogue_item_version_id' => $version->getKey(),
            'nutrient' => $input->nutrient,
            'basis' => $input->basis,
            'value' => $input->value === null ? null : $this->storage($input->value),
            'threshold_value' => $input->thresholdValue === null ? null : $this->storage($input->thresholdValue),
            'unit' => $input->unit,
            'status' => $input->status,
            'provenance' => $input->provenance,
            'source' => $input->source,
            'source_field' => $input->sourceField,
            'source_scale' => $sourceScale,
            'precision_reduced' => $precisionReduced,
            'source_observed_at' => $input->sourceObservedAt,
            'imported_at' => $input->importedAt,
            'normalization_policy_version' => self::POLICY_VERSION,
        ]);
    }

    private function storeDirectFact(
        CatalogueItemVersion $version,
        CatalogueNutrientObservation $input,
        ObservationModel $observation,
    ): CatalogueNutrientValue {
        $canonicalUnit = NutrientRegistry::definition($input->nutrient)->canonicalStorageUnit;

        return $this->storeFact(
            version: $version,
            observation: $observation,
            nutrient: $input->nutrient,
            basis: $input->basis,
            value: $this->canonicalAmount($input->value, $input->unit, $canonicalUnit),
            thresholdValue: $this->canonicalAmount($input->thresholdValue, $input->unit, $canonicalUnit),
            unit: $canonicalUnit,
            status: $input->status,
            provenance: $input->provenance,
            warning: $observation->precision_reduced
                ? NutrientNormalizationWarning::SourcePrecisionReduced
                : null,
        );
    }

    /**
     * @param  array<string, CatalogueNutrientObservation>  $inputs
     * @param  array<string, ObservationModel>  $observations
     * @return list<CatalogueNutrientValue>
     */
    private function storeEnergyFacts(
        CatalogueItemVersion $version,
        NutrientBasis $basis,
        array $inputs,
        array $observations,
    ): array {
        $kcalKey = $this->key(Nutrient::EnergyKcal, $basis);
        $kjKey = $this->key(Nutrient::EnergyKj, $basis);
        $kcalInput = $inputs[$kcalKey] ?? null;
        $kjInput = $inputs[$kjKey] ?? null;

        if ($kcalInput === null && $kjInput === null) {
            return [];
        }

        if ($kcalInput?->value === null && $kjInput?->value === null) {
            $facts = [];

            if ($kcalInput !== null) {
                $facts[] = $this->storeDirectFact($version, $kcalInput, $observations[$kcalKey]);
            }

            if ($kjInput !== null) {
                $facts[] = $this->storeDirectFact($version, $kjInput, $observations[$kjKey]);
            }

            return $facts;
        }

        $authoritativeInput = $kcalInput?->value !== null ? $kcalInput : $kjInput;
        $authoritativeKey = $kcalInput?->value !== null ? $kcalKey : $kjKey;
        assert($authoritativeInput !== null && $authoritativeInput->value !== null);

        $canonicalKcal = $authoritativeInput->nutrient === Nutrient::EnergyKcal
            ? $this->storage($authoritativeInput->value)
            : $this->storage($this->unitConverter->convert(
                $authoritativeInput->value,
                NutrientUnit::Kilojoule,
                NutrientUnit::Kilocalorie,
            ));

        $conflict = false;

        if ($kcalInput?->value !== null && $kjInput?->value !== null) {
            $suppliedKjAsKcal = $this->storage($this->unitConverter->convert(
                $kjInput->value,
                NutrientUnit::Kilojoule,
                NutrientUnit::Kilocalorie,
            ));
            $conflict = $suppliedKjAsKcal !== $canonicalKcal;
        }

        $sourceObservation = $observations[$authoritativeKey];
        $warning = $conflict
            ? NutrientNormalizationWarning::EnergySourceConflict
            : ($sourceObservation->precision_reduced
                ? NutrientNormalizationWarning::SourcePrecisionReduced
                : null);

        $kcalFact = $this->storeFact(
            version: $version,
            observation: $sourceObservation,
            nutrient: Nutrient::EnergyKcal,
            basis: $basis,
            value: $canonicalKcal,
            thresholdValue: null,
            unit: NutrientUnit::Kilocalorie,
            status: $authoritativeInput->status,
            provenance: $kcalInput?->value !== null
                ? $kcalInput->provenance
                : NutrientProvenance::Derived,
            derivation: $kcalInput?->value !== null ? null : NutrientDerivation::EnergyKcalFromKj,
            warning: $warning,
        );

        $kjFact = $this->storeFact(
            version: $version,
            observation: $sourceObservation,
            nutrient: Nutrient::EnergyKj,
            basis: $basis,
            value: $canonicalKcal,
            thresholdValue: null,
            unit: NutrientUnit::Kilocalorie,
            status: $authoritativeInput->status,
            provenance: NutrientProvenance::Derived,
            derivation: NutrientDerivation::EnergyKjFromKcal,
            warning: $warning,
        );

        return [$kcalFact, $kjFact];
    }

    private function storeFact(
        CatalogueItemVersion $version,
        ObservationModel $observation,
        Nutrient $nutrient,
        NutrientBasis $basis,
        ?string $value,
        ?string $thresholdValue,
        NutrientUnit $unit,
        NutrientValueStatus $status,
        NutrientProvenance $provenance,
        ?NutrientDerivation $derivation = null,
        ?NutrientNormalizationWarning $warning = null,
    ): CatalogueNutrientValue {
        return CatalogueNutrientValue::query()->forceCreate([
            'catalogue_item_version_id' => $version->getKey(),
            'source_observation_id' => $observation->getKey(),
            'nutrient' => $nutrient,
            'basis' => $basis,
            'value' => $value,
            'threshold_value' => $thresholdValue,
            'unit' => $unit,
            'status' => $status,
            'provenance' => $provenance,
            'derivation' => $derivation,
            'normalization_warning' => $warning,
            'normalization_policy_version' => self::POLICY_VERSION,
        ]);
    }

    private function canonicalAmount(
        string|int|null $value,
        NutrientUnit $sourceUnit,
        NutrientUnit $canonicalUnit,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return $this->storage($this->unitConverter->convert($value, $sourceUnit, $canonicalUnit));
    }

    private function storage(string|int|BigDecimal $value): string
    {
        return Decimal::forStorage($value instanceof BigDecimal ? $value : $this->sourceDecimal($value));
    }

    private function sourceDecimal(string|int $value): BigDecimal
    {
        $lexical = trim((string) $value);

        if (strlen($lexical) > 128) {
            throw new InvalidArgumentException('Nutrient source values may not exceed 128 characters.');
        }

        return Decimal::parse($lexical);
    }

    private function sourceScale(string|int $value): int
    {
        $lexical = ltrim(trim((string) $value), '+');
        $point = strpos($lexical, '.');

        return $point === false ? 0 : strlen($lexical) - $point - 1;
    }

    private function key(Nutrient $nutrient, NutrientBasis $basis): string
    {
        return "{$basis->value}:{$nutrient->value}";
    }
}
