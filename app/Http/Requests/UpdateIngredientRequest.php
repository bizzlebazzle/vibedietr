<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name'                     => ['required', 'string', 'max:255'],
            'barcode'                  => ['nullable', 'string', 'max:64'],
            'keywords'                 => ['nullable', 'array'],
            'categories'               => ['nullable', 'array'],
            'nutriments'               => ['nullable', 'array'],
            'quantity'                 => ['required', 'numeric', 'min:0'],
            'quantity_unit'            => ['required', 'string', 'max:32'],
            'serving_quantity'         => ['nullable', 'numeric', 'min:0'],
            'serving_quantity_unit'    => ['nullable', 'string', 'max:32'],
            'recommended_servings'     => ['nullable', 'numeric', 'min:0'],
            'image_url'                => ['nullable', 'url'],
        ];
    }
}
