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

final class RecipeRevisionManager
{
    public function __construct(
        private readonly RecipeVersionContent $content,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function startOrResume(int $recipeId, User $actor): RecipeDraftRevision
    {
        return DB::transaction(function () use ($recipeId, $actor): RecipeDraftRevision {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('startRevision', $recipe);

            $existing = $recipe->activeRevision()->lockForUpdate()->first();
            if ($existing instanceof RecipeDraftRevision) {
                return $existing;
            }

            $version = $recipe->currentVersion()->first();
            if (! $version instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    'revision' => 'The current finalized version is unavailable.',
                ]);
            }

            $revision = new RecipeDraftRevision;
            $revision->forceFill(['base_recipe_version_id' => $version->getKey()]);
            $revision->recipe()->associate($recipe);
            $revision->save();
            $this->content->restore($recipe, $version->snapshot);

            $this->audit->record(
                AuditAction::RecipeRevisionCreated,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->getKey()),
                [
                    'event' => 'revision_created',
                    'outcome' => 'completed',
                    'revision_id' => $revision->getKey(),
                    'base_version_id' => $version->getKey(),
                    'base_version_number' => $version->version_number,
                ],
                'recipe-revision-create:'.Str::ulid(),
            );

            return $revision;
        }, 3);
    }

    public function abandon(int $recipeId, User $actor): Recipe
    {
        return DB::transaction(function () use ($recipeId, $actor): Recipe {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('abandonRevision', $recipe);
            $revision = $recipe->activeRevision()->lockForUpdate()->first();

            if (! $revision instanceof RecipeDraftRevision) {
                return $recipe;
            }

            $version = $recipe->currentVersion()->first();
            if (! $version instanceof RecipeVersion) {
                throw ValidationException::withMessages([
                    'revision' => 'The current finalized version is unavailable.',
                ]);
            }

            $baseVersion = $revision->baseVersion()->firstOrFail();
            $this->content->restore($recipe, $version->snapshot);
            $revisionId = (string) $revision->getKey();
            $revision->delete();

            $this->audit->record(
                AuditAction::RecipeRevisionAbandoned,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->getKey()),
                [
                    'event' => 'revision_abandoned',
                    'outcome' => 'completed',
                    'revision_id' => $revisionId,
                    'base_version_id' => $baseVersion->getKey(),
                    'base_version_number' => $baseVersion->version_number,
                ],
                'recipe-revision-abandon:'.Str::ulid(),
            );

            return $recipe;
        }, 3);
    }
}
