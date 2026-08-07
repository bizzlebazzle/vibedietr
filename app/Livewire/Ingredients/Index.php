<?php

namespace App\Livewire\Ingredients;

use App\Models\Ingredient;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showFormModal = false;

    public bool $showDetailsModal = false;

    public ?int $viewingIngredientId = null;

    protected $listeners = [
        'ingredientSaved' => 'onIngredientSaved',
        'close-modal' => 'closeModal',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function openFormModal()
    {
        $this->showFormModal = true;
    }

    public function closeModal()
    {
        $this->showFormModal = false;
        $this->showDetailsModal = false;
        $this->editingIngredientId = null;
        $this->viewingIngredientId = null;
    }

    public function onIngredientSaved()
    {
        // Close modal and refresh the list
        $this->showFormModal = false;
        $this->showDetailsModal = false;
        $this->resetPage(); // optional: go back to first page so new item is visible
    }

    public ?int $editingIngredientId = null;

    public function openEditModal(int $id): void
    {
        $ingredient = Ingredient::findOrFail($id);

        $this->authorize('update', $ingredient); // enforce ownership

        $this->showDetailsModal = false;
        $this->viewingIngredientId = null;
        $this->editingIngredientId = $id;
        $this->showFormModal = true;
    }

    public function openShowModal(int $id): void
    {
        $ingredient = Ingredient::findOrFail($id);

        $this->authorize('view', $ingredient);

        $this->showFormModal = false;
        $this->editingIngredientId = null;
        $this->viewingIngredientId = $id;
        $this->showDetailsModal = true;
    }

    public function formatMeasurementValue($value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    public function displayUnitLabel(?string $unit, $value = null): ?string
    {
        $unit = trim((string) $unit);

        if ($unit === '') {
            return null;
        }

        $nonPluralShorthandUnits = [
            'mg', 'g', 'kg', 'ml', 'cl', 'l',
            'tsp', 'tbsp', 'fl oz', 'cup',
            'pt', 'qt', 'gal', 'oz', 'lb',
        ];

        if (
            ! in_array($unit, $nonPluralShorthandUnits, true)
            && preg_match('/^[a-z]+$/i', $unit)
            && is_numeric($value)
            && (float) $value !== 1.0
        ) {
            return Str::plural($unit);
        }

        return $unit;
    }

    public function formatMeasurement($value, ?string $unit = null): ?string
    {
        $formattedValue = $this->formatMeasurementValue($value);

        if ($formattedValue === null) {
            return null;
        }

        $formattedUnit = $this->displayUnitLabel($unit, $value);

        if ($formattedUnit === null) {
            return $formattedValue;
        }

        $separator = in_array($unit, ['mg', 'g', 'kg', 'ml', 'cl', 'l'], true) ? '' : ' ';

        return $formattedValue.$separator.$formattedUnit;
    }

    public function render()
    {
        $ingredients = Ingredient::query()
            ->where('user_id', auth()->id())
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('barcode', 'like', '%'.$this->search.'%')
            )
            )
            ->latest()
            ->paginate(12);

        return view('livewire.ingredients.index', compact('ingredients'));
    }
}
