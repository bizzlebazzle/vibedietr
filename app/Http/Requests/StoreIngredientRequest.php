<?php

namespace App\Http\Requests;

use App\Domain\Ingredients\IngredientWriteContract;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ingredient::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(IngredientWriteContract::prepare($this->all()));
    }

    public function rules(): array
    {
        return IngredientWriteContract::rules();
    }
}
