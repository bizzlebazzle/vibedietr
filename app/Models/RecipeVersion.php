<?php

namespace App\Models;

use App\Domain\Recipes\RecipeVisibility;
use Carbon\CarbonImmutable;
use Database\Factories\RecipeVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property int $recipe_id
 * @property int $version_number
 * @property RecipeVisibility $visibility
 * @property array<string, mixed> $snapshot
 * @property string|null $public_attribution_name
 * @property CarbonImmutable $finalized_at
 */
class RecipeVersion extends Model
{
    /** @use HasFactory<RecipeVersionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finalized recipe versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Finalized recipe versions cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'visibility' => RecipeVisibility::class,
            'snapshot' => 'array',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
