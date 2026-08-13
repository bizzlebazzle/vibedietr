<?php

namespace Tests\Unit\Domain;

use App\Domain\Nutrition\EnergyConversion;
use App\Domain\Nutrition\EnergyNormalizer;
use App\Domain\Nutrition\Nutrient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnergyNormalizerTest extends TestCase
{
    #[DataProvider('energyInputs')]
    public function test_energy_is_normalized_with_kcal_as_the_authoritative_basis(
        array $input,
        array $expected,
    ): void {
        $this->assertSame($expected, (new EnergyNormalizer)->normalize($input));
    }

    public static function energyInputs(): iterable
    {
        yield 'kcal only' => [
            [Nutrient::EnergyKcal->value => '1.234567890123456789'],
            [
                Nutrient::EnergyKcal->value => '1.234567890123456789',
                Nutrient::EnergyKj->value => '5.165432052276543205',
            ],
        ];

        yield 'kJ only' => [
            [Nutrient::EnergyKj->value => '1.234567890123456789'],
            [
                Nutrient::EnergyKj->value => '1.234567890123456790',
                Nutrient::EnergyKcal->value => '0.295068807390883554',
            ],
        ];

        yield 'conflicting pair' => [
            [
                Nutrient::EnergyKcal->value => '1.234567890123456789',
                Nutrient::EnergyKj->value => '999.999999999999999999',
            ],
            [
                Nutrient::EnergyKcal->value => '1.234567890123456789',
                Nutrient::EnergyKj->value => '5.165432052276543205',
            ],
        ];

        yield 'explicit kcal zero' => [
            [Nutrient::EnergyKcal->value => 0],
            [Nutrient::EnergyKcal->value => 0, Nutrient::EnergyKj->value => 0],
        ];

        yield 'explicit kJ zero' => [
            [Nutrient::EnergyKj->value => 0],
            [Nutrient::EnergyKj->value => 0, Nutrient::EnergyKcal->value => 0],
        ];

        yield 'neither unit' => [
            [Nutrient::Protein->value => '2.000000000000000000'],
            [Nutrient::Protein->value => '2.000000000000000000'],
        ];

        yield 'small non-zero canonical energy' => [
            [Nutrient::EnergyKcal->value => '0.000000000000000001'],
            [
                Nutrient::EnergyKcal->value => '0.000000000000000001',
                Nutrient::EnergyKj->value => '0.000000000000000004',
            ],
        ];
    }

    public function test_the_conversion_factor_has_one_central_definition(): void
    {
        $this->assertSame('4.184', EnergyConversion::KILOJOULES_PER_KILOCALORIE);
    }
}
