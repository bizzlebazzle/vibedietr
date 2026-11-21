<div x-data="recipeBarcodeLookup({ lineId: {{ $lineId }} })" data-recipe-barcode="{{ $lineId }}" x-bind:aria-busy="pending" class="space-y-3 rounded border border-gray-300 p-3 dark:border-slate-600">
    <p class="text-sm">Look up a barcode or scan the product. A successful lookup may add a product to the shared catalogue. Select the result to match this ingredient.</p>
    <label for="ingredient-barcode-{{ $lineId }}" class="block text-sm font-medium">Barcode for ingredient {{ $index + 1 }}</label>
    <input id="ingredient-barcode-{{ $lineId }}" x-model="code" x-ref="barcodeInput" type="text" maxlength="64" inputmode="numeric" aria-describedby="ingredient-barcode-error-{{ $lineId }}" @if ($errors->has('barcodeLookups.'.$lineId)) aria-invalid="true" @endif x-on:keydown.enter.prevent="lookup()" class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-900" />
    <div class="flex flex-wrap gap-2">
        <button type="button" x-on:click="lookup()" aria-label="Look up barcode for ingredient {{ $index + 1 }}" x-bind:disabled="pending" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-600">Look up barcode</button>
        <button type="button" x-on:click="openScanner()" aria-label="Scan barcode for ingredient {{ $index + 1 }}" x-bind:disabled="pending" x-bind:aria-expanded="scanning" aria-controls="ingredient-scanner-{{ $lineId }}" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-600">Scan barcode</button>
    </div>
    <p x-show="pending" x-cloak role="status">Looking up barcode…</p>
    <p x-show="failure" x-text="failure" x-cloak role="alert" class="text-sm"></p>
    <div id="ingredient-barcode-error-{{ $lineId }}"><x-input-error :messages="$errors->get('barcodeLookups.'.$lineId)" /></div>
    @if (isset($barcodeNotices[$lineId]))
        <p role="status" class="text-sm">{{ $barcodeNotices[$lineId] }}</p>
    @endif
    @if ($errors->has('barcodeLookups.'.$lineId))
        <p class="text-sm">Check the code and try again, search by name, or <a href="{{ route('catalogue.manual.create') }}" class="underline">submit a missing food manually</a>. Save draft changes before leaving.</p>
    @endif
    <div id="ingredient-scanner-{{ $lineId }}" x-ref="scanner">
        <template x-if="scanning">
            <div class="space-y-3">
                <button type="button" x-on:click="closeScanner(); $el.closest('[data-recipe-barcode]').querySelector('[aria-controls]').focus()" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">Close scanner</button>
                <x-barcode-scanner :event="'recipe-barcode-scanned-'.$lineId" :camera-label="'Choose camera for ingredient '.($index + 1)" />
            </div>
        </template>
    </div>
</div>
