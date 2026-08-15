<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipeFinalizer
{
    public function __construct(
        private readonly RecipeDraftEditor $editor,
        private readonly AuditEventRecorder $audit,
        private readonly RecipeFinalizationHook $hook,
    ) {}

    /**
     * @param  array{title: string, servings: mixed, visibility: string}  $metadata
     * @param  list<array{id: int|null, original_text: string, quantity: mixed, unit: string, generic_wording: string, notes: string}>  $ingredients
     * @param  list<array{id: int|null, key: string, name: string}>  $sections
     * @param  list<array{id: int|null, text: string, section_key: string|null}>  $steps
     */
    public function finalize(
        int $recipeId,
        string $baselineFingerprint,
        array $metadata,
        array $ingredients,
        array $sections,
        array $steps,
        User $actor,
    ): RecipeVersion {
        return DB::transaction(function () use ($recipeId, $baselineFingerprint, $metadata, $ingredients, $sections, $steps, $actor): RecipeVersion {
            $authoritative = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('finalize', $authoritative);

            if ($authoritative->getRawOriginal('lifecycle') === RecipeLifecycle::Finalized->value) {
                $version = $authoritative->currentVersion()->first();

                if ($version instanceof RecipeVersion) {
                    return $version;
                }

                throw ValidationException::withMessages([
                    'finalize' => 'This recipe is finalized but its current stable version is unavailable.',
                ]);
            }

            $recipe = $this->editor->save(
                $recipeId,
                $baselineFingerprint,
                $metadata,
                $ingredients,
                $sections,
                $steps,
            )->fresh();
            $recipe->load(['ingredientLines', 'instructionSections', 'instructionSteps']);
            $this->validatePreconditions($recipe);

            $finalizedAt = now()->utc();
            $version = new RecipeVersion;
            $version->forceFill([
                'version_number' => 1,
                'visibility' => $recipe->visibility,
                'snapshot' => $this->snapshot($recipe),
                'finalized_at' => $finalizedAt,
            ]);
            $version->recipe()->associate($recipe);
            $version->save();

            $recipe->forceFill([
                'lifecycle' => RecipeLifecycle::Finalized,
                'current_recipe_version_id' => $version->getKey(),
                'finalized_at' => $finalizedAt,
            ])->save();

            $this->audit->record(
                AuditAction::RecipeFinalized,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->getKey()),
                [
                    'event' => 'finalized',
                    'outcome' => 'completed',
                    'version_id' => $version->getKey(),
                    'visibility' => $recipe->getRawOriginal('visibility'),
                ],
                'recipe-finalize:'.Str::ulid(),
            );

            $this->hook->beforeCommit($recipe, $version);

            return $version;
        }, 3);
    }

    private function validatePreconditions(Recipe $recipe): void
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
    private function snapshot(Recipe $recipe): array
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
}
