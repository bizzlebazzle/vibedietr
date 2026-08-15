<?php

namespace App\Domain\Recipes;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Shared\Decimal;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RecipeDraftEditor
{
    public function __construct(
        private readonly RecipeDraftFingerprint $fingerprint,
        private readonly RecipeDraftSaveHook $saveHook,
    ) {}

    /**
     * @param  array{title: string, servings: mixed, visibility: string}  $metadata
     * @param  list<array{id: int|null, original_text: string, quantity: mixed, unit: string, generic_wording: string, notes: string}>  $ingredients
     * @param  list<array{id: int|null, key: string, name: string}>  $sections
     * @param  list<array{id: int|null, text: string, section_key: string|null}>  $steps
     */
    public function save(
        int $recipeId,
        string $baselineFingerprint,
        array $metadata,
        array $ingredients,
        array $sections,
        array $steps,
    ): Recipe {
        return DB::transaction(function () use ($recipeId, $baselineFingerprint, $metadata, $ingredients, $sections, $steps): Recipe {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::authorize('update', $recipe);

            $storedIngredients = $recipe->ingredientLines()->lockForUpdate()->get();
            $storedSections = $recipe->instructionSections()->lockForUpdate()->get();
            $storedSteps = $recipe->instructionSteps()->lockForUpdate()->get();

            if (! hash_equals($baselineFingerprint, $this->fingerprint->forRecipe($recipe, $storedIngredients, $storedSections, $storedSteps))) {
                throw new StaleRecipeDraft;
            }

            $this->assertSubmittedIdsBelongToRecipe($ingredients, $storedIngredients, 'ingredients', 'ingredient line');
            $this->assertSubmittedIdsBelongToRecipe($sections, $storedSections, 'sections', 'instruction section');
            $this->assertSubmittedIdsBelongToRecipe($steps, $storedSteps, 'steps', 'instruction step');

            $recipe->update($metadata);
            $this->persistIngredients($recipe, $ingredients, $storedIngredients);
            $sectionIdsByKey = $this->persistSections($recipe, $sections, $storedSections);
            $this->persistSteps($recipe, $steps, $storedSteps, $sectionIdsByKey);
            $this->saveHook->beforeCommit($recipe);

            return $recipe;
        }, 3);
    }

    /**
     * @template TModel of Model
     *
     * @param  list<array{id: int|null}>  $submitted
     * @param  Collection<int, TModel>  $stored
     */
    private function assertSubmittedIdsBelongToRecipe(array $submitted, Collection $stored, string $key, string $label): void
    {
        $ids = collect($submitted)->pluck('id')->filter(fn ($id): bool => $id !== null)->map(fn ($id): int => (int) $id)->all();

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$key => "Each {$label} may appear only once."]);
        }

        if (array_diff($ids, $stored->modelKeys()) !== []) {
            throw ValidationException::withMessages([$key => "A submitted {$label} does not belong to this recipe."]);
        }
    }

    /**
     * @param  list<array{id: int|null, original_text: string, quantity: mixed, unit: string, generic_wording: string, notes: string}>  $submitted
     * @param  Collection<int, RecipeIngredientLine>  $stored
     */
    private function persistIngredients(Recipe $recipe, array $submitted, Collection $stored): void
    {
        $keptIds = collect($submitted)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $recipe->ingredientLines()->whereNotIn('id', $keptIds)->delete();
        $storedById = $stored->keyBy('id');
        $temporaryBase = ((int) $stored->max('position')) + count($submitted) + 1;
        $ordered = [];

        foreach ($submitted as $index => $state) {
            $line = $state['id'] === null ? new RecipeIngredientLine : $storedById->get($state['id']);
            assert($line instanceof RecipeIngredientLine);
            $standardUnit = trim($state['unit']) === '' ? null : MeasurementUnitRegistry::findStandard($state['unit']);
            $line->fill([
                'original_text' => $state['original_text'],
                'quantity' => $state['quantity'] === null || $state['quantity'] === '' ? null : Decimal::forStorage(Decimal::parse($state['quantity'])),
                'standard_unit' => $standardUnit?->value,
                'custom_unit' => $standardUnit === null && trim($state['unit']) !== '' ? $state['unit'] : null,
                'generic_wording' => $this->blankToNull($state['generic_wording']),
                'notes' => $this->blankToNull($state['notes']),
            ]);
            $line->position = $temporaryBase + $index;
            $line->recipe()->associate($recipe);
            $line->save();
            $ordered[] = $line;
        }

        $this->writeFinalPositions($ordered);
    }

    /**
     * @param  list<array{id: int|null, key: string, name: string}>  $submitted
     * @param  Collection<int, RecipeInstructionSection>  $stored
     * @return array<string, int>
     */
    private function persistSections(Recipe $recipe, array $submitted, Collection $stored): array
    {
        $keptIds = collect($submitted)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $recipe->instructionSections()->whereNotIn('id', $keptIds)->delete();
        $storedById = $stored->keyBy('id');
        $temporaryBase = ((int) $stored->max('position')) + count($submitted) + 1;
        $ordered = [];
        $idsByKey = [];

        foreach ($submitted as $index => $state) {
            $section = $state['id'] === null ? new RecipeInstructionSection : $storedById->get($state['id']);
            assert($section instanceof RecipeInstructionSection);
            $section->name = trim($state['name']);
            $section->position = $temporaryBase + $index;
            $section->recipe()->associate($recipe);
            $section->save();
            $ordered[] = $section;
            $idsByKey[$state['key']] = $section->getKey();
        }

        $this->writeFinalPositions($ordered);

        return $idsByKey;
    }

    /**
     * @param  list<array{id: int|null, text: string, section_key: string|null}>  $submitted
     * @param  Collection<int, RecipeInstructionStep>  $stored
     * @param  array<string, int>  $sectionIdsByKey
     */
    private function persistSteps(Recipe $recipe, array $submitted, Collection $stored, array $sectionIdsByKey): void
    {
        $keptIds = collect($submitted)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $recipe->instructionSteps()->whereNotIn('id', $keptIds)->delete();
        $storedById = $stored->keyBy('id');
        $temporaryBase = ((int) $stored->max('position')) + count($submitted) + 1;
        $ordered = [];

        foreach ($submitted as $index => $state) {
            $step = $state['id'] === null ? new RecipeInstructionStep : $storedById->get($state['id']);
            assert($step instanceof RecipeInstructionStep);
            $step->text = $state['text'];
            $step->position = $temporaryBase + $index;
            $step->recipe()->associate($recipe);
            $step->section_id = $state['section_key'] === null ? null : $sectionIdsByKey[$state['section_key']];
            $step->save();
            $ordered[] = $step;
        }

        $this->writeFinalPositions($ordered);
    }

    /** @param list<RecipeIngredientLine|RecipeInstructionSection|RecipeInstructionStep> $models */
    private function writeFinalPositions(array $models): void
    {
        foreach ($models as $position => $model) {
            $model->position = $position;
            $model->save();
        }
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
