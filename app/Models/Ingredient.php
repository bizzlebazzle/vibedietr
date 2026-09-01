<?php

namespace App\Models;

use App\Domain\Ingredients\IngredientBarcodeProvenance;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'keywords',
        'categories',
        'nutriments',
        'quantity',
        'quantity_unit',
        'serving_quantity',
        'serving_quantity_unit',
        'recommended_servings',
        'image_url',
    ];

    protected $casts = [
        'keywords' => 'array',
        'categories' => 'array',
        'nutriments' => 'array',
        'barcode_provenance' => IngredientBarcodeProvenance::class,
        'barcode_imported_at' => 'immutable_datetime',
        'quantity' => 'decimal:3',
        'serving_quantity' => 'decimal:3',
        'recommended_servings' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<LegacyIngredientCatalogueMapping, $this> */
    public function catalogueMapping(): HasOne
    {
        return $this->hasOne(LegacyIngredientCatalogueMapping::class);
    }
}
