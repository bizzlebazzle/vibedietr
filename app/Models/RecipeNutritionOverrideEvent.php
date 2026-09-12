<?php

namespace App\Models;

use App\Domain\Nutrition\RecipeNutritionSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $recipe_version_id
 * @property int|null $actor_user_id
 * @property string $event
 * @property RecipeNutritionSource $prior_source
 * @property RecipeNutritionSource $resulting_source
 * @property array<string, mixed> $prior_values
 * @property array<string, mixed>|null $resulting_values
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property string $audit_event_id
 */
class RecipeNutritionOverrideEvent extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recipe nutrition override history is immutable.'));
        static::deleting(fn () => throw new LogicException('Recipe nutrition override history cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'prior_source' => RecipeNutritionSource::class,
            'resulting_source' => RecipeNutritionSource::class,
            'prior_values' => 'array',
            'resulting_values' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
