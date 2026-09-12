<?php

namespace App\Domain\Nutrition;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\Recipe;
use App\Models\RecipeNutritionOverrideEvent;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RecipeNutritionOverrideManager
{
    public function __construct(
        private RecipeNutritionValueNormalizer $normalizer,
        private RecipeNutritionSourceSelector $selector,
        private AuditEventRecorder $audit,
    ) {}

    /** @param array<string, string|int|null> $values */
    public function save(int $recipeId, string $versionId, array $values, ?string $note, User $actor): RecipeNutritionOverrideEvent
    {
        $normalized = $this->normalizer->normalize($values, 'creator_override');

        return DB::transaction(function () use ($recipeId, $versionId, $normalized, $note, $actor): RecipeNutritionOverrideEvent {
            [$recipe, $version] = $this->lockCurrentVersion($recipeId, $versionId, $actor);
            $current = $this->selector->currentOverride($version);
            $prior = $this->selector->effective($version);
            $eventId = (string) Str::ulid();
            $changed = $this->changedNutrients($prior['values'], $normalized);
            if ($current?->resulting_values !== null && $changed === []) {
                throw ValidationException::withMessages(['nutrients' => 'Change at least one nutrition value.']);
            }
            $audit = $this->audit->record(
                AuditAction::RecipeNutritionOverrideApplied,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::NutritionOverride, 'override:'.$eventId),
                ['changed_nutrients' => $changed, 'outcome' => 'applied'],
                'recipe-nutrition:'.Str::ulid(),
            );

            $event = new RecipeNutritionOverrideEvent;
            $event->forceFill([
                'id' => $eventId,
                'actor_user_id' => $actor->getKey(),
                'event' => $current?->resulting_values === null ? 'added' : 'changed',
                'prior_source' => $prior['source'],
                'resulting_source' => RecipeNutritionSource::CreatorOverride,
                'prior_values' => $prior['values'],
                'resulting_values' => $normalized,
                'note' => $this->note($note),
                'occurred_at' => now()->utc(),
                'audit_event_id' => $audit->getKey(),
            ]);
            $event->recipeVersion()->associate($version);
            $event->save();

            return $event;
        }, 3);
    }

    public function remove(int $recipeId, string $versionId, ?string $note, User $actor): RecipeNutritionOverrideEvent
    {
        return DB::transaction(function () use ($recipeId, $versionId, $note, $actor): RecipeNutritionOverrideEvent {
            [$recipe, $version] = $this->lockCurrentVersion($recipeId, $versionId, $actor);
            $prior = $this->selector->effective($version);
            if ($prior['source'] !== RecipeNutritionSource::CreatorOverride) {
                throw ValidationException::withMessages(['override' => 'This recipe version has no creator override to remove.']);
            }
            $eventId = (string) Str::ulid();
            $resultingSource = $this->selector->imported($version) === null
                ? RecipeNutritionSource::IngredientEstimate
                : RecipeNutritionSource::ImportedSource;
            $audit = $this->audit->record(
                AuditAction::RecipeNutritionOverrideApplied,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::NutritionOverride, 'override:'.$eventId),
                ['changed_nutrients' => array_keys($prior['values']), 'outcome' => 'applied'],
                'recipe-nutrition:'.Str::ulid(),
            );

            $event = new RecipeNutritionOverrideEvent;
            $event->forceFill([
                'id' => $eventId,
                'actor_user_id' => $actor->getKey(),
                'event' => 'removed',
                'prior_source' => RecipeNutritionSource::CreatorOverride,
                'resulting_source' => $resultingSource,
                'prior_values' => $prior['values'],
                'resulting_values' => null,
                'note' => $this->note($note),
                'occurred_at' => now()->utc(),
                'audit_event_id' => $audit->getKey(),
            ]);
            $event->recipeVersion()->associate($version);
            $event->save();

            return $event;
        }, 3);
    }

    /** @return array{Recipe, RecipeVersion} */
    private function lockCurrentVersion(int $recipeId, string $versionId, User $actor): array
    {
        $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
        Gate::forUser($actor)->authorize('overrideNutrition', $recipe);
        $version = $recipe->currentVersion()->lockForUpdate()->first();
        if (! $version instanceof RecipeVersion || $version->getKey() !== $versionId) {
            throw ValidationException::withMessages(['source_version_id' => 'The recipe version changed. Reload before changing nutrition.']);
        }

        return [$recipe, $version];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function changedNutrients(array $before, array $after): array
    {
        return collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->values()->all();
    }

    private function note(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
