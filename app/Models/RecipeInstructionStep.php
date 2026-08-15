<?php

namespace App\Models;

use Database\Factories\RecipeInstructionStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeInstructionStep extends Model
{
    /** @use HasFactory<RecipeInstructionStepFactory> */
    use HasFactory;

    protected $fillable = ['text'];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<RecipeInstructionSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(RecipeInstructionSection::class, 'section_id');
    }
}
