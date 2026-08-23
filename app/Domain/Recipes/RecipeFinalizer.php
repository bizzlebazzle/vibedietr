<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Profiles\PublicAttributionSnapshot;
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
        private readonly RecipeVersionContent $content,
        private readonly AuditEventRecorder $audit,
        private readonly RecipeFinalizationHook $hook,
        private readonly PublicAttributionSnapshot $attribution,
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
            $this->content->validateForPublication($recipe);

            $finalizedAt = now()->utc();
            $version = new RecipeVersion;
            $version->forceFill([
                'version_number' => 1,
                'visibility' => $recipe->visibility,
                'snapshot' => $this->content->snapshot($recipe),
                'public_attribution_name' => $this->attribution->forUser($actor),
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
}
