<?php

namespace App\Models;

use App\Domain\Recipes\RecipeTagName;
use Database\Factories\PublicRecipeTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicRecipeTag extends Model
{
    /** @use HasFactory<PublicRecipeTagFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (PublicRecipeTag $tag): void {
            $tag->name = RecipeTagName::display($tag->name);
            $tag->normalized_name = RecipeTagName::normalized($tag->name);
        });
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
