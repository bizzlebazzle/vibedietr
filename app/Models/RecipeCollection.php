<?php

namespace App\Models;

use App\Models\Concerns\NormalizesPrivateOrganizationName;
use Database\Factories\RecipeCollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RecipeCollection extends Model
{
    /** @use HasFactory<RecipeCollectionFactory> */
    use HasFactory, NormalizesPrivateOrganizationName;

    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_collection_recipes')->withTimestamps()
            ->orderByPivot('created_at')->orderByPivot('recipe_id');
    }

    /** @return BelongsToMany<Bookmark, $this> */
    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(Bookmark::class, 'recipe_collection_bookmarks')->withTimestamps()
            ->orderByPivot('created_at')->orderByPivot('bookmark_id');
    }
}
