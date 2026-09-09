<?php

namespace App\Http\Requests;

use App\Domain\Catalogue\ManualCatalogueSubmissionChoice;
use App\Domain\Catalogue\ManualCatalogueSubmissionData;
use App\Domain\Catalogue\ManualFoodClassification;
use App\Domain\Catalogue\PackageStructure;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Rules\ValidNutrientValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualCatalogueSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $nutrients = array_map(fn (Nutrient $nutrient): string => $nutrient->value, Nutrient::cases());
        $units = array_map(fn (StandardUnit $unit): string => $unit->value, StandardUnit::cases());

        return [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'classification' => ['required', Rule::enum(ManualFoodClassification::class)],
            'brand' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'manufacturer' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'food_form' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'preparation' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'treatment' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'composition' => ['nullable', 'string', 'max:1000', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'item_type' => ['nullable', 'string', 'max:32', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'amount_per_item' => ['nullable', 'string', new ValidNutrientValue, 'gt:0', 'required_with:amount_per_item_unit'],
            'amount_per_item_unit' => ['nullable', Rule::in($units), 'required_with:amount_per_item'],
            'servings_per_item' => ['nullable', 'string', new ValidNutrientValue, 'gt:0'],
            'serving_amount' => ['nullable', 'string', new ValidNutrientValue, 'gt:0', 'required_with:serving_amount_unit'],
            'serving_amount_unit' => ['nullable', Rule::in($units), 'required_with:serving_amount'],
            'nutrition_basis' => ['required', Rule::in([
                NutrientBasis::Per100Gram->value,
                NutrientBasis::Per100Millilitre->value,
                NutrientBasis::PerServing->value,
            ])],
            'nutrition' => ['nullable', 'array:'.implode(',', $nutrients)],
            'nutrition.*' => ['nullable', 'string', new ValidNutrientValue],
            'duplicate_choice' => ['nullable', Rule::enum(ManualCatalogueSubmissionChoice::class)],
            'duplicate_item_id' => ['nullable', 'integer', 'min:1', 'required_with:duplicate_choice'],
            'distinction_explanation' => ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/u', 'required_if:duplicate_choice,continue_distinct'],
            'barcode' => ['prohibited'],
            'code' => ['prohibited'],
            'provider_barcode' => ['prohibited'],
            'provider_code' => ['prohibited'],
            'source_barcode' => ['prohibited'],
            'source' => ['prohibited'],
            'source_identifier' => ['prohibited'],
            'origin' => ['prohibited'],
            'status' => ['prohibited'],
            'submitted_by_user_id' => ['prohibited'],
            'current_catalogue_item_version_id' => ['prohibited'],
            'provenance' => ['prohibited'],
        ];
    }

    public function submissionData(): ManualCatalogueSubmissionData
    {
        $validated = $this->validated();
        $basis = NutrientBasis::from($validated['nutrition_basis']);
        $nutrition = [];

        foreach (($validated['nutrition'] ?? []) as $identifier => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $nutrient = Nutrient::from($identifier);
            $nutrition[] = new CatalogueNutrientObservation(
                $nutrient,
                $basis,
                (string) $value,
                match ($nutrient) {
                    Nutrient::EnergyKcal => NutrientUnit::Kilocalorie,
                    Nutrient::EnergyKj => NutrientUnit::Kilojoule,
                    Nutrient::Sodium => NutrientUnit::Milligram,
                    default => NutrientUnit::Gram,
                },
                NutrientProvenance::ManuallySubmitted,
                sourceField: 'manual_submission.'.$nutrient->value,
            );
        }

        return new ManualCatalogueSubmissionData(
            name: $validated['name'],
            classification: ManualFoodClassification::from($validated['classification']),
            brand: $validated['brand'] ?? null,
            manufacturer: $validated['manufacturer'] ?? null,
            foodForm: $validated['food_form'] ?? null,
            preparation: $validated['preparation'] ?? null,
            treatment: $validated['treatment'] ?? null,
            composition: $validated['composition'] ?? null,
            package: PackageStructure::make(
                packageCount: $validated['package_count'] ?? null,
                itemType: $validated['item_type'] ?? null,
                amountPerItem: $validated['amount_per_item'] ?? null,
                amountPerItemUnit: $validated['amount_per_item_unit'] ?? null,
                servingsPerItem: $validated['servings_per_item'] ?? null,
                servingAmount: $validated['serving_amount'] ?? null,
                servingAmountUnit: $validated['serving_amount_unit'] ?? null,
            ),
            nutrition: $nutrition,
            choice: isset($validated['duplicate_choice'])
                ? ManualCatalogueSubmissionChoice::from($validated['duplicate_choice'])
                : null,
            duplicateItemId: isset($validated['duplicate_item_id'])
                ? (int) $validated['duplicate_item_id']
                : null,
            distinctionExplanation: $validated['distinction_explanation'] ?? null,
        );
    }
}
