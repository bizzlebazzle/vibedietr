<?php

namespace Tests\Unit\Domain;

use App\Domain\Recipes\InvalidOriginalRecipeServings;
use App\Domain\Recipes\RecipeQuantityFormatter;
use App\Domain\Recipes\RecipeQuantityPresenter;
use App\Domain\Recipes\RecipeQuantityScaler;
use PHPUnit\Framework\TestCase;

class RecipeQuantityScalerTest extends TestCase
{
    public function test_integer_decimal_and_fraction_quantities_scale_exactly(): void
    {
        $scaler = new RecipeQuantityScaler;

        foreach ([
            ['200', '4', '8', '400'],
            ['200', '4', '2', '100'],
            ['200', '4', '6', '300'],
            ['1.25', '4', '6', '1.875'],
            ['0.0001', '4', '2', '0.00005'],
            ['0.5', '4', '8', '1'],
            ['0.25', '4', '2', '0.125'],
            ['1.5', '4', '6', '2.25'],
        ] as [$quantity, $originalServings, $requestedServings, $expected]) {
            $this->assertTrue(
                $scaler->scale($quantity, $originalServings, $requestedServings)->isEqualTo($expected),
                "Expected {$quantity} at {$originalServings} servings to become {$expected} at {$requestedServings} servings.",
            );
        }
    }

    public function test_each_calculation_uses_the_original_value_without_cumulative_error(): void
    {
        $scaler = new RecipeQuantityScaler;
        $displayAtEight = $scaler->scale('200', '4', '8');
        $displayAtSixAfterEight = $scaler->scale('200', '4', '6');
        $displayAtSixDirectly = $scaler->scale('200', '4', '6');
        $displayAtThree = $scaler->scale('1.234567890123456789', '4', '3');
        $restored = $scaler->scale('1.234567890123456789', '4', '4');

        $this->assertTrue($displayAtEight->isEqualTo('400'));
        $this->assertTrue($displayAtSixAfterEight->isEqualTo($displayAtSixDirectly));
        $this->assertTrue($displayAtThree->isEqualTo('0.92592591759259259175'));
        $this->assertTrue($restored->isEqualTo('1.234567890123456789'));
    }

    public function test_invalid_saved_servings_never_become_an_assumed_denominator(): void
    {
        $scaler = new RecipeQuantityScaler;

        foreach (['0', '-1', 'invalid'] as $servings) {
            try {
                $scaler->scale('2', $servings, '4');
                $this->fail("Saved servings {$servings} did not fail.");
            } catch (InvalidOriginalRecipeServings) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_presenter_scales_standard_count_and_custom_units_without_conversion(): void
    {
        $display = (new RecipeQuantityPresenter)->present('4', '6', [
            $this->ingredient('200', 'gram', null, 'plain flour', 'sifted'),
            $this->ingredient('250', 'millilitre', null, 'stock'),
            $this->ingredient('2', 'piece', null, 'eggs'),
            $this->ingredient('2', null, 'pinches', 'seasoning'),
        ]);

        $this->assertSame(['300', '375', '3', '3'], array_column($display->ingredients, 'quantity'));
        $this->assertSame(['g', 'ml', 'piece', 'pinches'], array_column($display->ingredients, 'unit'));
        $this->assertSame('sifted', $display->ingredients[0]['notes']);
        $this->assertTrue($display->canResize);
    }

    public function test_unquantified_or_incomplete_lines_use_exact_original_text_without_inference(): void
    {
        $display = (new RecipeQuantityPresenter)->present('4', '8', [
            $this->ingredient(null, null, null, null, null, 'salt to taste'),
            $this->ingredient('2', 'piece', null, null, null, '2 eggs in their shells'),
        ]);

        $this->assertFalse($display->ingredients[0]['structured']);
        $this->assertSame('salt to taste', $display->ingredients[0]['original_text']);
        $this->assertNull($display->ingredients[0]['quantity']);
        $this->assertFalse($display->ingredients[1]['structured']);
        $this->assertSame('2 eggs in their shells', $display->ingredients[1]['original_text']);
    }

    public function test_formatter_uses_existing_three_decimal_convention_without_float_math(): void
    {
        $formatter = new RecipeQuantityFormatter;

        $this->assertSame('0.5', $formatter->format('0.5'));
        $this->assertSame('0.25', $formatter->format('0.25'));
        $this->assertSame('1.5', $formatter->format('1.5'));
        $this->assertSame('1.235', $formatter->format('1.2345'));
        $this->assertSame('<0.001', $formatter->format('0.00005'));
    }

    /**
     * @return array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}
     */
    private function ingredient(
        ?string $quantity,
        ?string $standardUnit,
        ?string $customUnit,
        ?string $wording,
        ?string $notes = null,
        string $originalText = 'original ingredient text',
    ): array {
        return [
            'original_text' => $originalText,
            'quantity' => $quantity,
            'standard_unit' => $standardUnit,
            'custom_unit' => $customUnit,
            'generic_wording' => $wording,
            'notes' => $notes,
        ];
    }
}
