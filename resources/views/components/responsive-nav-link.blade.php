@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full border-l-4 border-sky-500 bg-sky-50 py-2 ps-3 pe-4 text-start text-base font-medium text-sky-700 transition duration-150 ease-in-out focus:border-sky-600 focus:bg-sky-100 focus:text-sky-800 focus:outline-none dark:border-sky-400 dark:bg-slate-800 dark:text-sky-300 dark:focus:border-sky-300 dark:focus:bg-slate-700 dark:focus:text-sky-200'
            : 'block w-full border-l-4 border-transparent py-2 ps-3 pe-4 text-start text-base font-medium text-gray-600 transition duration-150 ease-in-out hover:border-gray-300 hover:bg-gray-50 hover:text-gray-800 focus:border-gray-300 focus:bg-gray-50 focus:text-gray-800 focus:outline-none dark:text-slate-400 dark:hover:border-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:focus:border-slate-600 dark:focus:bg-slate-800 dark:focus:text-slate-100';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
