<?php

namespace App\Livewire\Recipes;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Recipes\RecipeIngredientLineWriter;
use App\Domain\Shared\Decimal;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Rules\ValidMeasurementUnit;
use Closure;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class IngredientLines extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public ?int $editingLineId = null;

    public string $originalText = '';

    public $quantity = null;

    public string $unit = '';

    public string $genericWording = '';

    public string $notes = '';

    public function mount(Recipe $recipe): void
    {
        $this->authorize('update', $recipe);
        $this->recipeId = $recipe->getKey();
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'originalText' => [
                'required',
                'string',
                'max:10000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('The original ingredient line is required.');
                    }
                },
            ],
            'quantity' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! is_string($value) && ! is_int($value)) {
                        $fail('The quantity must be a non-negative decimal.');

                        return;
                    }

                    try {
                        Decimal::forStorage(Decimal::parse($value));
                    } catch (InvalidArgumentException) {
                        $fail('The quantity must be a non-negative decimal within the supported range.');
                    }
                },
            ],
            'unit' => ['nullable', 'string', new ValidMeasurementUnit],
            'genericWording' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function saveLine(RecipeIngredientLineWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $this->validate();
        $attributes = $this->structuredAttributes();

        if ($this->editingLineId === null) {
            $writer->append($recipe, $attributes);
            session()->flash('ingredient-status', 'Ingredient line added.');
        } else {
            $writer->update($recipe, $this->editingLineId, $attributes);
            session()->flash('ingredient-status', 'Ingredient line updated.');
        }

        $this->resetEditor();
    }

    public function editLine(int $lineId): void
    {
        $recipe = $this->ownedRecipe();
        $line = $recipe->ingredientLines()->find($lineId);

        if (! $line instanceof RecipeIngredientLine) {
            abort(404);
        }

        $this->editingLineId = $line->getKey();
        $this->originalText = $line->original_text;
        $this->quantity = $line->quantity;
        $this->unit = $line->standard_unit instanceof StandardUnit
            ? MeasurementUnitRegistry::definition($line->standard_unit)->symbol
            : ($line->custom_unit ?? '');
        $this->genericWording = $line->generic_wording ?? '';
        $this->notes = $line->notes ?? '';
    }

    public function cancelEdit(): void
    {
        $this->resetEditor();
    }

    public function deleteLine(int $lineId, RecipeIngredientLineWriter $writer): void
    {
        $writer->delete($this->ownedRecipe(), $lineId);
        $this->resetEditor();
        session()->flash('ingredient-status', 'Ingredient line removed.');
    }

    public function moveUp(int $lineId, RecipeIngredientLineWriter $writer): void
    {
        $this->move($lineId, -1, $writer);
    }

    public function moveDown(int $lineId, RecipeIngredientLineWriter $writer): void
    {
        $this->move($lineId, 1, $writer);
    }

    /** @param list<int> $lineIds */
    public function reorder(array $lineIds, RecipeIngredientLineWriter $writer): void
    {
        Validator::make(
            ['lineIds' => $lineIds],
            ['lineIds' => ['array'], 'lineIds.*' => ['required', 'integer']]
        )->validate();

        $writer->reorder($this->ownedRecipe(), $lineIds);
    }

    public function render()
    {
        $recipe = $this->ownedRecipe();

        return view('livewire.recipes.ingredient-lines', [
            'lines' => $recipe->ingredientLines()->get(),
            'unitGroups' => MeasurementUnitRegistry::formGroups(),
            'customUnits' => MeasurementUnitRegistry::suggestedCustomUnits(),
        ]);
    }

    private function ownedRecipe(): Recipe
    {
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        $this->authorize('update', $recipe);

        return $recipe;
    }

    /** @return array<string, mixed> */
    private function structuredAttributes(): array
    {
        $unitText = $this->unit;
        $standardUnit = trim($unitText) === '' ? null : MeasurementUnitRegistry::findStandard($unitText);
        $quantity = $this->quantity === null || $this->quantity === ''
            ? null
            : Decimal::forStorage(Decimal::parse($this->quantity));

        return [
            'original_text' => $this->originalText,
            'quantity' => $quantity,
            'standard_unit' => $standardUnit?->value,
            'custom_unit' => $standardUnit === null && trim($unitText) !== '' ? $unitText : null,
            'generic_wording' => $this->blankToNull($this->genericWording),
            'notes' => $this->blankToNull($this->notes),
        ];
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function move(int $lineId, int $offset, RecipeIngredientLineWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $lineIds = $recipe->ingredientLines()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $index = array_search($lineId, $lineIds, true);

        if ($index === false) {
            abort(404);
        }

        $target = $index + $offset;

        if (! array_key_exists($target, $lineIds)) {
            return;
        }

        [$lineIds[$index], $lineIds[$target]] = [$lineIds[$target], $lineIds[$index]];
        $writer->reorder($recipe, $lineIds);
    }

    private function resetEditor(): void
    {
        $this->reset(['editingLineId', 'originalText', 'quantity', 'unit', 'genericWording', 'notes']);
        $this->resetValidation();
    }
}
