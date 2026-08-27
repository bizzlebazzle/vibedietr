<?php

namespace App\Domain\RecipeImports\Parsing;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Shared\Decimal;
use InvalidArgumentException;

final class DeterministicRecipeTextParser implements RecipeTextParser
{
    public const IDENTIFIER = 'vibedietr.deterministic_text';

    public function parse(string $sourceText): ParsedRecipe
    {
        $lines = $this->lines($sourceText);
        $title = null;
        $servings = null;
        $ingredients = [];
        $sections = [];
        $steps = [];
        $warnings = [];
        $mode = null;
        $sectionKey = null;
        $sawMajorHeading = false;

        foreach ($lines as $line) {
            $working = $this->workingText($line);
            if ($working === '') {
                continue;
            }

            if ($servings === null && preg_match('/\b(?:serves?|servings?|yield)\s*:?\s*(\d+(?:\.\d+)?)\b/iu', $working, $match) === 1) {
                $servings = $match[1];
                if ($mode === null) {
                    continue;
                }
            }

            $heading = $this->majorHeading($working);
            if ($heading !== null) {
                $mode = $heading;
                $sectionKey = null;
                $sawMajorHeading = true;

                continue;
            }

            if ($title === null && $mode === null && ! $this->looksLikeIngredient($working) && ! $this->looksLikeInstruction($working)) {
                $title = $working;

                continue;
            }

            if ($mode === 'ingredients') {
                $ingredients[] = $this->ingredient($line);

                continue;
            }

            if ($mode === 'instructions') {
                if ($this->looksLikeSectionHeading($line, $working)) {
                    $sectionKey = 'section-'.count($sections);
                    $sections[] = new ParsedInstructionSection($sectionKey, mb_substr(rtrim($working, ':'), 0, 255));

                    continue;
                }

                $steps[] = new ParsedInstructionStep($line, $sectionKey);

                continue;
            }

            if (! $sawMajorHeading && $this->looksLikeIngredient($working)) {
                $ingredients[] = $this->ingredient($line);
            } elseif (! $sawMajorHeading && $this->looksLikeInstruction($working)) {
                $steps[] = new ParsedInstructionStep(
                    $line,
                    warnings: ['instruction_segmentation_uncertain'],
                    uncertainFields: ['segmentation'],
                );
            }
        }

        if ($ingredients === [] || $steps === []) {
            throw new NoCredibleRecipeStructure;
        }

        if ($title !== null && mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
            $warnings[] = 'title_uncertain';
        }
        if ($servings !== null && (strlen($servings) > 11 || (float) $servings > 99_999_999.99)) {
            $servings = null;
            $warnings[] = 'servings_uncertain';
        }
        if ($title === null) {
            $warnings[] = 'title_uncertain';
        }
        if ($servings === null) {
            $warnings[] = 'servings_uncertain';
        }
        if (! $sawMajorHeading) {
            $warnings[] = 'extraction_incomplete';
        }

        $hasUncertainty = $warnings !== []
            || collect($ingredients)->contains(fn (ParsedIngredientLine $line): bool => $line->requiresReview())
            || collect($steps)->contains(fn (ParsedInstructionStep $step): bool => $step->requiresReview());

        return new ParsedRecipe(
            title: $title,
            servings: $servings,
            ingredients: $ingredients,
            sections: $sections,
            steps: $steps,
            warnings: array_values(array_unique($warnings)),
            parserIdentifier: self::IDENTIFIER,
            parserVersion: (string) (config('production.imports.parser_version') ?: 'rec15-v1'),
            completionClassification: $hasUncertainty ? 'partial_reviewable' : 'structured_reviewable',
        );
    }

