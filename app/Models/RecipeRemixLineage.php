<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecipeRemixLineageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property int $remix_recipe_id
 * @property int $source_recipe_id
 * @property string $source_recipe_version_id
 * @property int $source_version_number
 * @property int|null $source_creator_user_id
 * @property string $operation_id
 * @property CarbonImmutable $created_at
 */
class RecipeRemixLineage extends Model
{
    /** @use HasFactory<RecipeRemixLineageFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recipe remix lineage is immutable.'));
        static::deleting(fn () => throw new LogicException('Recipe remix lineage cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'source_recipe_id' => 'integer',
            'source_version_number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function remixRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'remix_recipe_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sourceCreator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_creator_user_id');
    }
}
