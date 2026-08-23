<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipeRemixCreator
{
    public function __construct(
        private readonly AuditEventRecorder $audit,
        private readonly RecipeRemixCreationHook $hook,
    ) {}

    public function create(
        int $sourceRecipeId,
        string $sourceVersionId,
        string $operationId,
        User $actor,
    ): Recipe {
        if (! Str::isUlid($sourceVersionId) || ! Str::isUlid($operationId)) {
            throw ValidationException::withMessages(['remix' => 'The remix request is invalid.']);
        }

        return DB::transaction(function () use ($sourceRecipeId, $sourceVersionId, $operationId, $actor): Recipe {
            $existing = RecipeRemixLineage::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof RecipeRemixLineage) {
                $remix = $existing->remixRecipe()->firstOrFail();

                if ($remix->user_id !== $actor->getKey()) {
                    throw ValidationException::withMessages(['remix' => 'The remix request is invalid.']);
                }

                return $remix;
            }

            $source = Recipe::query()
                ->visibleTo($actor)
                ->whereKey($sourceRecipeId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = RecipeRemixLineage::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof RecipeRemixLineage) {
                $remix = $existing->remixRecipe()->firstOrFail();

                if ($remix->user_id !== $actor->getKey()) {
                    throw ValidationException::withMessages(['remix' => 'The remix request is invalid.']);
                }

                return $remix;
            }

            Gate::forUser($actor)->authorize('remix', $source);

            if (! $source->isFinalized() || $source->current_recipe_version_id !== $sourceVersionId) {
                throw ValidationException::withMessages([
                    'source_version_id' => 'The source recipe changed. Reload it before creating a remix.',
                ]);
            }

            $sourceVersion = RecipeVersion::query()
                ->whereKey($sourceVersionId)
                ->where('recipe_id', $source->getKey())
                ->firstOrFail();
            $snapshot = $sourceVersion->snapshot;

            $remix = new Recipe;
            $remix->forceFill([
                'user_id' => $actor->getKey(),
                'title' => (string) ($snapshot['title'] ?? ''),
                'servings' => $snapshot['servings'] ?? null,
                'lifecycle' => RecipeLifecycle::Draft,
                'visibility' => RecipeVisibility::Private,
                'current_recipe_version_id' => null,
                'finalized_at' => null,
            ]);
            $remix->save();

            $this->copyIngredients($remix, $snapshot);
            $this->hook->afterIngredientCopy($remix);
            $this->copyInstructions($remix, $snapshot);
            $this->hook->afterInstructionCopy($remix);

            $lineage = new RecipeRemixLineage;
            $lineage->forceFill([
                'remix_recipe_id' => $remix->getKey(),
                'source_recipe_id' => $source->getKey(),
                'source_recipe_version_id' => $sourceVersion->getKey(),
                'source_version_number' => $sourceVersion->version_number,
                'source_creator_user_id' => $source->user_id,
                'operation_id' => $operationId,
            ]);
            $lineage->save();
            $this->hook->afterLineageCreation($remix, $lineage);

            $this->audit->record(
                AuditAction::RecipeRemixed,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$remix->getKey()),
                [
                    'event' => 'remixed',
                    'outcome' => 'completed',
                    'source_version_id' => $sourceVersion->getKey(),
                ],
                $operationId,
            );

            return $remix->fresh([
                'ingredientLines',
                'instructionSections',
                'instructionSteps',
                'remixLineage',
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $snapshot */
    private function copyIngredients(Recipe $remix, array $snapshot): void
    {
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
            $line->recipe()->associate($remix);
            $line->save();
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function copyInstructions(Recipe $remix, array $snapshot): void
    {
        $sectionIds = [];

        foreach (collect($snapshot['sections'] ?? [])->sortBy('position')->values() as $position => $state) {
            $section = new RecipeInstructionSection;
            $section->forceFill([
                'name' => (string) ($state['name'] ?? ''),
                'position' => $position,
            ]);
            $section->recipe()->associate($remix);
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
            $step->recipe()->associate($remix);
            $step->save();
        }
    }
}
