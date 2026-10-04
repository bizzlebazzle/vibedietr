@props(['messages'])

@if ($messages)
    <ul data-feedback-error {{ $attributes->merge(['class' => 'text-sm text-red-700 dark:text-red-300 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
