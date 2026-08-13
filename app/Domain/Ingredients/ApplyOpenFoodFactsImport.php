<?php

namespace App\Domain\Ingredients;

use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupResult;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Models\Ingredient;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final readonly class ApplyOpenFoodFactsImport
{
    public function __construct(private IngredientWriteNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $userInput
     */
    public function apply(
        Ingredient $ingredient,
        array $userInput,
        string $requestedBarcode,
        OpenFoodFactsLookupResult $result,
    ): Ingredient {
        $requestedBarcode = trim($requestedBarcode);
        $product = $result->product;

        if ($result->status !== OpenFoodFactsLookupStatus::Success
            || $product === null
            || $requestedBarcode === ''
            || trim($product->code) !== $requestedBarcode
        ) {
            throw new InvalidArgumentException('A consistent successful provider result is required.');
        }

        $mapped = $userInput;
        $mapped['name'] = $product->name ?? $mapped['name'];
        $mapped['keywords'] = $product->keywords;
        $mapped['categories'] = $product->categories;
        $mapped['nutriments'] = $product->nutriments;
        $mapped['image_url'] = $product->imageUrl;

        if ($product->quantity !== null) {
            $mapped['quantity'] = $product->quantity;
        }

        if ($product->quantityUnit !== null) {
            $mapped['quantity_unit'] = $product->quantityUnit;
        }

        if ($product->servingQuantity !== null) {
            $mapped['serving_quantity'] = $product->servingQuantity;
        }

        if ($product->servingQuantityUnit !== null) {
            $mapped['serving_quantity_unit'] = $product->servingQuantityUnit;
        }

        $prepared = IngredientWriteContract::prepare($mapped);
        $validated = Validator::make($prepared, IngredientWriteContract::rules())->validate();

        $ingredient->fill($this->normalizer->normalize($validated));
        $ingredient->barcode = $requestedBarcode;
        $ingredient->barcode_source = OpenFoodFactsClient::PROVIDER;
        $ingredient->barcode_imported_at = Date::now()->toImmutable()->utc();
        $ingredient->barcode_provenance = IngredientBarcodeProvenance::MachineImported;
        $ingredient->save();

        return $ingredient;
    }
}
