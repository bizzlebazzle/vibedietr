<?php

namespace App\Http\Requests;

use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Rules\ValidNutrientValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMealPlanItemEntryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $nutrition = $this->input('one_off_nutrition');

        if (is_array($nutrition) && ! collect($nutrition)->contains(fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')) {
            $this->merge(['one_off_nutrition' => null]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $nutrients = array_map(fn (Nutrient $nutrient): string => $nutrient->value, Nutrient::cases());

        return [
            'slot_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::enum(MealPlanItemEntryKind::class)],
            'planned_amount' => ['required', 'string', new ValidNutrientValue, 'gt:0'],
            'planned_unit' => ['required', Rule::enum(StandardUnit::class)],
            'catalogue_item_id' => [
                'required_if:kind,'.MealPlanItemEntryKind::Catalogue->value,
                'prohibited_unless:kind,'.MealPlanItemEntryKind::Catalogue->value,
                'integer',
                'min:1',
            ],
            'one_off_wording' => [
                'required_if:kind,'.MealPlanItemEntryKind::OneOff->value,
                'prohibited_unless:kind,'.MealPlanItemEntryKind::OneOff->value,
                'string',
                'max:255',
                'not_regex:/[\x00-\x1F\x7F]/u',
            ],
            'one_off_nutrition_basis' => [
                'prohibited_unless:kind,'.MealPlanItemEntryKind::OneOff->value,
                'required_with:one_off_nutrition',
                'nullable',
                Rule::in([
                    NutrientBasis::Per100Gram->value,
                    NutrientBasis::Per100Millilitre->value,
                    NutrientBasis::PerServing->value,
                    NutrientBasis::PerItem->value,
                ]),
            ],
            'one_off_nutrition' => [
                'prohibited_unless:kind,'.MealPlanItemEntryKind::OneOff->value,
                'nullable',
                'array:'.implode(',', $nutrients),
            ],
            'one_off_nutrition.*' => ['nullable', 'string', new ValidNutrientValue],
            'catalogue_item_version_id' => ['prohibited'],
            'catalogue_snapshot' => ['prohibited'],
            'catalogue_nutrition_snapshot' => ['prohibited'],
            'submitted_by_user_id' => ['prohibited'],
        ];
    }
}