    /** @return list<string> */
    private function lines(string $sourceText): array
    {
        $working = $sourceText;
        if (preg_match('/<\/?[a-z][^>]*>/iu', $sourceText) === 1) {
            $working = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $working) ?? $working;
            $working = preg_replace('/<\/(?:p|div|li|h[1-6]|section|article)>/iu', "\n", $working) ?? $working;
            $working = strip_tags($working);
            $working = html_entity_decode($working, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return preg_split('/\R/u', $working) ?: [];
    }

    private function workingText(string $line): string
    {
        $working = trim($line);
        $working = preg_replace('/^#{1,6}\s+/u', '', $working) ?? $working;
        $working = preg_replace('/^(?:[-*+]\s+|\d+[.)]\s+)/u', '', $working) ?? $working;

        return trim($working);
    }

    private function majorHeading(string $working): ?string
    {
        $heading = mb_strtolower(rtrim($working, ':'));

        return match (true) {
            in_array($heading, ['ingredient', 'ingredients', 'what you need'], true) => 'ingredients',
            in_array($heading, ['instruction', 'instructions', 'method', 'directions', 'steps'], true) => 'instructions',
            default => null,
        };
    }

    private function looksLikeIngredient(string $working): bool
    {
        return preg_match('/^(?:\d+(?:\.\d+)?|\d+\s+\d+\/\d+|\d+\/\d+|[¼½¾⅓⅔⅛⅜⅝⅞])\b/u', $working) === 1
            || preg_match('/^(?:salt|pepper)\s+to\s+taste\b/iu', $working) === 1;
    }

    private function looksLikeInstruction(string $working): bool
    {
        return preg_match('/^(?:\d+[.)]\s*)?(?:add|bake|beat|blend|boil|chop|combine|cook|fold|heat|mix|place|pour|preheat|reduce|roast|season|serve|stir|whisk)\b/iu', $working) === 1;
    }

    private function looksLikeSectionHeading(string $line, string $working): bool
    {
        return (str_ends_with($working, ':') || preg_match('/^#{2,6}\s+/u', trim($line)) === 1)
            && ! $this->looksLikeInstruction($working);
    }

    private function ingredient(string $original): ParsedIngredientLine
    {
        $working = $this->workingText($original);
        $warnings = [];
        $uncertain = [];
        $quantity = null;
        $unit = null;
        $generic = $working;

        if (preg_match('/^(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?|[¼½¾⅓⅔⅛⅜⅝⅞])(?:\s+|$)(.*)$/u', $working, $match) === 1) {
            try {
                $quantity = Decimal::forStorage(Decimal::parse($this->quantity($match[1])));
            } catch (InvalidArgumentException) {
                $quantity = null;
                $warnings[] = 'ingredient_quantity_uncertain';
                $uncertain[] = 'quantity';
            }
            $remainder = $match[2];
            if (preg_match('/^([^\s,]+(?:\s+oz)?)(?:\s+|$)(.*)$/u', $remainder, $parts) === 1) {
                $candidateUnit = $parts[1];
                if (mb_strlen($candidateUnit) > 32) {
                    $candidateUnit = '';
                }
                $standard = MeasurementUnitRegistry::findStandard($candidateUnit);
                if ($standard !== null) {
                    $unit = $candidateUnit === '' ? null : $candidateUnit;
                    $generic = $parts[2];
                } else {
                    $unit = $candidateUnit;
                    $generic = $parts[2];
                    $warnings[] = 'ingredient_unit_uncertain';
                    $uncertain[] = 'unit';
                }
            } else {
                $generic = $remainder;
                $warnings[] = 'ingredient_unit_uncertain';
                $uncertain[] = 'unit';
            }
        } else {
            $warnings[] = 'ingredient_quantity_uncertain';
            $uncertain[] = 'quantity';
        }

        return new ParsedIngredientLine(
            originalText: $original,
            quantity: $quantity,
            unit: $unit,
            genericWording: $generic === '' ? null : mb_substr($generic, 0, 255),
            warnings: $warnings,
            uncertainFields: $uncertain,
        );
    }

    private function quantity(string $value): string
    {
        $unicode = ['¼' => '0.25', '½' => '0.5', '¾' => '0.75', '⅓' => '0.333333333333333333', '⅔' => '0.666666666666666667', '⅛' => '0.125', '⅜' => '0.375', '⅝' => '0.625', '⅞' => '0.875'];
        if (isset($unicode[$value])) {
            return $unicode[$value];
        }
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $parts) === 1) {
            return bcadd($parts[1], bcdiv($parts[2], $parts[3], 18), 18);
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $value, $parts) === 1) {
            return bcdiv($parts[1], $parts[2], 18);
        }

        return $value;
    }
}
