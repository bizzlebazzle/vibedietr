<?php

namespace App\Domain\RecipeImports;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\RecipeImports\Parsing\ParsedRecipe;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecipeImportMaterializer
{
    public function __construct(private readonly RecipeImportMaterializationHook $hook) {}

    public function materialize(string $importId, ParsedRecipe $result): Recipe
    {
        $hook = $this->hook;

        return DB::transaction(function () use ($hook, $importId, $result): Recipe {
            $import = RecipeImport::query()->lockForUpdate()->findOrFail($importId);

            if ($import->status === RecipeImportStatus::ReviewReady && $import->recipe_id !== null) {
                return Recipe::query()->findOrFail($import->recipe_id);
            }

            if (! in_array($import->status, [RecipeImportStatus::Pending, RecipeImportStatus::Processing, RecipeImportStatus::Extracting], true)) {
                throw new LogicException('The recipe import is not materializable.');
            }

            $recipe = $import->recipe_id === null
                ? new Recipe
                : Recipe::query()->lockForUpdate()->findOrFail($import->recipe_id);

            if ($recipe->exists && ($recipe->user_id !== $import->user_id || $recipe->getRawOriginal('lifecycle') !== RecipeLifecycle::Draft->value)) {
                throw new LogicException('The import draft ownership or lifecycle is invalid.');
            }

            $recipe->fill([
                'title' => $result->title ?? 'Imported recipe',
                'servings' => $result->servings,
                'visibility' => RecipeVisibility::Private,
            ]);
            $recipe->forceFill(['lifecycle' => RecipeLifecycle::Draft]);
            $recipe->owner()->associate($import->user_id);
            $recipe->save();

            $recipe->ingredientLines()->delete();
            $recipe->instructionSteps()->delete();
            $recipe->instructionSections()->delete();

            foreach ($result->ingredients as $position => $parsed) {
                $standardUnit = $parsed->unit === null ? null : MeasurementUnitRegistry::findStandard($parsed->unit);
                $line = new RecipeIngredientLine;
                $line->forceFill([
                    'original_text' => $parsed->originalText,
                    'position' => $position,
                    'quantity' => $parsed->quantity,
                    'standard_unit' => $standardUnit?->value,
                    'custom_unit' => $standardUnit === null ? $parsed->unit : null,
                    'generic_wording' => $parsed->genericWording,
                    'notes' => $parsed->notes,
                    'requires_review' => $parsed->requiresReview(),
                    'parser_warnings' => $parsed->warnings === [] ? null : $parsed->warnings,
                    'uncertain_fields' => $parsed->uncertainFields === [] ? null : $parsed->uncertainFields,
                ]);
                $line->recipe()->associate($recipe);
                $line->save();
            }

            $sectionIds = [];
            foreach ($result->sections as $position => $parsed) {
                $section = new RecipeInstructionSection;
                $section->forceFill(['name' => $parsed->name, 'position' => $position]);
                $section->recipe()->associate($recipe);
                $section->save();
                $sectionIds[$parsed->key] = $section->getKey();
            }

            foreach ($result->steps as $position => $parsed) {
                $step = new RecipeInstructionStep;
                $step->forceFill([
                    'text' => $parsed->text,
                    'position' => $position,
                    'section_id' => $parsed->sectionKey === null ? null : ($sectionIds[$parsed->sectionKey] ?? null),
                    'requires_review' => $parsed->requiresReview(),
                    'parser_warnings' => $parsed->warnings === [] ? null : $parsed->warnings,
                    'uncertain_fields' => $parsed->uncertainFields === [] ? null : $parsed->uncertainFields,
                ]);
                $step->recipe()->associate($recipe);
                $step->save();
            }

            $import->forceFill([
                'recipe_id' => $recipe->getKey(),
                'status' => RecipeImportStatus::ReviewReady,
                'parser_identifier' => $result->parserIdentifier,
                'parser_version' => $result->parserVersion,
                'requires_review' => true,
                'warnings' => $result->warnings === [] ? null : $result->warnings,
                'provenance' => array_merge($import->provenance ?? [], [
                    'channel' => $import->type->value,
                    'parser' => $result->parserIdentifier,
                    'parser_version' => $result->parserVersion,
                    'parsed_at' => now()->utc()->toIso8601String(),
                ]),
                'completion_classification' => $result->completionClassification,
                'failure_category' => null,
                'failure_code' => null,
                'completed_at' => now()->utc(),
                'failed_at' => null,
            ])->save();

            $hook->beforeCommit($import, $recipe);

            return $recipe;
        }, 3);
    }
}
