@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center border-b-2 border-sky-500 px-1 pt-1 text-sm font-medium leading-5 text-gray-900 transition duration-150 ease-in-out focus:border-sky-600 focus:outline-none dark:border-sky-400 dark:text-slate-100 dark:focus:border-sky-300'
            : 'inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-gray-500 transition duration-150 ease-in-out hover:border-gray-300 hover:text-gray-700 focus:border-gray-300 focus:outline-none focus:text-gray-700 dark:text-slate-400 dark:hover:border-slate-600 dark:hover:text-slate-200 dark:focus:border-slate-600 dark:focus:text-slate-200';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
