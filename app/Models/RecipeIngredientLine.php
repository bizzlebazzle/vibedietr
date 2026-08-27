<?php

namespace App\Models;

use App\Domain\Measurements\StandardUnit;
use Database\Factories\RecipeIngredientLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recipe_id
 * @property string $original_text
 * @property int $position
 * @property string|null $quantity
 * @property StandardUnit|null $standard_unit
 * @property string|null $custom_unit
 * @property string|null $generic_wording
 * @property string|null $notes
 * @property bool $requires_review
 * @property array<int, string>|null $parser_warnings
 * @property array<int, string>|null $uncertain_fields
 * @property-read Recipe $recipe
 */
class RecipeIngredientLine extends Model
{
    /** @use HasFactory<RecipeIngredientLineFactory> */
    use HasFactory;

    protected $fillable = [
        'original_text',
        'quantity',
        'standard_unit',
        'custom_unit',
        'generic_wording',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:18',
            'standard_unit' => StandardUnit::class,
            'requires_review' => 'boolean',
            'parser_warnings' => 'array',
            'uncertain_fields' => 'array',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
