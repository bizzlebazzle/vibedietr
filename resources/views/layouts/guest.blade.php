<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="{
        themeOverride: localStorage.getItem('theme'),
        dark: (() => {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark') return true;
            if (saved === 'light') return false;
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        })(),
        init() {
            const mq = window.matchMedia('(prefers-color-scheme: dark)');
            const handler = e => {
                if (this.themeOverride === null) this.dark = e.matches;
            };
            if (mq.addEventListener) mq.addEventListener('change', handler);
            else mq.addListener(handler);
        }
    }"
    x-init="init()"
    x-effect="document.documentElement.classList.toggle('dark', dark)"
    :class="{ 'dark': dark }"
>
    <head>
        <script>
            (function() {
                try {
                    const saved = localStorage.getItem('theme');
                    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    const isDark = saved === 'dark' || (saved === null && systemDark);
                    if (isDark) document.documentElement.classList.add('dark');
                } catch (_) {}
            })();
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="min-h-screen flex flex-col items-center bg-gray-100 px-4 pt-6 text-gray-900 sm:justify-center sm:pt-0 dark:bg-slate-950 dark:text-slate-100">
            <div>
                <a href="/" wire:navigate>
                    <x-application-logo class="h-20 w-20 fill-current text-gray-500 dark:text-slate-300" />
                </a>
            </div>

            <div class="mt-6 w-full overflow-hidden rounded-lg border border-gray-200 bg-white px-6 py-4 shadow-sm sm:max-w-md sm:rounded-lg dark:border-slate-800 dark:bg-slate-900/80">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
