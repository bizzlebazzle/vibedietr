<?php

namespace App\Models;

use App\Domain\Nutrition\RecipeNutritionRecalculationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $recipe_version_id
 * @property string $approved_catalogue_item_version_id
 * @property string $correlation_id
 * @property RecipeNutritionRecalculationState $state
 * @property array<string, mixed>|null $estimate
 * @property string|null $audit_event_id
 * @property string|null $failure_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
class RecipeNutritionRecalculation extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Recipe nutrition recalculation history cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'state' => RecipeNutritionRecalculationState::class,
            'estimate' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function approvedCatalogueItemVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class, 'approved_catalogue_item_version_id');
    }
}
