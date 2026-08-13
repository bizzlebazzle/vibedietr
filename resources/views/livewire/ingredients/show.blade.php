<div class="space-y-6 text-gray-900 dark:text-slate-100">
  <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
    <div class="w-full sm:w-48">
      @if($ingredient->image_url)
        <img src="{{ $ingredient->image_url }}" alt="{{ $ingredient->name }}" class="aspect-square w-full rounded-lg border border-gray-200 object-cover dark:border-slate-700">
      @else
        <div class="flex aspect-square items-center justify-center rounded-lg border border-dashed border-gray-300 text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">
          No image
        </div>
      @endif
    </div>

    <div class="min-w-0 flex-1 space-y-4">
      <div class="space-y-2">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h2 class="text-xl font-semibold">{{ $ingredient->name }}</h2>
            @if($ingredient->barcode)
              <p class="text-sm text-gray-600 dark:text-slate-400">Barcode: {{ $ingredient->barcode }}</p>
            @endif
          </div>

          <div class="flex flex-wrap gap-2">
            @if($withinModal)
              <a href="{{ route('ingredients.show', $ingredient) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700">
                Open page
              </a>
            @endif

            @can('update', $ingredient)
              <a href="{{ route('ingredients.edit', $ingredient) }}" class="inline-flex items-center rounded-md border border-transparent bg-sky-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-sky-500">
                Edit
              </a>
            @endcan

            @can('delete', $ingredient)
              <form method="POST" action="{{ route('ingredients.destroy', $ingredient) }}"
                    onsubmit="return confirm('Delete this ingredient?')">
                @csrf
                @method('DELETE')
                <x-danger-button>Delete</x-danger-button>
              </form>
            @endcan
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg border border-gray-200 p-3 dark:border-slate-700">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
              Quantity
              @if($ingredient->quantity_unit)
                ({{ $this->displayUnitLabel($ingredient->quantity_unit) }})
              @endif
            </div>
            <div class="mt-1 text-sm font-medium">{{ $this->formatMeasurement($ingredient->quantity, $ingredient->quantity_unit) ?? 'Not set' }}</div>
          </div>

          <div class="rounded-lg border border-gray-200 p-3 dark:border-slate-700">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Recommended serving</div>
            <div class="mt-1 text-sm font-medium">
              @if($ingredient->serving_quantity)
                {{ $this->formatMeasurement($ingredient->serving_quantity, $ingredient->serving_quantity_unit) }}
              @else
                Not set
              @endif
            </div>
          </div>

          <div class="rounded-lg border border-gray-200 p-3 dark:border-slate-700">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Recommended servings</div>
            <div class="mt-1 text-sm font-medium">{{ $this->formatMeasurementValue($ingredient->recommended_servings) ?? 'Not set' }}</div>
          </div>
        </div>
      </div>

      <section class="space-y-3">
        <div>
          <h3 class="font-medium">Nutritional Information</h3>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          @foreach($nutritionPanels as $panel)
            <section class="rounded border-2 border-gray-900 bg-white p-4 shadow-sm dark:border-slate-100 dark:bg-slate-950">
              <div class="border-b-4 border-gray-900 pb-2 text-sm font-bold uppercase tracking-wide dark:border-slate-100">
                {{ $panel['title'] }}
              </div>

              <div class="mt-2 divide-y divide-gray-300 dark:divide-slate-700">
                @foreach($nutritionRows as $row)
                  <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 py-3 items-start">
                    <div class="text-sm font-semibold text-gray-800 dark:text-slate-100">
                      {{ $row['label'] }}
                    </div>

                    <div class="text-right text-sm text-gray-700 dark:text-slate-200">
                      {{ $this->formatNutritionRow($panel['bucket'], $row['nutrients']) }}
                    </div>
                  </div>
                @endforeach
              </div>
            </section>
          @endforeach
        </div>
      </section>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
          <h3 class="font-medium">Keywords</h3>
          <div class="mt-3 flex flex-wrap gap-2">
            @forelse($ingredient->keywords ?? [] as $keyword)
              <span class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-slate-700 dark:text-slate-100">{{ $keyword }}</span>
            @empty
              <span class="text-sm text-gray-500 dark:text-slate-400">No keywords</span>
            @endforelse
          </div>
        </section>

        <section class="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
          <h3 class="font-medium">Categories</h3>
          <div class="mt-3 flex flex-wrap gap-2">
            @forelse($ingredient->categories ?? [] as $category)
              <span class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-slate-700 dark:text-slate-100">{{ $category }}</span>
            @empty
              <span class="text-sm text-gray-500 dark:text-slate-400">No categories</span>
            @endforelse
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
