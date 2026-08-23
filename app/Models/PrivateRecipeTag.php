<?php

namespace App\Models;

use App\Models\Concerns\NormalizesPrivateOrganizationName;
use Database\Factories\PrivateRecipeTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PrivateRecipeTag extends Model
{
    /** @use HasFactory<PrivateRecipeTagFactory> */
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
        return $this->belongsToMany(Recipe::class, 'private_recipe_tag_recipes')->withTimestamps()
            ->orderByPivot('created_at')->orderByPivot('recipe_id');
    }

    /** @return BelongsToMany<Bookmark, $this> */
    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(Bookmark::class, 'private_recipe_tag_bookmarks')->withTimestamps()
            ->orderByPivot('created_at')->orderByPivot('bookmark_id');
    }
}
