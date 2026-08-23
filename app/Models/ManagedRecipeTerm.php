<?php

namespace App\Models;

use App\Domain\Recipes\ManagedRecipeTermCategory;
use App\Domain\Recipes\RecipeTagName;
use Database\Factories\ManagedRecipeTermFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ManagedRecipeTermCategory $category
 * @property bool $is_active
 */
class ManagedRecipeTerm extends Model
{
    /** @use HasFactory<ManagedRecipeTermFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (ManagedRecipeTerm $term): void {
            $term->name = RecipeTagName::display($term->name);
            $term->normalized_name = RecipeTagName::normalized($term->name);
        });
    }

    protected function casts(): array
    {
        return [
            'category' => ManagedRecipeTermCategory::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'managed_recipe_term_recipes')->withTimestamps();
    }

    /** @return HasMany<ManagedRecipeTermSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(ManagedRecipeTermSuggestion::class);
    }
}
