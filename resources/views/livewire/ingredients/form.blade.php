<div
  x-data="{
    notice: null,
    keywordInput: '',
    categoryInput: '',
    notifyHandler: null,
    barcodeHandler: null,
    noticeTimer: null,
    scanLookupPending: false,

    init() {
      // lightweight notifier
      this.notifyHandler = e => {
        const { message } = e.detail || {};
        this.notice = message || 'Done';
        clearTimeout(this.noticeTimer);
        this.noticeTimer = setTimeout(() => this.notice = null, 2500);
      };
      window.addEventListener('notify', this.notifyHandler);

      // handle barcode scanned -> set field + call OFF
      this.barcodeHandler = e => {
        const code = e.detail?.text || '';
        if (!code || this.scanLookupPending) return;
        this.scanLookupPending = true;
        // Keep the field in sync, then fetch using the scanned code directly.
        $wire.set('barcode', code);
        Promise.resolve($wire.fetchFromOff(code)).finally(() => {
          this.scanLookupPending = false;
        });
      };
      window.addEventListener('barcode-scanned', this.barcodeHandler);
    },

    destroy() {
      window.removeEventListener('notify', this.notifyHandler);
      window.removeEventListener('barcode-scanned', this.barcodeHandler);
      clearTimeout(this.noticeTimer);
    },

    addKeyword() {
      const val = this.keywordInput.trim();
      const arr = [...@js($keywords ?? [])];
      if (!val || arr.includes(val)) return;
      arr.push(val);
      this.keywordInput = '';
      this.$wire.set('keywords', arr);
    },

    addCategory() {
      const val = this.categoryInput.trim();
      if (!val) return;
      const arr = [...@js($categories ?? [])];
      arr.push(val);
      this.categoryInput = '';
      this.$wire.set('categories', arr);
    },
  }"
  x-init="init()"
  class="space-y-6 text-gray-900 dark:text-gray-100"
