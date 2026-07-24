<div class="space-y-4">
  <div class="flex flex-col sm:flex-row gap-3 items-stretch">
    <input
      type="search"
      wire:model.debounce.300ms="search"
      placeholder="Search by name or barcode…"
      class="flex-1 min-w-0 rounded border px-3 py-2"
    />
    <x-secondary-button wire:click="openFormModal" class="shrink-0">
      + Add ingredient
    </x-secondary-button>
  </div>

  <div wire:on:close-modal="closeModal">
    @if($showFormModal)
      <x-modal
        name="ingredient-form"
        :show="$showFormModal"
        max-width="6xl"
        :title="$editingIngredientId ? 'Edit Ingredient' : 'Add Ingredient'"
      >
          @if($editingIngredientId)
              @livewire('ingredients.form', ['ingredient' => \App\Models\Ingredient::find($editingIngredientId)], key('ingredient-form-'.$editingIngredientId))
          @else
              @livewire('ingredients.form', key('ingredient-form-create'))
          @endif
      </x-modal>
    @endif

    @if($showDetailsModal && $viewingIngredientId)
      <x-modal
        name="ingredient-details"
        :show="$showDetailsModal"
        max-width="6xl"
        title="Ingredient details"
      >
          @livewire('ingredients.show', ['ingredient' => \App\Models\Ingredient::find($viewingIngredientId), 'withinModal' => true], key('ingredient-show-'.$viewingIngredientId))
      </x-modal>
    @endif
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($ingredients as $ingredient)
      <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900/80">
        <button type="button" wire:click="openShowModal({{ $ingredient->id }})" class="flex min-w-0 flex-1 gap-3 text-left">
          @if($ingredient->image_url)
            <img src="{{ $ingredient->image_url }}" alt="" class="h-16 w-16 rounded object-cover">
          @endif
          <div class="min-w-0 flex-1">
            <div class="font-medium text-gray-900 dark:text-slate-100">{{ $ingredient->name }}</div>
            @if($ingredient->barcode)
              <div class="text-sm text-gray-600 dark:text-slate-400">Barcode: {{ $ingredient->barcode }}</div>
            @endif
            <div class="mt-1 text-sm text-gray-600 dark:text-slate-400">{{ $this->formatMeasurement($ingredient->quantity, $ingredient->quantity_unit) }}</div>
          </div>
        </button>
      </div>
    @empty
      <div class="col-span-full text-gray-600 dark:text-slate-400">No ingredients found.</div>
    @endforelse
  </div>

  <div>
    {{ $ingredients->links() }}
  </div>
</div>
