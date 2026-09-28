@props(['status'])

@if ($status)
    <div role="status" aria-live="polite" aria-atomic="true" {{ $attributes->merge(['class' => 'text-sm font-medium text-green-600 dark:text-green-400']) }}>
        {{ $status }}
    </div>
@endif
