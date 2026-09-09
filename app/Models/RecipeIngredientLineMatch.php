<?php

namespace App\Models;

use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Domain\Recipes\RecipeIngredientMatchReviewState;
use Database\Factories\RecipeIngredientLineMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RecipeIngredientLineMatch extends Model
{
    /** @use HasFactory<RecipeIngredientLineMatchFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (RecipeIngredientLineMatch $match): void {
            $match->change_marker = strtolower((string) Str::ulid());
        });
    }

    protected function casts(): array
    {
        return [
            'provenance' => RecipeIngredientMatchProvenance::class,
            'review_state' => RecipeIngredientMatchReviewState::class,
        ];
    }

    /** @return BelongsTo<RecipeIngredientLine, $this> */
    public function ingredientLine(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredientLine::class, 'recipe_ingredient_line_id');
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function catalogueItemVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_user_id');
    }
}