>

  {{-- Alerts --}}
  <div x-show="notice" x-text="notice" class="p-2 rounded bg-green-100 text-green-800"></div>

  @if($errors->any())
    <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100">
      <div class="font-medium">Please fix the highlighted fields before saving.</div>
      <ul class="mt-2 list-disc pl-5">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form wire:submit="save" class="grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="md:col-span-2 space-y-4">
      <div class="space-y-1">
        <label class="block font-medium">Name</label>
        <input type="text" wire:model.defer="name" class="w-full rounded border px-3 py-2">
        @error('name') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
      </div>

      <div class="space-y-1">
        <label class="block font-medium">Barcode lookup</label>
        <p class="text-sm text-gray-600 dark:text-slate-300">
          A barcode is saved only after OpenFoodFacts confirms the product.
        </p>
        <div class="flex flex-col gap-2">
          <div class="flex gap-2">
            <input type="text" wire:model.defer="barcode" class="flex-1 rounded border px-3 py-2" placeholder="Scan or enter a barcode to look up">
            <button
              type="button"
              class="rounded bg-blue-600 text-white px-3 py-2 disabled:opacity-50"
              wire:click="fetchFromOff"
              wire:loading.attr="disabled"
              wire:target="fetchFromOff"
            >
              <span wire:loading.remove wire:target="fetchFromOff">Fetch from OFF</span>
              <span wire:loading wire:target="fetchFromOff">Fetching…</span>
            </button>
          </div>
          <details class="rounded border p-3">
            <summary class="cursor-pointer font-medium">Scan barcode</summary>
            <div class="mt-3">
                <x-barcode-scanner
                    facing="environment"
                    :autostart="false"
                    event-name="barcode-scanned"
                />
            </div>
        </details>
        </div>
        @error('barcode') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="block font-medium sm:flex sm:min-h-[3rem] sm:items-end">Quantity</label>
          <input type="number" step="0.001" min="0" wire:model.defer="quantity" class="w-full rounded border px-3 py-2">
          @error('quantity') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        </div>
        <div>
          <label class="block font-medium sm:flex sm:min-h-[3rem] sm:items-end">Quantity unit</label>
          <input type="text" list="measurement-unit-options" wire:model.defer="quantity_unit" class="w-full rounded border px-3 py-2" placeholder="e.g. g, tbsp or bunch">
          <datalist id="measurement-unit-options">
            @foreach($measurementUnitGroups as $group => $units)
              @foreach($units as $value => $label)
                <option value="{{ $value }}" label="{{ $group }} — {{ $label }}"></option>
              @endforeach
            @endforeach
            @foreach($customMeasurementUnits as $unit)
              <option value="{{ $unit }}" label="Current custom unit"></option>
            @endforeach
          </datalist>
          @error('quantity_unit') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        </div>
        <div>
          <label class="block font-medium sm:flex sm:min-h-[3rem] sm:items-end">Recommended servings (optional)</label>
          <input type="number" step="0.01" min="0" wire:model.defer="recommended_servings" class="w-full rounded border px-3 py-2">
          @error('recommended_servings') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block font-medium">Serving quantity (optional)</label>
          <input type="number" step="0.001" min="0" wire:model.defer="serving_quantity" class="w-full rounded border px-3 py-2">
          @error('serving_quantity') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        </div>
        <div>
          <label class="block font-medium">Serving quantity unit (optional)</label>
          <input type="text" list="measurement-unit-options" wire:model.defer="serving_quantity_unit" class="w-full rounded border px-3 py-2" placeholder="e.g. ml, piece or splash">
          @error('serving_quantity_unit') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        </div>
      </div>

      <div>
        <label class="block font-medium">Image URL (automatic from OpenFoodFacts when barcode scanning)</label>
        <input type="url" wire:model.defer="image_url" class="w-full rounded border px-3 py-2" placeholder="https://…">
        @error('image_url') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
      </div>

      <section class="space-y-3">
        <div>
          <h3 class="font-medium">Nutritional Information (optional)</h3>
          <p class="text-sm text-gray-600 dark:text-slate-300">
            Fill in any values you have manually, or fetch from OpenFoodFacts to populate them automatically.
          </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          @foreach($nutritionPanels as $panel)
            <section class="rounded border-2 border-gray-900 bg-white p-4 shadow-sm dark:border-slate-100 dark:bg-slate-950">
              <div class="border-b-4 border-gray-900 pb-2 text-sm font-bold uppercase tracking-wide dark:border-slate-100">
                {{ $panel['title'] }}
              </div>

              <div class="mt-2 divide-y divide-gray-300 dark:divide-slate-700">
                @foreach($panel['rows'] as $row)
                  <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="text-sm font-semibold text-gray-800 dark:text-slate-100">
                      {{ $row['label'] }}
                    </div>

                    @if(isset($row['inputs']))
                      <div class="flex justify-end gap-2">
                        @foreach($row['inputs'] as $input)
                          <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                              {{ $input['label'] }}
                            </label>
                            <input
                              type="number"
                              step="{{ $input['step'] }}"
                              min="0"
                              wire:model.defer="{{ $input['model'] }}"
                              class="w-24 rounded border px-2 py-2 text-right text-sm"
                              placeholder="0"
                            >
                            @error($input['model']) <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                          </div>
                        @endforeach
                      </div>
                    @else
                      <div>
                        <div class="flex items-center justify-end gap-2">
                          <input
                            type="number"
                            step="{{ $row['step'] }}"
                            min="0"
                            wire:model.defer="{{ $row['model'] }}"
                            class="w-24 rounded border px-2 py-2 text-right text-sm"
                            placeholder="0.0"
                          >
                          <span class="w-6 text-xs font-medium text-gray-500 dark:text-slate-400">{{ $row['unit'] }}</span>
                        </div>
                        @error($row['model']) <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                      </div>
                    @endif
                  </div>
                @endforeach
              </div>
            </section>
          @endforeach
        </div>

        <div>
        <label class="block font-medium">Keywords (optional)</label>
        <input type="text"
               x-model="keywordInput"
               @keydown.enter.prevent="addKeyword"
               placeholder="Type a keyword and press Enter"
               class="w-full rounded border px-3 py-2">
        <div class="mt-2 flex flex-wrap gap-2">
          @foreach(($keywords ?? []) as $i => $kw)
            <span wire:key="kw-{{ $i }}-{{ md5($kw) }}" class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-slate-700 dark:text-slate-100">
              {{ $kw }}
              <button
                type="button"
                class="text-gray-500 dark:text-slate-300"
                aria-label="Remove keyword {{ $kw }}"
                wire:click="$set('keywords', {{ collect($keywords)->except($i)->values()->toJson() }})"
              >&times;</button>
            </span>
          @endforeach
        </div>
      </div>

      <div>
        <label class="block font-medium">Categories (optional)</label>
        <input type="text"
               x-model="categoryInput"
               @keydown.enter.prevent="addCategory"
               placeholder="Type a category tag and press Enter"
               class="w-full rounded border px-3 py-2">
        <div class="mt-2 flex flex-wrap gap-2">
          @foreach(($categories ?? []) as $i => $cat)
            <span wire:key="cat-{{ $i }}-{{ md5($cat) }}" class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-slate-700 dark:text-slate-100">
              {{ $cat }}
              <button
                type="button"
                class="text-gray-500 dark:text-slate-300"
                aria-label="Remove category {{ $cat }}"
                wire:click="$set('categories', {{ collect($categories)->except($i)->values()->toJson() }})"
              >&times;</button>
            </span>
          @endforeach
        </div>
      </div>
      </section>

      <div class="flex gap-3">
        <x-primary-button wire:loading.attr="disabled" wire:target="save" type="submit">Save</x-primary-button>
        <x-secondary-button wire:click="$dispatch('close-modal')">Cancel</x-secondary-button>
      </div>
    </div>

    <div class="space-y-3">
      <div class="rounded border border-gray-200 p-3 text-gray-900 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-100">
        <div class="font-medium mb-2">Preview</div>
        @if($image_url)
          <img src="{{ $image_url }}" alt="{{ $name ? $name.' image' : 'Product image' }}" class="w-full rounded">
        @else
          <div class="text-sm text-gray-600 dark:text-slate-300">No image</div>
        @endif
        <div class="mt-2 text-sm">
          <div><span class="font-medium">Name:</span> {{ $name }}</div>
          <div><span class="font-medium">Barcode:</span> {{ $barcode }}</div>
          <div><span class="font-medium">Qty:</span> {{ $quantity }} {{ $quantity_unit }}</div>
        </div>
      </div>
    </div>
  </form>
</div>
