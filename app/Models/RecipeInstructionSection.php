<?php

namespace App\Models;

use Database\Factories\RecipeInstructionSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeInstructionSection extends Model
{
    /** @use HasFactory<RecipeInstructionSectionFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return HasMany<RecipeInstructionStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeInstructionStep::class, 'section_id')->orderBy('position');
    }
}
