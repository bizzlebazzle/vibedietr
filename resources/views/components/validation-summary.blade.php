@props(['errors', 'message' => 'Please correct the following errors. Your entries have been kept.'])

@if ($errors->any())
    <div data-validation-summary tabindex="-1" role="alert" aria-atomic="true"
         {{ $attributes->merge(['class' => 'rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100']) }}>
        <p class="font-semibold">Validation errors</p>
        <p>{{ $message }}</p>
        <ul class="mt-2 list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
