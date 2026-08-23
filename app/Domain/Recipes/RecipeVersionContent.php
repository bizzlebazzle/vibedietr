<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Validation\ValidationException;

final class RecipeVersionContent
{
    public function validateForPublication(Recipe $recipe): void
    {
        $errors = [];

        if (trim($recipe->title) === '') {
            $errors['title'] = 'A title is required before finalizing.';
        }
        if ($recipe->servings === null) {
            $errors['servings'] = 'Suggested servings are required before finalizing.';
        } elseif ((float) $recipe->servings <= 0) {
            $errors['servings'] = 'Suggested servings must be greater than zero before finalizing.';
        }
        if ($recipe->ingredientLines->isEmpty()) {
            $errors['ingredients'] = 'Add at least one ingredient line before finalizing.';
        }
        if ($recipe->instructionSteps->filter(fn ($step): bool => trim($step->text) !== '')->isEmpty()) {
            $errors['steps'] = 'Add at least one non-blank instruction step before finalizing.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(Recipe $recipe): array
    {
        return [
            'title' => $recipe->title,
            'servings' => $recipe->servings,
            'visibility' => $recipe->getRawOriginal('visibility'),
            'ingredients' => $recipe->ingredientLines->map(fn ($line): array => [
                'position' => $line->position,
                'original_text' => $line->original_text,
                'quantity' => $line->quantity,
                'standard_unit' => $line->getRawOriginal('standard_unit'),
                'custom_unit' => $line->custom_unit,
                'generic_wording' => $line->generic_wording,
                'notes' => $line->notes,
            ])->values()->all(),
            'sections' => $recipe->instructionSections->map(fn ($section): array => [
                'key' => 'section-'.$section->getKey(),
                'position' => $section->position,
                'name' => $section->name,
            ])->values()->all(),
            'steps' => $recipe->instructionSteps->map(fn ($step): array => [
                'position' => $step->position,
                'text' => $step->text,
                'section_key' => $step->section_id === null ? null : 'section-'.$step->section_id,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(Recipe $recipe, array $snapshot): void
    {
        $recipe->instructionSteps()->delete();
        $recipe->instructionSections()->delete();
        $recipe->ingredientLines()->delete();

        $recipe->forceFill([
            'title' => (string) ($snapshot['title'] ?? ''),
            'servings' => $snapshot['servings'] ?? null,
        ])->save();

        foreach (collect($snapshot['ingredients'] ?? [])->sortBy('position')->values() as $position => $state) {
            $line = new RecipeIngredientLine;
            $line->forceFill([
                'original_text' => (string) ($state['original_text'] ?? ''),
                'position' => $position,
                'quantity' => $state['quantity'] ?? null,
                'standard_unit' => $state['standard_unit'] ?? null,
                'custom_unit' => $state['custom_unit'] ?? null,
                'generic_wording' => $state['generic_wording'] ?? null,
                'notes' => $state['notes'] ?? null,
            ]);
            $line->recipe()->associate($recipe);
            $line->save();
        }

        $sectionIds = [];
        foreach (collect($snapshot['sections'] ?? [])->sortBy('position')->values() as $position => $state) {
            $section = new RecipeInstructionSection;
            $section->forceFill([
                'name' => (string) ($state['name'] ?? ''),
                'position' => $position,
            ]);
            $section->recipe()->associate($recipe);
            $section->save();
            $sectionIds[(string) ($state['key'] ?? '')] = $section->getKey();
        }

        foreach (collect($snapshot['steps'] ?? [])->sortBy('position')->values() as $position => $state) {
            $sectionKey = $state['section_key'] ?? null;
            $step = new RecipeInstructionStep;
            $step->forceFill([
                'text' => (string) ($state['text'] ?? ''),
                'position' => $position,
                'section_id' => $sectionKey === null ? null : ($sectionIds[(string) $sectionKey] ?? null),
            ]);
            $step->recipe()->associate($recipe);
            $step->save();
        }

        $recipe->unsetRelation('ingredientLines');
        $recipe->unsetRelation('instructionSections');
        $recipe->unsetRelation('instructionSteps');
    }
}
