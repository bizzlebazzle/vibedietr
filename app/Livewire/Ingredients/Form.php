<?php

namespace App\Livewire\Ingredients;

use App\Domain\Ingredients\ApplyOpenFoodFactsImport;
use App\Domain\Ingredients\IngredientWriteContract;
use App\Domain\Ingredients\IngredientWriteNormalizer;
use App\Domain\Ingredients\PendingIngredientImport;
use App\Domain\Ingredients\PendingIngredientImportStore;
use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientRegistry;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Models\Ingredient;
use App\Models\User;
use App\Security\Limits\AbuseRateLimiter;
use App\Security\Limits\LimiterIdentity;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    public ?int $ingredientId = null;

    public $name = '';

    public $barcode = null;

    #[Locked]
    public ?string $pendingImportToken = null;

    public $keywords = [];

    public $categories = [];

    public $nutriments = []; // structure: ['raw'=>[], 'per_100g'=>[], 'per_serving'=>[]]

    public $per_100g_energy_kcal = null;

    public $per_100g_energy_kj = null;

    public $per_100g_fat = null;

    public $per_100g_saturated_fat = null;

    public $per_100g_carbohydrates = null;

    public $per_100g_sugars = null;

    public $per_100g_fibre = null;

    public $per_100g_protein = null;

    public $per_100g_salt = null;

    public $per_100g_sodium = null;

    public $per_serving_energy_kcal = null;

    public $per_serving_energy_kj = null;

    public $per_serving_fat = null;

    public $per_serving_saturated_fat = null;

    public $per_serving_carbohydrates = null;

    public $per_serving_sugars = null;

    public $per_serving_fibre = null;

    public $per_serving_protein = null;

    public $per_serving_salt = null;

    public $per_serving_sodium = null;

    public $quantity = null;

    public $quantity_unit = null;

    public $serving_quantity = null;

    public $serving_quantity_unit = null;

    public $recommended_servings = null;

    public $image_url = null;

    protected function rules(): array
    {
        return IngredientWriteContract::livewireRules();
    }

    /** @return array<string, array<int, string>> */
    protected function barcodeLookupRules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:64'],
        ];
    }

    public function mount(?Ingredient $ingredient = null)
    {
        $this->ingredientId = $ingredient?->getKey();
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

        if ($barcode === '' || ! auth()->check()) {
            return null;
        }

        return Ingredient::query()
            ->where('user_id', auth()->id())
            ->where('barcode', $barcode)
            ->when($this->ingredientId !== null, fn ($query) => $query->whereKeyNot($this->ingredientId))
            ->first();
    }

    protected function redirectToExistingBarcodeIngredient(?string $barcode): bool
    {
        $ingredient = $this->existingIngredientForBarcode($barcode);

        if (! $ingredient) {
            return false;
        }

        session()->flash('status', 'That barcode has already been added.');
        $this->redirectRoute('ingredients.show', ['ingredient' => $ingredient], navigate: true);

        return true;
    }

    public function fetchFromOff(?string $barcode = null): void
    {
        $this->authorizeMutation();

        $pendingImports = app(PendingIngredientImportStore::class);
        $pendingImports->forget($this->pendingImportToken);
        $this->pendingImportToken = null;

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

        $this->validateOnly('barcode', $this->barcodeLookupRules());
        $identities = app(LimiterIdentity::class);
        $limiter = app(AbuseRateLimiter::class);
        $limiter->consume(
            'barcode_user', $identities->request(request()),
            (int) config('security.throttles.barcode_user.attempts'),
            (int) config('security.throttles.barcode_user.decay_seconds'),
        );
        $limiter->consume(
            'barcode_global', 'global',
            (int) config('security.throttles.barcode_global.attempts'),
            (int) config('security.throttles.barcode_global.decay_seconds'),
        );

        $result = app(OpenFoodFactsClient::class)->lookup($barcode);

        if ($result->status !== OpenFoodFactsLookupStatus::Success || $result->product === null) {
            $message = match ($result->status) {
                OpenFoodFactsLookupStatus::NotFound => 'No product found for that barcode.',
                OpenFoodFactsLookupStatus::RateLimited => 'Too many lookups. Please try again shortly.',
                OpenFoodFactsLookupStatus::InvalidResponse => 'Product data could not be read.',
                OpenFoodFactsLookupStatus::Unavailable => 'Food database temporarily unavailable. Please try again.',
                OpenFoodFactsLookupStatus::PermanentFailure => 'Food database could not complete the lookup.',
                OpenFoodFactsLookupStatus::Success => 'Product data could not be read.',
            };

            $this->dispatch('notify', type: 'error', message: $message);

            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->pendingImportToken = $pendingImports->remember(
            $user, $this->ingredientId, $barcode, $result
        );

        $product = $result->product;
        $this->name = $product->name ?? $this->name;
        $this->keywords = $product->keywords;
        $this->categories = $product->categories;

        if ($product->quantity !== null) {
            $this->quantity = $product->quantity;
        }
        if ($product->quantityUnit !== null || $product->multipleQuantity) {
            $this->quantity_unit = $product->quantityUnit;
        }

        $this->nutriments = array_replace_recursive($this->nutriments, $product->nutriments);
        $this->hydrateNutritionInputs();

        if (! $this->serving_quantity && $product->servingQuantity !== null) {
            $this->serving_quantity = $product->servingQuantity;
        }

        if (! $this->serving_quantity_unit) {
            $this->serving_quantity_unit = $product->servingQuantityUnit;
        }

        $this->image_url = $product->imageUrl;

        $this->dispatch('notify', type: 'success', message: 'Successfully loaded item information from OpenFoodFacts.');
    }

    protected function nutritionInputMap(): array
    {
        return IngredientWriteContract::nutritionInputMap();
    }

    protected function hydrateNutritionInputs(): void
    {
        foreach ($this->nutritionInputMap() as $property => ['bucket' => $bucket, 'key' => $key]) {
            $this->{$property} = data_get($this->nutriments, "{$bucket}.{$key}");
        }
    }

    protected function mergeNutritionInputsIntoNutriments(): array
    {
        $nutriments = is_array($this->nutriments) ? $this->nutriments : [];

        foreach ($this->nutritionInputMap() as $property => ['bucket' => $bucket, 'key' => $key]) {
            $value = $this->{$property};

            if ($value === null || (is_string($value) && trim($value) === '')) {
                unset($nutriments[$bucket][$key]);

                continue;
            }

            $nutriments[$bucket][$key] = $value;
        }

        foreach (['per_100g', 'per_serving'] as $bucket) {
            if (empty($nutriments[$bucket])) {
                unset($nutriments[$bucket]);
            }
        }

        return $nutriments;
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

    /** @return array<string, mixed> */
    protected function writeInput(): array
    {
        $input = [];

        foreach (IngredientWriteContract::fields() as $field) {
            $input[$field] = $this->{$field};
        }

        foreach (array_keys($this->nutritionInputMap()) as $property) {
            $input[$property] = $this->{$property};
        }

        return $input;
    }

    protected function prepareWriteInput(): void
    {
        foreach (IngredientWriteContract::prepare($this->writeInput()) as $field => $value) {
            $this->{$field} = $value;
        }
    }

    public function save(): void
    {
        $ingredient = $this->authorizeMutation();

        $this->prepareWriteInput();

        $validated = $this->validate($this->rules());
        $validated['nutriments'] = $this->mergeNutritionInputsIntoNutriments();
        $payload = app(IngredientWriteNormalizer::class)->normalize(Arr::only($validated, IngredientWriteContract::fields()));

        $this->name = $payload['name'];
        $this->keywords = $payload['keywords'] ?? [];
        $this->categories = $payload['categories'] ?? [];
        $this->nutriments = $payload['nutriments'] ?? [];
        $this->quantity_unit = $payload['quantity_unit'];
        $this->serving_quantity = $payload['serving_quantity'] ?? null;
        $this->serving_quantity_unit = $payload['serving_quantity_unit'] ?? null;
        $this->recommended_servings = $payload['recommended_servings'] ?? null;
        $this->image_url = $payload['image_url'] ?? null;

        $pendingImport = $this->pendingImportForSave();

        if ($this->pendingImportToken !== null && $pendingImport === null) {
            $this->addError('barcode', 'The verified barcode lookup expired. Fetch it again before saving.');

            return;
        }

        if ($ingredient) {
            $ingredient = Ingredient::query()->findOrFail($ingredient->getKey());
            $this->authorize('update', $ingredient);
            $this->persist($ingredient, $payload, $pendingImport);
            $this->ingredientId = $ingredient->getKey();
            $this->dispatch('notify', type: 'success', message: 'Ingredient updated.');
            $this->dispatch('ingredientSaved')->to(Index::class);
        } else {
            $this->authorize('create', Ingredient::class);

            $ingredient = new Ingredient($payload);
            $ingredient->user()->associate(auth()->user());
            $this->persist($ingredient, $payload, $pendingImport);
            $this->ingredientId = $ingredient->getKey();
            $this->dispatch('notify', type: 'success', message: 'Ingredient created.');
            $this->dispatch('ingredientSaved')->to(Index::class);

            if (! request()->routeIs('ingredients.index')) {
                $this->redirectRoute('ingredients.index', navigate: true);
            }
        }

        $this->barcode = $ingredient->barcode;
        app(PendingIngredientImportStore::class)->forget($this->pendingImportToken);
        $this->pendingImportToken = null;
    }

    protected function pendingImportForSave(): ?PendingIngredientImport
    {
        if ($this->pendingImportToken === null) {
            return null;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return app(PendingIngredientImportStore::class)->get(
            $this->pendingImportToken,
            $user,
            $this->ingredientId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function persist(
        Ingredient $ingredient,
        array $payload,
        ?PendingIngredientImport $pendingImport,
    ): void {
        if ($pendingImport === null) {
            $ingredient->fill($payload)->save();

            return;
        }

        app(ApplyOpenFoodFactsImport::class)->apply(
            $ingredient,
            $payload,
            $pendingImport->requestedBarcode,
            $pendingImport->result,
        );
    }

    protected function authorizeMutation(): ?Ingredient
    {
        if ($this->ingredientId === null) {
            $this->authorize('create', Ingredient::class);

            return null;
        }

        $ingredient = Ingredient::query()->findOrFail($this->ingredientId);
        $this->authorize('update', $ingredient);

        return $ingredient;
    }

    public function render()
    {
        return view('livewire.ingredients.form', [
            'measurementUnitGroups' => $this->measurementUnitGroups(),
            'customMeasurementUnits' => $this->customMeasurementUnits(),
            'nutritionPanels' => $this->nutritionPanels(),
        ]);
    }

    /** @return list<array{title: string, rows: array<int, array<string, mixed>>}> */
    protected function nutritionPanels(): array
    {
        return [
            ['title' => 'Per 100g', 'rows' => $this->nutritionRows('per_100g')],
            ['title' => 'Per serving', 'rows' => $this->nutritionRows('per_serving')],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function nutritionRows(string $bucket): array
    {
        $energy = [Nutrient::EnergyKcal, Nutrient::EnergyKj];
        $rows = [[
            'label' => NutrientRegistry::definition(Nutrient::EnergyKcal)->label,
            'inputs' => array_map(fn (Nutrient $nutrient): array => [
                'model' => "{$bucket}_{$nutrient->value}",
                'label' => NutrientRegistry::definition($nutrient)->preferredDisplayUnit->symbol(),
                'step' => 'any',
            ], $energy),
        ]];

        foreach (NutrientRegistry::all() as $definition) {
            if (in_array($definition->id, $energy, true)) {
                continue;
            }

            $rows[] = [
                'label' => $definition->label,
                'model' => "{$bucket}_{$definition->id->value}",
                'unit' => $definition->canonicalStorageUnit->symbol(),
                'step' => 'any',
            ];
        }

        return $rows;
    }
}
