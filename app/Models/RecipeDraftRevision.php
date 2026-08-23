<?php

namespace App\Models;

use Database\Factories\RecipeDraftRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeDraftRevision extends Model
{
    /** @use HasFactory<RecipeDraftRevisionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'base_recipe_version_id');
    }
}
