<?php

namespace App\Domain\RecipeImports\Ocr;

use App\Domain\RecipeImports\Parsing\ParsedIngredientLine;
use App\Domain\RecipeImports\Parsing\ParsedInstructionStep;
use App\Domain\RecipeImports\Parsing\ParsedRecipe;

final class OcrQualityApplicator
{
    public function apply(ParsedRecipe $recipe, OcrResult $ocr): ParsedRecipe
    {
        $lineMap = [];
        foreach ($ocr->lines as $line) {
            $lineMap[trim($line->text)] = $line;
        }
        $ingredients = array_map(function (ParsedIngredientLine $ingredient) use ($lineMap): ParsedIngredientLine {
            $quality = $lineMap[trim($ingredient->originalText)] ?? null;
            if ($quality === null || $quality->confidence === 'reliable') {
                return $ingredient;
            }
            $warnings = [...$ingredient->warnings, 'ingredient_text_low_confidence'];
            $uncertain = [...$ingredient->uncertainFields, 'text'];
            if ($quality->containsCriticalUncertainty) {
                $warnings[] = 'ingredient_quantity_uncertain';
                $warnings[] = 'ingredient_unit_uncertain';
                $uncertain[] = 'quantity';
                $uncertain[] = 'unit';
            }

            return new ParsedIngredientLine(
                $ingredient->originalText, $ingredient->quantity, $ingredient->unit,
                $ingredient->genericWording, $ingredient->notes,
                array_values(array_unique($warnings)), array_values(array_unique($uncertain)),
            );
        }, $recipe->ingredients);
        $steps = array_map(function (ParsedInstructionStep $step) use ($lineMap): ParsedInstructionStep {
            $quality = $lineMap[trim($step->text)] ?? null;
            if ($quality === null || $quality->confidence === 'reliable') {
                return $step;
            }

            return new ParsedInstructionStep(
                $step->text,
                $step->sectionKey,
                array_values(array_unique([...$step->warnings, 'instruction_text_low_confidence'])),
                array_values(array_unique([...$step->uncertainFields, 'text'])),
            );
        }, $recipe->steps);
        $warnings = array_values(array_unique([...$recipe->warnings, ...$ocr->warnings]));
        $strong = $ocr->materiallyUncertain()
            || $recipe->completionClassification === 'reviewable_with_strong_warnings';
        if ($strong) {
            $warnings[] = 'possible_extraction_error';
        }

        return new ParsedRecipe(
            $recipe->title,
            $recipe->servings,
            $ingredients,
            $recipe->sections,
            $steps,
            array_values(array_unique($warnings)),
            $recipe->parserIdentifier,
            $recipe->parserVersion,
            $strong ? 'reviewable_with_strong_warnings' : 'reviewable',
        );
    }
}
