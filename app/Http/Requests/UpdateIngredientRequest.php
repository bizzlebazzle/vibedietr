<?php

namespace App\Http\Requests;

use App\Domain\Ingredients\IngredientWriteContract;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ingredient = $this->route('ingredient');

        return $ingredient instanceof Ingredient
            && ($this->user()?->can('update', $ingredient) ?? false);
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
