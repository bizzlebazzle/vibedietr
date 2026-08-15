<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RecipeInstructionWriter
{
    public function appendStep(Recipe $recipe, string $text, ?int $sectionId): RecipeInstructionStep
    {
        Gate::authorize('update', $recipe);

        return DB::transaction(function () use ($recipe, $text, $sectionId): RecipeInstructionStep {
            $lockedRecipe = $this->lockRecipe($recipe);
            $section = $this->resolveSection($lockedRecipe, $sectionId);
            $lastPosition = $lockedRecipe->instructionSteps()->max('position');
            $step = new RecipeInstructionStep(['text' => $text]);
            $step->position = $lastPosition === null ? 0 : ((int) $lastPosition) + 1;
            $step->recipe()->associate($lockedRecipe);
            $step->section()->associate($section);
            $step->save();

            return $step;
        });
    }

    public function updateStep(Recipe $recipe, int $stepId, string $text, ?int $sectionId): RecipeInstructionStep
    {
        Gate::authorize('update', $recipe);

        return DB::transaction(function () use ($recipe, $stepId, $text, $sectionId): RecipeInstructionStep {
            $lockedRecipe = $this->lockRecipe($recipe);
            $step = $lockedRecipe->instructionSteps()->lockForUpdate()->findOrFail($stepId);
            $section = $this->resolveSection($lockedRecipe, $sectionId);
            $step->text = $text;
            $step->section()->associate($section);
            $step->save();

            return $step;
        });
    }

    public function deleteStep(Recipe $recipe, int $stepId): void
    {
        Gate::authorize('update', $recipe);

        DB::transaction(function () use ($recipe, $stepId): void {
            $lockedRecipe = $this->lockRecipe($recipe);
            $steps = $lockedRecipe->instructionSteps()->lockForUpdate()->get();
            $step = $steps->firstWhere('id', $stepId);

            if (! $step instanceof RecipeInstructionStep) {
                abort(404);
            }

            $step->delete();
            $this->persistStepOrder($steps->reject->is($step)->values()->all());
        });
    }

    /** @param list<int> $stepIds */
    public function reorderSteps(Recipe $recipe, array $stepIds): void
    {
        Gate::authorize('update', $recipe);

        DB::transaction(function () use ($recipe, $stepIds): void {
            $lockedRecipe = $this->lockRecipe($recipe);
            $steps = $lockedRecipe->instructionSteps()->lockForUpdate()->get()->keyBy('id');
            $this->validateCompleteOrder(
                $stepIds,
                $steps->keys()->map(static fn ($id): int => (int) $id)->all(),
                'stepIds',
                'instruction step'
            );
            $orderedSteps = collect($stepIds)->map(
                static function (int $stepId) use ($steps): RecipeInstructionStep {
                    $step = $steps->get($stepId);
                    assert($step instanceof RecipeInstructionStep);

                    return $step;
                }
            )->all();
            $this->persistStepOrder($orderedSteps);
        });
    }

    public function appendSection(Recipe $recipe, string $name): RecipeInstructionSection
    {
        Gate::authorize('update', $recipe);

        return DB::transaction(function () use ($recipe, $name): RecipeInstructionSection {
            $lockedRecipe = $this->lockRecipe($recipe);
            $lastPosition = $lockedRecipe->instructionSections()->max('position');
            $section = new RecipeInstructionSection(['name' => $name]);
            $section->position = $lastPosition === null ? 0 : ((int) $lastPosition) + 1;
            $section->recipe()->associate($lockedRecipe);
            $section->save();

            return $section;
        });
    }

    public function updateSection(Recipe $recipe, int $sectionId, string $name): RecipeInstructionSection
    {
        Gate::authorize('update', $recipe);

        return DB::transaction(function () use ($recipe, $sectionId, $name): RecipeInstructionSection {
            $lockedRecipe = $this->lockRecipe($recipe);
            $section = $lockedRecipe->instructionSections()->lockForUpdate()->findOrFail($sectionId);
            $section->update(['name' => $name]);

            return $section;
        });
    }

    public function deleteSection(Recipe $recipe, int $sectionId): void
    {
        Gate::authorize('update', $recipe);

        DB::transaction(function () use ($recipe, $sectionId): void {
            $lockedRecipe = $this->lockRecipe($recipe);
            $sections = $lockedRecipe->instructionSections()->lockForUpdate()->get();
            $section = $sections->firstWhere('id', $sectionId);

            if (! $section instanceof RecipeInstructionSection) {
                abort(404);
            }

            $section->steps()->update(['section_id' => null]);
            $section->delete();
            $this->persistSectionOrder($sections->reject->is($section)->values()->all());
        });
    }

    /** @param list<int> $sectionIds */
    public function reorderSections(Recipe $recipe, array $sectionIds): void
    {
        Gate::authorize('update', $recipe);

        DB::transaction(function () use ($recipe, $sectionIds): void {
            $lockedRecipe = $this->lockRecipe($recipe);
            $sections = $lockedRecipe->instructionSections()->lockForUpdate()->get()->keyBy('id');
            $this->validateCompleteOrder(
                $sectionIds,
                $sections->keys()->map(static fn ($id): int => (int) $id)->all(),
                'sectionIds',
                'instruction section'
            );
            $orderedSections = collect($sectionIds)->map(
                static function (int $sectionId) use ($sections): RecipeInstructionSection {
                    $section = $sections->get($sectionId);
                    assert($section instanceof RecipeInstructionSection);

                    return $section;
                }
            )->all();
            $this->persistSectionOrder($orderedSections);
        });
    }

    private function lockRecipe(Recipe $recipe): Recipe
    {
        return Recipe::query()->lockForUpdate()->findOrFail($recipe->getKey());
    }

    private function resolveSection(Recipe $recipe, ?int $sectionId): ?RecipeInstructionSection
    {
        if ($sectionId === null) {
            return null;
        }

        $section = $recipe->instructionSections()->lockForUpdate()->find($sectionId);

        if (! $section instanceof RecipeInstructionSection) {
            throw ValidationException::withMessages([
                'sectionId' => 'The selected instruction section does not belong to this recipe.',
            ]);
        }

        return $section;
    }

    /**
     * @param  list<int>  $submittedIds
     * @param  list<int>  $expectedIds
     */
    private function validateCompleteOrder(array $submittedIds, array $expectedIds, string $key, string $label): void
    {
        if (count($submittedIds) !== count(array_unique($submittedIds, SORT_REGULAR))) {
            throw ValidationException::withMessages([$key => "Each {$label} may appear only once."]);
        }

        sort($submittedIds);
        sort($expectedIds);

        if ($submittedIds !== $expectedIds) {
            throw ValidationException::withMessages([
                $key => "The order must contain every {$label} from this recipe and no others.",
            ]);
        }
    }

    /** @param list<RecipeInstructionStep> $steps */
    private function persistStepOrder(array $steps): void
    {
        if ($steps === []) {
            return;
        }

        $temporaryBase = ((int) collect($steps)->max('position')) + count($steps) + 1;

        foreach ($steps as $index => $step) {
            $step->position = $temporaryBase + $index;
            $step->save();
        }

        foreach ($steps as $index => $step) {
            $step->position = $index;
            $step->save();
        }
    }

    /** @param list<RecipeInstructionSection> $sections */
    private function persistSectionOrder(array $sections): void
    {
        if ($sections === []) {
            return;
        }

        $temporaryBase = ((int) collect($sections)->max('position')) + count($sections) + 1;

        foreach ($sections as $index => $section) {
            $section->position = $temporaryBase + $index;
            $section->save();
        }

        foreach ($sections as $index => $section) {
            $section->position = $index;
            $section->save();
        }
    }
}
