<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Support\Collection;

final class RecipeDraftFingerprint
{
    /**
     * @param  Collection<int, RecipeIngredientLine>|null  $ingredients
     * @param  Collection<int, RecipeInstructionSection>|null  $sections
     * @param  Collection<int, RecipeInstructionStep>|null  $steps
     */
    public function forRecipe(
        Recipe $recipe,
        ?Collection $ingredients = null,
        ?Collection $sections = null,
        ?Collection $steps = null,
    ): string {
        $ingredients ??= $recipe->ingredientLines()->get();
        $sections ??= $recipe->instructionSections()->get();
        $steps ??= $recipe->instructionSteps()->get();

        $state = [
            'recipe' => [
                'id' => $recipe->getKey(),
                'user_id' => $recipe->user_id,
                'title' => $recipe->title,
                'servings' => $recipe->servings,
                'visibility' => $recipe->getRawOriginal('visibility'),
                'lifecycle' => $recipe->getRawOriginal('lifecycle'),
                'current_recipe_version_id' => $recipe->current_recipe_version_id,
                'active_revision' => $recipe->activeRevision()->first()?->only(['id', 'base_recipe_version_id']),
                'updated_at' => $recipe->getRawOriginal('updated_at'),
            ],
            'ingredients' => $ingredients->map(fn ($line): array => [
                'id' => $line->getKey(),
                'original_text' => $line->original_text,
                'position' => $line->position,
                'quantity' => $line->quantity,
                'standard_unit' => $line->getRawOriginal('standard_unit'),
                'custom_unit' => $line->custom_unit,
                'generic_wording' => $line->generic_wording,
                'notes' => $line->notes,
                'updated_at' => $line->getRawOriginal('updated_at'),
            ])->values()->all(),
            'sections' => $sections->map(fn ($section): array => [
                'id' => $section->getKey(),
                'name' => $section->name,
                'position' => $section->position,
                'updated_at' => $section->getRawOriginal('updated_at'),
            ])->values()->all(),
            'steps' => $steps->map(fn ($step): array => [
                'id' => $step->getKey(),
                'section_id' => $step->section_id,
                'text' => $step->text,
                'position' => $step->position,
                'updated_at' => $step->getRawOriginal('updated_at'),
            ])->values()->all(),
        ];

        return hash('sha256', serialize($state));
    }
}
