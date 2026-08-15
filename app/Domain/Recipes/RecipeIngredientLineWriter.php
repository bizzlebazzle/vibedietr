<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecipeIngredientLineWriter
{
    /** @param array<string, mixed> $attributes */
    public function append(Recipe $recipe, array $attributes): RecipeIngredientLine
    {
        return DB::transaction(function () use ($recipe, $attributes): RecipeIngredientLine {
            $lockedRecipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->getKey());
            $lastPosition = $lockedRecipe->ingredientLines()->max('position');
            $position = $lastPosition === null ? 0 : ((int) $lastPosition) + 1;

            $line = new RecipeIngredientLine($attributes);
            $line->position = $position;
            $line->recipe()->associate($lockedRecipe);
            $line->save();

            return $line;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Recipe $recipe, int $lineId, array $attributes): RecipeIngredientLine
    {
        return DB::transaction(function () use ($recipe, $lineId, $attributes): RecipeIngredientLine {
            $lockedRecipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->getKey());
            $line = $lockedRecipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $line->update($attributes);

            return $line;
        });
    }

    public function delete(Recipe $recipe, int $lineId): void
    {
        DB::transaction(function () use ($recipe, $lineId): void {
            $lockedRecipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->getKey());
            $lines = $lockedRecipe->ingredientLines()->lockForUpdate()->get();
            $line = $lines->firstWhere('id', $lineId);

            if (! $line instanceof RecipeIngredientLine) {
                abort(404);
            }

            $line->delete();
            $this->persistOrder($lines->reject->is($line)->values()->all());
        });
    }

    /** @param list<int> $lineIds */
    public function reorder(Recipe $recipe, array $lineIds): void
    {
        DB::transaction(function () use ($recipe, $lineIds): void {
            $lockedRecipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->getKey());
            $lines = $lockedRecipe->ingredientLines()->lockForUpdate()->get()->keyBy('id');

            if (count($lineIds) !== count(array_unique($lineIds, SORT_REGULAR))) {
                throw ValidationException::withMessages([
                    'lineIds' => 'Each ingredient line may appear only once.',
                ]);
            }

            $expectedIds = $lines->keys()->map(static fn ($id): int => (int) $id)->sort()->values()->all();
            $submittedIds = collect($lineIds)->sort()->values()->all();

            if ($submittedIds !== $expectedIds) {
                throw ValidationException::withMessages([
                    'lineIds' => 'The order must contain every ingredient line from this recipe and no others.',
                ]);
            }

            $orderedLines = collect($lineIds)->map(
                static function (int $lineId) use ($lines): RecipeIngredientLine {
                    $line = $lines->get($lineId);
                    assert($line instanceof RecipeIngredientLine);

                    return $line;
                }
            )->all();

            $this->persistOrder($orderedLines);
        });
    }

    /** @param list<RecipeIngredientLine> $lines */
    private function persistOrder(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $temporaryBase = ((int) collect($lines)->max('position')) + count($lines) + 1;

        foreach ($lines as $index => $line) {
            $line->position = $temporaryBase + $index;
            $line->save();
        }

        foreach ($lines as $index => $line) {
            $line->position = $index;
            $line->save();
        }
    }
}
