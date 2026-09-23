<?php

namespace App\Http\Requests;

use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\NutritionTargets\NutritionTargetType;
use App\Domain\Shared\Decimal;
use App\Rules\ValidNutritionTargetValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class NutritionTargetProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $nutrients = NutrientRegistry::stableIdentifiers();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'targets' => ['required', 'array:'.implode(',', $nutrients)],
            'user_id' => ['prohibited'],
            'is_default' => ['prohibited'],
        ];

        foreach (NutrientRegistry::all() as $definition) {
            $nutrient = $definition->id->value;
            $type = "targets.$nutrient.type";
            $rules["targets.$nutrient"] = ['sometimes', 'array:type,exact_value,minimum_value,maximum_value'];
            $rules[$type] = ['nullable', Rule::enum(NutritionTargetType::class)];
            $rules["targets.$nutrient.exact_value"] = [
                "required_if:$type,".NutritionTargetType::Exact->value,
                "prohibited_unless:$type,".NutritionTargetType::Exact->value,
                'nullable',
                new ValidNutritionTargetValue($definition),
            ];
            $rules["targets.$nutrient.minimum_value"] = [
                "required_if:$type,".NutritionTargetType::Minimum->value.','.NutritionTargetType::Range->value,
                "prohibited_unless:$type,".NutritionTargetType::Minimum->value.','.NutritionTargetType::Range->value,
                'nullable',
                new ValidNutritionTargetValue($definition),
            ];
            $rules["targets.$nutrient.maximum_value"] = [
                "required_if:$type,".NutritionTargetType::Maximum->value.','.NutritionTargetType::Range->value,
                "prohibited_unless:$type,".NutritionTargetType::Maximum->value.','.NutritionTargetType::Range->value,
                'nullable',
                new ValidNutritionTargetValue($definition),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (NutrientRegistry::stableIdentifiers() as $nutrient) {
                $target = $this->input("targets.$nutrient", []);
                if (! is_array($target) || ($target['type'] ?? null) !== NutritionTargetType::Range->value) {
                    continue;
                }

                try {
                    $minimum = Decimal::parse((string) ($target['minimum_value'] ?? ''));
                    $maximum = Decimal::parse((string) ($target['maximum_value'] ?? ''));
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($minimum->isGreaterThan($maximum)) {
                    $validator->errors()->add(
                        "targets.$nutrient.maximum_value",
                        'The maximum value must be greater than or equal to the minimum value.',
                    );
                }
            }
        });
    }
}
