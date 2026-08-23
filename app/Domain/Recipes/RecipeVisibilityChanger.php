<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipeVisibilityChanger
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function change(int $recipeId, RecipeVisibility $visibility, User $actor): Recipe
    {
        return DB::transaction(function () use ($recipeId, $visibility, $actor): Recipe {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('changeVisibility', $recipe);

            if (! $recipe->isFinalized() || $recipe->current_recipe_version_id === null) {
                throw ValidationException::withMessages([
                    'visibility' => 'Only a finalized recipe can change public visibility.',
                ]);
            }

            $previous = RecipeVisibility::from((string) $recipe->getRawOriginal('visibility'));

            if ($previous === $visibility) {
                return $recipe;
            }

            $recipe->forceFill(['visibility' => $visibility])->save();

            $this->audit->record(
                AuditAction::RecipeVisibilityChanged,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->getKey()),
                [
                    'event' => 'visibility_changed',
                    'outcome' => 'completed',
                    'previous_visibility' => $previous->value,
                    'resulting_visibility' => $visibility->value,
                    'version_id' => $recipe->current_recipe_version_id,
                ],
                'recipe-visibility:'.Str::ulid(),
            );

            return $recipe;
        }, 3);
    }
}
