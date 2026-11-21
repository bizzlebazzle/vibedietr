<p id="ingredient-barcode-help-{{ $lineId }}" class="text-xs text-gray-600 dark:text-gray-400">Scanning checks the shared catalogue first. A successful lookup may add a product to the shared catalogue. Select the result to match this ingredient.</p>
<p x-show="pending" x-cloak role="status">Looking up barcode…</p>
<p x-show="failure" x-text="failure" x-cloak role="alert" class="text-sm"></p>
<div id="ingredient-barcode-error-{{ $lineId }}"><x-input-error :messages="$errors->get('barcodeLookups.'.$lineId)" /></div>
@if (isset($barcodeNotices[$lineId]))
    <p role="status" class="text-sm">{{ $barcodeNotices[$lineId] }}</p>
@endif
@if ($errors->has('barcodeLookups.'.$lineId))
    <p class="text-sm">Scan again, search by name, or <a href="{{ route('catalogue.manual.create') }}" class="underline">submit a missing food manually</a>. Save draft changes before leaving.</p>
@endif
<div id="ingredient-scanner-{{ $lineId }}" x-ref="scanner">
    <template x-if="scanning">
        <div class="space-y-3">
            <button type="button" x-on:click="closeScanner(); $refs.scanButton.focus()" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">Close scanner</button>
            <x-barcode-scanner :event="'recipe-barcode-scanned-'.$lineId" :camera-label="'Choose camera for ingredient '.($index + 1)" />
        </div>
    </template>
</div>
