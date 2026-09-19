<?php

namespace App\Domain\Diary;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueNutrientReadModel;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\EnergyNormalizer;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\DiaryEntry;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DiaryEntryWriter
{
    public function __construct(private readonly RecipeNutritionSourceSelector $nutrition, private readonly EnergyNormalizer $energy, private readonly DiaryConsumptionManager $consumption) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): DiaryEntry
    {
        return DB::transaction(function () use ($data, $actor) {
            $kind = DiaryEntryKind::from($data['kind']);
            $snapshot = match ($kind) {
                DiaryEntryKind::Recipe => $this->recipeSnapshot((int) $data['recipe_id'], $actor),
                DiaryEntryKind::Catalogue => $this->catalogueSnapshot((int) $data['catalogue_item_id']),
                DiaryEntryKind::OneOff => $this->oneOffSnapshot($data),
            };
            $entry = new DiaryEntry;
            $entry->forceFill(['user_id' => $actor->getKey(), 'kind' => $kind, ...$snapshot])->save();
            $unit = $kind === DiaryEntryKind::Recipe ? 'serving' : StandardUnit::from($data['actual_unit'])->value;
            $this->consumption->consumeDiary($entry, (string) $data['actual_amount'], $unit, $data['consumed_local_at'] ?? null, $data['timezone'] ?? null, isset($data['utc_offset_minutes']) ? (int) $data['utc_offset_minutes'] : null, $data['effective_diary_date'] ?? null, $actor);

            return $entry;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function recipeSnapshot(int $recipeId, User $actor): array
    {
        $recipe = Recipe::query()->visibleTo($actor)->lockForUpdate()->findOrFail($recipeId);
        if (! $recipe->canBeUsedInPlansFor($actor)) {
            throw ValidationException::withMessages(['recipe_id' => 'Only a finalized recipe can be recorded.']);
        }
        $version = RecipeVersion::query()->where('recipe_id', $recipe->getKey())->find($recipe->current_recipe_version_id);
        if (! $version) {
            throw ValidationException::withMessages(['recipe_id' => 'The current finalized recipe version is unavailable.']);
        }
        $nutrition = $this->nutrition->effective($version);

        return ['source_id' => $recipe->getKey(), 'source_version_id' => $version->getKey(), 'source_version_number' => $version->version_number, 'source_snapshot' => ['recipe' => $version->snapshot, 'nutrition' => ['source' => $nutrition['source']->value, 'values' => $nutrition['values'], 'provenance' => $nutrition['provenance'], 'ingredient_estimate' => $this->nutrition->estimate($version)]]];
    }

    /** @return array<string, mixed> */
    private function catalogueSnapshot(int $itemId): array
    {
        $item = CatalogueItem::query()->where('status', CatalogueItemStatus::Approved)->lockForUpdate()->find($itemId);
        $version = $item?->current_catalogue_item_version_id ? CatalogueItemVersion::query()->with('nutrientValues.sourceObservation')->whereKey($item->current_catalogue_item_version_id)->where('catalogue_item_id', $item->getKey())->first() : null;
        if (! $version) {
            throw ValidationException::withMessages(['catalogue_item_id' => 'Only an approved catalogue item with a current version can be recorded.']);
        }

        return ['source_id' => $item->getKey(), 'source_version_id' => $version->getKey(), 'source_version_number' => $version->version_number, 'source_snapshot' => ['name' => $version->name, 'brand' => $version->brand, 'nutrition' => $version->nutrientValues->map(fn ($value) => CatalogueNutrientReadModel::fromValue($value)->toArray())->values()->all()]];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function oneOffSnapshot(array $data): array
    {
        $wording = trim((string) ($data['one_off_wording'] ?? ''));
        if ($wording === '') {
            throw ValidationException::withMessages(['one_off_wording' => 'Enter the one-off item wording.']);
        }
        $entered = array_filter($data['one_off_nutrition'] ?? [], fn ($v) => $v !== null && trim((string) $v) !== '');
        $normalized = $entered === [] ? [] : $this->energy->normalize(array_map('strval', $entered));

        return ['source_id' => null, 'source_version_id' => null, 'source_version_number' => null, 'source_snapshot' => ['wording' => $wording, 'nutrition_basis' => $data['one_off_nutrition_basis'] ?? null, 'entered_nutrition' => $entered, 'normalized_nutrition' => array_map('strval', $normalized)]];
    }
}
