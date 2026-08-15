<?php

namespace App\Models;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    protected $fillable = ['title', 'servings', 'visibility'];

    protected function casts(): array
    {
        return [
            'servings' => 'decimal:2',
            'lifecycle' => RecipeLifecycle::class,
            'visibility' => RecipeVisibility::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<RecipeIngredientLine, $this> */
    public function ingredientLines(): HasMany
    {
        return $this->hasMany(RecipeIngredientLine::class)->orderBy('position');
    }

    /** @return HasMany<RecipeInstructionSection, $this> */
    public function instructionSections(): HasMany
    {
        return $this->hasMany(RecipeInstructionSection::class)->orderBy('position');
    }

    /** @return HasMany<RecipeInstructionStep, $this> */
    public function instructionSteps(): HasMany
    {
        return $this->hasMany(RecipeInstructionStep::class)->orderBy('position');
    }
}
