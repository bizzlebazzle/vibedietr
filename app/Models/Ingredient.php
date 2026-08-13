<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ingredient extends Model
{
    /** @use HasFactory<\Database\Factories\IngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'barcode',
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
        'quantity' => 'decimal:3',
        'serving_quantity' => 'decimal:3',
        'recommended_servings' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
