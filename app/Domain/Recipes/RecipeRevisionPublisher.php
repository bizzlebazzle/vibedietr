<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\Recipe;
use App\Models\RecipeDraftRevision;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipeRevisionPublisher
{
    public function __construct(
        private readonly RecipeDraftEditor $editor,
        private readonly RecipeVersionContent $content,
        private readonly AuditEventRecorder $audit,
        private readonly RecipeFinalizationHook $hook,
    ) {}

    /**
     * @param  array{title: string, servings: mixed, visibility: string}  $metadata
     * @param  list<array{id: int|null, original_text: string, quantity: mixed, unit: string, generic_wording: string, notes: string}>  $ingredients
     * @param  list<array{id: int|null, key: string, name: string}>  $sections
     * @param  list<array{id: int|null, text: string, section_key: string|null}>  $steps
     */
    public function publish(
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
            Gate::forUser($actor)->authorize('publishRevision', $authoritative);
            $revision = $authoritative->activeRevision()->lockForUpdate()->first();

            if (! $revision instanceof RecipeDraftRevision) {
                $current = $authoritative->currentVersion()->first();
                if ($current instanceof RecipeVersion) {
                    return $current;
                }

                throw ValidationException::withMessages(['revision' => 'There is no active draft revision to publish.']);
            }

            $current = $authoritative->currentVersion()->lockForUpdate()->first();
            if (! $current instanceof RecipeVersion
                || $revision->base_recipe_version_id !== $current->getKey()) {
                throw new StaleRecipeRevision;
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
            $this->content->validateForPublication($recipe);

            $finalizedAt = now()->utc();
            $version = new RecipeVersion;
            $version->forceFill([
                'version_number' => $current->version_number + 1,
                'visibility' => $recipe->visibility,
                'snapshot' => $this->content->snapshot($recipe),
                'finalized_at' => $finalizedAt,
            ]);
            $version->recipe()->associate($recipe);
            $version->save();

            $recipe->forceFill([
                'current_recipe_version_id' => $version->getKey(),
                'finalized_at' => $finalizedAt,
            ])->save();

            $revisionId = (string) $revision->getKey();
            $revision->delete();

            $this->audit->record(
                AuditAction::RecipeRevisionPublished,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->getKey()),
                [
                    'event' => 'revision_published',
                    'outcome' => 'completed',
                    'revision_id' => $revisionId,
                    'base_version_id' => $current->getKey(),
                    'base_version_number' => $current->version_number,
                    'new_version_id' => $version->getKey(),
                    'new_version_number' => $version->version_number,
                ],
                'recipe-revision-publish:'.Str::ulid(),
            );

            $this->hook->beforeCommit($recipe, $version);

            return $version;
        }, 3);
    }
}
