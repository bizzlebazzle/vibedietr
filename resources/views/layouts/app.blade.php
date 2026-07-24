<!DOCTYPE html>
<html
  lang="{{ str_replace('_', '-', app()->getLocale()) }}"
  x-data="{
    themeOverride: localStorage.getItem('theme'), // 'light', 'dark', or null
    dark: (() => {
      const saved = localStorage.getItem('theme');
      if (saved === 'dark') return true;
      if (saved === 'light') return false;
      return window.matchMedia('(prefers-color-scheme: dark)').matches;
    })(),
    init() {
      const mq = window.matchMedia('(prefers-color-scheme: dark)');
      const handler = e => {
        if (this.themeOverride === null) {
          this.dark = e.matches;
        }
      };
      if (mq.addEventListener) mq.addEventListener('change', handler);
      else mq.addListener(handler);
    },
    setTheme(mode) {
      if (mode === 'light') {
        this.dark = false;
        this.themeOverride = 'light';
        localStorage.setItem('theme', 'light');
      } else if (mode === 'dark') {
        this.dark = true;
        this.themeOverride = 'dark';
        localStorage.setItem('theme', 'dark');
      } else { // system default
        localStorage.removeItem('theme');
        this.themeOverride = null;
        this.dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
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
        @livewireStyles

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="min-h-screen bg-gray-100 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="border-b border-gray-200 bg-white/95 shadow-sm dark:border-slate-800 dark:bg-slate-900/95">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="text-gray-900 dark:text-slate-100">
                {{ $slot }}
            </main>
        </div>
        @livewireScripts
        @stack('scripts')
    </body>
</html>
