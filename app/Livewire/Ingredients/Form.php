<?php

namespace App\Livewire\Ingredients;

use App\Domain\Measurements\MeasurementUnitParser;
use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Models\Ingredient;
use App\Rules\ValidMeasurementUnit;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Form extends Component
{
    public ?Ingredient $ingredient = null;

    #[Validate('required|string|max:255')]
    public string $name = '';
    public ?string $barcode = null;
    public array $keywords = [];
    public array $categories = [];
    public array $nutriments = []; // structure: ['raw'=>[], 'per_100g'=>[], 'per_serving'=>[]]
    public $per_100g_energy_kj = null;
    public $per_100g_energy_kcal = null;
    public $per_100g_fat = null;
    public $per_100g_saturates = null;
    public $per_100g_sugars = null;
    public $per_100g_salt = null;
    public $per_serving_energy_kj = null;
    public $per_serving_energy_kcal = null;
    public $per_serving_fat = null;
    public $per_serving_saturates = null;
    public $per_serving_sugars = null;
    public $per_serving_salt = null;

    #[Validate('required|numeric|min:0')]
    public $quantity = null;

    #[Validate('required|string|max:32')]
    public ?string $quantity_unit = null;

    public $serving_quantity = null;
    public ?string $serving_quantity_unit = null;
    public $recommended_servings = null;

    public ?string $image_url = null;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'keywords' => ['nullable', 'array'],
            'categories' => ['nullable', 'array'],
            'nutriments' => ['nullable', 'array'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'quantity_unit' => ['required', 'string', 'max:32', new ValidMeasurementUnit],
            'serving_quantity' => ['nullable', 'numeric', 'min:0'],
            'serving_quantity_unit' => ['nullable', 'string', 'max:32', new ValidMeasurementUnit],
            'recommended_servings' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'url'],
            ...$this->nutritionInputRules(),
        ];
    }

    public function mount(?Ingredient $ingredient = null)
    {
        $this->ingredient = $ingredient;
        $this->resetForm($ingredient);
    }

    public function resetForm(?Ingredient $ingredient = null): void
    {
        // Initialize with either model values or safe defaults
        $this->name = $ingredient?->name ?? '';
        $this->barcode = $ingredient?->barcode;
        $this->keywords = $ingredient?->keywords ?? [];
        $this->categories = $ingredient?->categories ?? [];
        $this->nutriments = $ingredient?->nutriments ?? [];
        $this->quantity = $ingredient?->quantity; // keep null until user sets it, validation will require it
        $this->quantity_unit = $ingredient?->quantity_unit;
        $this->serving_quantity = $ingredient?->serving_quantity;
        $this->serving_quantity_unit = $ingredient?->serving_quantity_unit;
        $this->recommended_servings = $ingredient?->recommended_servings;
        $this->image_url = $ingredient?->image_url;
        $this->hydrateNutritionInputs();
    }

    protected function existingIngredientForBarcode(?string $barcode): ?Ingredient
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '' || !auth()->check()) {
            return null;
        }

        return Ingredient::query()
            ->where('user_id', auth()->id())
            ->where('barcode', $barcode)
            ->when($this->ingredient?->exists, fn ($query) => $query->whereKeyNot($this->ingredient->getKey()))
            ->first();
    }

    protected function redirectToExistingBarcodeIngredient(?string $barcode): bool
    {
        $ingredient = $this->existingIngredientForBarcode($barcode);

        if (!$ingredient) {
            return false;
        }

        session()->flash('status', 'That barcode has already been added.');
        $this->redirectRoute('ingredients.show', ['ingredient' => $ingredient], navigate: true);

        return true;
    }

    public function fetchFromOff(?string $barcode = null): void
    {
        $barcode = trim((string) ($barcode ?? $this->barcode ?? ''));

        if ($barcode === '') {
            $this->addError('barcode', 'Enter a barcode before fetching from OpenFoodFacts.');
            return;
        }

        $this->resetErrorBag('barcode');
        $this->barcode = $barcode;

        if ($this->redirectToExistingBarcodeIngredient($barcode)) {
            return;
        }

        // OFF v2 product endpoint (adjust fields as needed)
        $resp = Http::acceptJson()
            ->get("https://world.openfoodfacts.org/api/v2/product/{$barcode}.json", [
                'fields' => implode(',', [
                    'code',
                    'product_name',
                    'quantity',
                    'categories_tags',
                    'states_tags',
                    'keywords',
                    'nutriments',
                    'serving_quantity',
                    'serving_size',
                    'image_front_small_url',
                    'image_front_url',
                ])
            ]);

        if (!$resp->ok()) {
            $this->dispatch('notify', type: 'error', message: 'OFF lookup failed.');
            return;
        }

        $product = $resp->json('product');
        if (!$product) {
            $this->dispatch('notify', type: 'error', message: 'No product found for that barcode.');
            return;
        }

        // Map fields
        $this->name = $product['product_name'] ?? $this->name ?? '';
        $this->keywords = $product['keywords'] ?? ($product['_keywords'] ?? []);
        $this->categories = $product['categories_tags'] ?? [];

        $parsedQuantity = $this->parseProductQuantityFromOff($product['quantity'] ?? null);
        if ($parsedQuantity['quantity'] !== null) {
            $this->quantity = $parsedQuantity['quantity'];
        }
        if ($parsedQuantity['unit'] !== null || $parsedQuantity['multiple']) {
            $this->quantity_unit = $parsedQuantity['unit'];
        }

        $nutr = $product['nutriments'] ?? [];
        // Normalize nutriments into three buckets
        $this->nutriments = array_replace_recursive($this->nutriments, [
            'raw' => $nutr,
            'per_100g' => [
                'carbohydrates'   => $nutr['carbohydrates_100g'] ?? null,
                'fat'             => $nutr['fat_100g'] ?? null,
                'energy_kcal'     => $this->roundNutritionInteger($nutr['energy-kcal_100g'] ?? ($nutr['energy-kcal_100g'] ?? null)),
                'energy_kj'       => $this->roundNutritionInteger($nutr['energy-kj_100g'] ?? ($nutr['energy-kj_100g'] ?? null)),
                'fiber'           => $nutr['fiber_100g'] ?? null,
                'proteins'        => $nutr['proteins_100g'] ?? null,
                'salt'            => $nutr['salt_100g'] ?? null,
                'saturated_fat'   => $nutr['saturated-fat_100g'] ?? ($nutr['saturated_fat_100g'] ?? null),
                'sodium'          => $nutr['sodium_100g'] ?? null,
                'sugars'          => $nutr['sugars_100g'] ?? null,
            ],
            'per_serving' => [
                'carbohydrates'   => $nutr['carbohydrates_serving'] ?? null,
                'fat'             => $nutr['fat_serving'] ?? null,
                'energy_kcal'     => $this->roundNutritionInteger($nutr['energy-kcal_serving'] ?? ($nutr['energy_kcal_serving'] ?? null)),
                'energy_kj'       => $this->roundNutritionInteger($nutr['energy-kj_serving'] ?? ($nutr['energy_kj_serving'] ?? null)),
                'fiber'           => $nutr['fiber_serving'] ?? null,
                'proteins'        => $nutr['proteins_serving'] ?? null,
                'salt'            => $nutr['salt_serving'] ?? null,
                'saturated_fat'   => $nutr['saturated-fat_serving'] ?? ($nutr['saturated_fat_serving'] ?? null),
                'sodium'          => $nutr['sodium_serving'] ?? null,
                'sugars'          => $nutr['sugars_serving'] ?? null,
            ],
        ]);

        $this->hydrateNutritionInputs();

        // Guess quantity + unit from serving info if helpful
        if (!$this->serving_quantity && isset($product['serving_quantity'])) {
            $this->serving_quantity = is_numeric($product['serving_quantity'])
                ? (float) $product['serving_quantity']
                : null;
        }

        if (!$this->serving_quantity_unit) {
            $this->serving_quantity_unit = $this->guessServingQuantityUnitFromOff($product['serving_size'] ?? null);
        }

        // Image
        $this->image_url = $product['image_front_url']
            ?? $product['image_front_small_url']
            ?? null;

        $this->dispatch('notify', type: 'success', message: 'Successfully loaded item information from OpenFoodFacts.');
    }

    protected function nutritionInputRules(): array
    {
        $rules = [];

        foreach ($this->nutritionInputMap() as $field => $config) {
            $rules[$field] = $config['integer']
                ? ['nullable', 'integer', 'min:0']
                : ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    protected function nutritionInputMap(): array
    {
        return [
            'per_100g_energy_kj' => ['bucket' => 'per_100g', 'key' => 'energy_kj', 'integer' => true],
            'per_100g_energy_kcal' => ['bucket' => 'per_100g', 'key' => 'energy_kcal', 'integer' => true],
            'per_100g_fat' => ['bucket' => 'per_100g', 'key' => 'fat', 'integer' => false],
            'per_100g_saturates' => ['bucket' => 'per_100g', 'key' => 'saturated_fat', 'integer' => false],
            'per_100g_sugars' => ['bucket' => 'per_100g', 'key' => 'sugars', 'integer' => false],
            'per_100g_salt' => ['bucket' => 'per_100g', 'key' => 'salt', 'integer' => false],
            'per_serving_energy_kj' => ['bucket' => 'per_serving', 'key' => 'energy_kj', 'integer' => true],
            'per_serving_energy_kcal' => ['bucket' => 'per_serving', 'key' => 'energy_kcal', 'integer' => true],
            'per_serving_fat' => ['bucket' => 'per_serving', 'key' => 'fat', 'integer' => false],
            'per_serving_saturates' => ['bucket' => 'per_serving', 'key' => 'saturated_fat', 'integer' => false],
            'per_serving_sugars' => ['bucket' => 'per_serving', 'key' => 'sugars', 'integer' => false],
            'per_serving_salt' => ['bucket' => 'per_serving', 'key' => 'salt', 'integer' => false],
        ];
    }

    protected function hydrateNutritionInputs(): void
    {
        foreach ($this->nutritionInputMap() as $property => ['bucket' => $bucket, 'key' => $key, 'integer' => $integer]) {
            $value = data_get($this->nutriments, "{$bucket}.{$key}");
            $this->{$property} = $this->normalizeNutritionValue($value, $integer);
        }
    }

    protected function roundNutritionInteger($value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    protected function roundNutritionDecimal($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    protected function normalizeNutritionValue($value, bool $integer): int|float|null
    {
        return $integer
            ? $this->roundNutritionInteger($value)
            : $this->roundNutritionDecimal($value);
    }

    protected function normalizeNutritionInputs(): void
    {
        foreach ($this->nutritionInputMap() as $property => ['integer' => $integer]) {
            $this->{$property} = $this->normalizeNutritionValue($this->{$property}, $integer);
        }
    }

    protected function mergeNutritionInputsIntoNutriments(): array
    {
        $nutriments = $this->nutriments;

        foreach ($this->nutritionInputMap() as $property => ['bucket' => $bucket, 'key' => $key, 'integer' => $integer]) {
            $value = $this->{$property};

            if (blank($value)) {
                unset($nutriments[$bucket][$key]);
                continue;
            }

            $nutriments[$bucket][$key] = $this->normalizeNutritionValue($value, $integer);
        }

        foreach (['per_100g', 'per_serving'] as $bucket) {
            if (empty($nutriments[$bucket])) {
                unset($nutriments[$bucket]);
            }
        }

        return $nutriments;
    }

    protected function guessServingQuantityUnitFromOff(?string $servingSize): ?string
    {
        if (!is_string($servingSize) || trim($servingSize) === '') {
            return null;
        }

        return $this->guessUnitFromText($servingSize);
    }

    protected function parseProductQuantityFromOff(?string $quantityText): array
    {
        if (!is_string($quantityText) || trim($quantityText) === '') {
            return ['quantity' => null, 'unit' => null, 'multiple' => false];
        }

        $value = strtolower(trim($quantityText));

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*[a-z]/i', $value, $matches)) {
            return [
                'quantity' => $this->normalizeParsedNumber($matches[1]),
                'unit' => null,
                'multiple' => true,
            ];
        }

        if (preg_match('/\((\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*[a-z][^)]*\)/i', $value, $matches)) {
            return [
                'quantity' => $this->normalizeParsedNumber($matches[1]),
                'unit' => null,
                'multiple' => true,
            ];
        }

        if (preg_match('/\b(\d+(?:[.,]\d+)?)\b/', $value, $matches)) {
            return [
                'quantity' => $this->normalizeParsedNumber($matches[1]),
                'unit' => $this->guessUnitFromText($quantityText),
                'multiple' => false,
            ];
        }

        return [
            'quantity' => null,
            'unit' => $this->guessUnitFromText($quantityText),
            'multiple' => false,
        ];
    }

    protected function guessUnitFromText(?string $text): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $unit = MeasurementUnitParser::findInText($text);

        return $unit === null ? null : MeasurementUnitParser::parsedValue($unit);
    }

    protected function normalizeParsedNumber(string $value): int|float|null
    {
        $normalized = str_replace(',', '.', trim($value));

        if (!is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return floor($number) === $number ? (int) $number : $number;
    }

    public function measurementUnitGroups(): array
    {
        $groups = MeasurementUnitRegistry::formGroups();
        $customUnits = MeasurementUnitRegistry::suggestedCustomUnits();
        $groups['Custom measures'] = array_combine($customUnits, array_map('ucfirst', $customUnits));

        return $groups;
    }

    public function customMeasurementUnits(): array
    {
        return array_values(array_filter(
            array_unique([
                trim((string) $this->quantity_unit),
                trim((string) $this->serving_quantity_unit),
            ]),
            fn (string $unit) => $unit !== '' && ! in_array($unit, $this->defaultMeasurementUnits(), true)
        ));
    }

    protected function defaultMeasurementUnits(): array
    {
        return collect($this->measurementUnitGroups())
            ->flatMap(fn (array $units) => array_keys($units))
            ->values()
            ->all();
    }

    protected function selectableMeasurementUnits(): array
    {
        return array_values(array_unique([
            ...$this->defaultMeasurementUnits(),
            ...$this->customMeasurementUnits(),
        ]));
    }

    public function save(): void
    {
        $this->normalizeNutritionInputs();

        if ($this->redirectToExistingBarcodeIngredient($this->barcode)) {
            return;
        }

        $this->validate($this->rules());
        $this->quantity_unit = MeasurementUnitParser::storageValue($this->quantity_unit);
        $this->serving_quantity_unit = blank($this->serving_quantity_unit)
            ? null
            : MeasurementUnitParser::storageValue($this->serving_quantity_unit);
        $this->nutriments = $this->mergeNutritionInputsIntoNutriments();

        $payload = [
            'user_id'                 => auth()->id(),
            'name'                    => $this->name,
            'barcode'                 => $this->barcode ?: null,
            'keywords'                => $this->keywords ?: null,
            'categories'              => $this->categories ?: null,
            'nutriments'              => $this->nutriments ?: null,
            'quantity'                => $this->quantity,
            'quantity_unit'           => $this->quantity_unit,
            'serving_quantity'        => $this->serving_quantity ?: null,
            'serving_quantity_unit'   => $this->serving_quantity_unit ?: null,
            'recommended_servings'    => $this->recommended_servings ?: null,
            'image_url'               => $this->image_url ?: null,
        ];

        if ($this->ingredient?->exists) {
            $this->ingredient->update($payload);
            $this->dispatch('notify', type: 'success', message: 'Ingredient updated.');
            $this->dispatch('ingredientSaved')->to(Index::class);
        } else {
            $this->ingredient = Ingredient::create($payload);
            $this->dispatch('notify', type: 'success', message: 'Ingredient created.');
            $this->dispatch('ingredientSaved')->to(Index::class);

            if (!request()->routeIs('ingredients.index')) {
                $this->redirectRoute('ingredients.index', navigate: true);
            }
        }
    }

    public function render()
    {
        return view('livewire.ingredients.form', [
            'measurementUnitGroups' => $this->measurementUnitGroups(),
            'customMeasurementUnits' => $this->customMeasurementUnits(),
            'nutritionPanels' => [
                [
                    'title' => 'Per 100g',
                    'rows' => [
                        [
                            'label' => 'Energy',
                            'inputs' => [
                                ['model' => 'per_100g_energy_kj', 'label' => 'kJ', 'step' => '1'],
                                ['model' => 'per_100g_energy_kcal', 'label' => 'kcal', 'step' => '1'],
                            ],
                        ],
                        ['label' => 'Fat', 'model' => 'per_100g_fat', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Saturates', 'model' => 'per_100g_saturates', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Sugars', 'model' => 'per_100g_sugars', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Salt', 'model' => 'per_100g_salt', 'unit' => 'g', 'step' => '0.01'],
                    ],
                ],
                [
                    'title' => 'Per serving',
                    'rows' => [
                        [
                            'label' => 'Energy',
                            'inputs' => [
                                ['model' => 'per_serving_energy_kj', 'label' => 'kJ', 'step' => '1'],
                                ['model' => 'per_serving_energy_kcal', 'label' => 'kcal', 'step' => '1'],
                            ],
                        ],
                        ['label' => 'Fat', 'model' => 'per_serving_fat', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Saturates', 'model' => 'per_serving_saturates', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Sugars', 'model' => 'per_serving_sugars', 'unit' => 'g', 'step' => '0.01'],
                        ['label' => 'Salt', 'model' => 'per_serving_salt', 'unit' => 'g', 'step' => '0.01'],
                    ],
                ],
            ],
        ]);
    }
}
