<div {{ $attributes->class(['secondary-bar border-b border-gray-200 bg-white text-gray-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100']) }}>
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-24 items-center sm:min-h-12">
            {{ $slot }}
        </div>
    </div>
</div>
