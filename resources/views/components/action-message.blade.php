@props(['on'])

<div role="status" aria-live="polite" aria-atomic="true" x-data="{ shown: false, timeout: null }"
     x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2000); })"
     x-show="shown"
     style="display: none;"
    {{ $attributes->merge(['class' => 'text-sm text-gray-600 dark:text-slate-400']) }}>
    {{ $slot->isEmpty() ? __('Saved.') : $slot }}
</div>
