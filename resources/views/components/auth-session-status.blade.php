@props(['status'])

@if ($status)
    <div role="status" aria-live="polite" aria-atomic="true" {{ $attributes->merge(['class' => 'text-sm font-medium text-green-800 dark:text-green-300']) }}>
        {{ $status }}
    </div>
@endif
