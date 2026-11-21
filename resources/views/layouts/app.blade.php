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
        <script nonce="{{ Vite::cspNonce() }}">
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
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="description" content="VibeDietr recipes, food catalogue and meal planning.">
        <x-document-title :header="$header ?? null" :title="$title ?? null" />

        @livewireStyles(['nonce' => Vite::cspNonce()])

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
        <x-skip-link />
        <div class="min-h-screen bg-gray-100 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header>
                    <x-secondary-bar>
                        {{ $header }}
                    </x-secondary-bar>
                </header>
            @endif

            <!-- Page Content -->
            <main id="main-content" tabindex="-1" class="text-gray-900 dark:text-slate-100">
                {{ $slot }}
            </main>
            <x-service-information-link />
        </div>
        @livewireScripts(['nonce' => Vite::cspNonce()])
        @stack('scripts')
    </body>
</html>
