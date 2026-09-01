<?php

namespace App\Domain\Catalogue;

use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Models\CatalogueNutrientValue;

final readonly class CatalogueNutrientReadModel
{
    public function __construct(
        public string $nutrient,
        public ?string $value,
        public string $unit,
        public string $basis,
        public string $status,
        public string $provenance,
        public ?string $source,
        public ?string $derivation,
        public ?string $warning,
        public string $display,
    ) {}

    public static function fromValue(CatalogueNutrientValue $value): self
    {
        return new self(
            nutrient: $value->nutrient->value,
            value: $value->value,
            unit: $value->unit->value,
            basis: $value->basis->value,
            status: $value->status->value,
            provenance: $value->provenance->value,
            source: $value->sourceObservation?->source?->value,
            derivation: $value->derivation?->value,
            warning: $value->normalization_warning?->value,
            display: (new NutrientDisplayFormatter)->format(
                $value->nutrient,
                $value->value,
                $value->status,
                $value->threshold_value,
            ),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'nutrient' => $this->nutrient,
            'value' => $this->value,
            'unit' => $this->unit,
            'basis' => $this->basis,
            'status' => $this->status,
            'provenance' => $this->provenance,
            'source' => $this->source,
            'derivation' => $this->derivation,
            'warning' => $this->warning,
            'display' => $this->display,
        ];
    }
}
