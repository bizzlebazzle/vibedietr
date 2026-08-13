<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

final readonly class EnergyNormalizer
{
    public function __construct(private NutrientUnitConverter $converter = new NutrientUnitConverter) {}

    /**
     * @param  array<string, int|string>  $nutrients
     * @return array<string, int|string>
     */
    public function normalize(array $nutrients): array
    {
        $kcalKey = Nutrient::EnergyKcal->value;
        $kjKey = Nutrient::EnergyKj->value;
        $kcal = $this->decimalAt($nutrients, $kcalKey);

        if ($kcal === null) {
            $sourceKilojoules = $this->decimalAt($nutrients, $kjKey);

            if ($sourceKilojoules === null) {
                unset($nutrients[$kcalKey], $nutrients[$kjKey]);

                return $nutrients;
            }

            $kcal = Decimal::parse(Decimal::forStorage($this->converter->convert(
                $sourceKilojoules,
                NutrientUnit::Kilojoule,
                NutrientUnit::Kilocalorie,
            )));
        }

        $kilojoules = $this->converter->convert(
            $kcal,
            NutrientUnit::Kilocalorie,
            NutrientUnit::Kilojoule,
        );

        $nutrients[$kcalKey] = $this->storageValue($kcal);
        $nutrients[$kjKey] = $this->storageValue($kilojoules);

        return $nutrients;
    }

    /** @param array<string, int|string> $nutrients */
    private function decimalAt(array $nutrients, string $key): ?BigDecimal
    {
        if (! array_key_exists($key, $nutrients)) {
            return null;
        }

        return Decimal::parse($nutrients[$key]);
    }

    private function storageValue(BigDecimal $value): int|string
    {
        return $value->isZero() ? 0 : Decimal::forStorage($value);
    }
}
